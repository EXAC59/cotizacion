<?php

namespace App\Services\WhatsApp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class WhatsAppCloudService
{
    public function configured(): bool
    {
        return filled(config('whatsapp.access_token'))
            && filled(config('whatsapp.phone_number_id'))
            && filled(config('whatsapp.waba_id'));
    }

    private function client(): PendingRequest
    {
        if (! $this->configured()) {
            throw ValidationException::withMessages(['whatsapp' => 'Falta conectar la cuenta emisora de WhatsApp Business.']);
        }

        return Http::baseUrl(rtrim(config('whatsapp.base_url'), '/').'/'.config('whatsapp.api_version'))
            ->withToken(config('whatsapp.access_token'))->acceptJson()->connectTimeout(10)->timeout(30);
    }

    private function result(Response $response): array
    {
        if (! $response->successful()) {
            // No incluir el cuerpo de Meta: puede contener información sensible.
            $code = $response->json('error.code');
            if ($code === 100 && str_contains((string) $response->json('error.message'), 'nonexisting field (message_templates)')) {
                throw ValidationException::withMessages(['whatsapp' => 'El identificador configurado no corresponde a una cuenta de WhatsApp Business. Corrige la cuenta emisora en Meta.']);
            }
            throw ValidationException::withMessages([
                'whatsapp' => 'Meta rechazó la operación de WhatsApp'.($code ? " (código {$code})" : '').'. Revisa la cuenta, sus permisos y la plantilla.',
            ]);
        }

        return $response->json() ?? [];
    }

    public function templates(): array
    {
        $templates = [];
        $after = null;
        do {
            $data = $this->result($this->client()->get(config('whatsapp.waba_id').'/message_templates', array_filter([
                'fields' => 'id,name,status,language,components', 'limit' => 100, 'after' => $after,
            ])));
            $templates = array_merge($templates, $data['data'] ?? []);
            $after = isset($data['paging']['next']) ? ($data['paging']['cursors']['after'] ?? null) : null;
        } while ($after !== null);

        return $templates;
    }

    public function catalog(bool $consultMeta = true): array
    {
        $remote = $consultMeta && $this->configured() ? $this->templates() : [];
        $language = config('whatsapp.language');
        $items = [];
        foreach (config('whatsapp.templates') as $name => $definition) {
            $template = collect($remote)->first(fn ($row) => $row['name'] === $name && $row['language'] === $language);
            $components = $template['components'] ?? [];
            $header = collect($components)->firstWhere('type', 'HEADER');
            $body = collect($components)->firstWhere('type', 'BODY');
            // Las tres variables se llenan en orden: cliente, folio y empresa.
            $compatible = ($header['format'] ?? null) === 'DOCUMENT'
                && ($body['text'] ?? null) === $definition['body']
                && ! collect($components)->contains(fn ($row) => ($row['type'] ?? '') === 'BUTTONS');
            $items[] = [
                'name' => $name, 'label' => $definition['label'], 'body' => $definition['body'],
                'language' => $language, 'status' => $template['status'] ?? ($consultMeta ? 'NOT_CREATED' : 'UNKNOWN'),
                'id' => $template['id'] ?? null, 'compatible' => $compatible,
                'canSend' => $compatible && ($template['status'] ?? '') === 'APPROVED',
            ];
        }

        return $items;
    }

    public function createTemplate(string $name, string $samplePdf): array
    {
        $definition = config('whatsapp.templates.'.$name);
        if (! $definition || ! filled(config('whatsapp.app_id'))) {
            throw ValidationException::withMessages(['whatsapp' => 'Falta el ID de la aplicación de Meta para registrar plantillas con PDF.']);
        }
        $existing = collect($this->catalog())->firstWhere('name', $name);
        if (($existing['status'] ?? '') !== 'NOT_CREATED') {
            throw ValidationException::withMessages(['whatsapp' => 'La plantilla ya existe en Meta. Actualiza su estado para consultar la aprobación.']);
        }
        // Meta requiere un handle de Resumable Upload como ejemplo del encabezado.
        $session = $this->result($this->client()->post(config('whatsapp.app_id').'/uploads', [
            'file_name' => 'cotizacion-ejemplo.pdf', 'file_length' => strlen($samplePdf), 'file_type' => 'application/pdf',
        ]));
        if (empty($session['id'])) {
            throw ValidationException::withMessages(['whatsapp' => 'Meta no devolvió la sesión para subir el PDF de ejemplo.']);
        }
        $uploaded = $this->result($this->client()
            ->withHeaders(['Authorization' => 'OAuth '.config('whatsapp.access_token'), 'file_offset' => '0'])
            ->withBody($samplePdf, 'application/pdf')->post($session['id']));
        if (empty($uploaded['h'])) {
            throw ValidationException::withMessages(['whatsapp' => 'Meta no devolvió el identificador del PDF de ejemplo.']);
        }

        return $this->result($this->client()->post(config('whatsapp.waba_id').'/message_templates', [
            'name' => $name, 'language' => config('whatsapp.language'), 'category' => 'UTILITY',
            'components' => [
                ['type' => 'HEADER', 'format' => 'DOCUMENT', 'example' => ['header_handle' => [$uploaded['h']]]],
                ['type' => 'BODY', 'text' => $definition['body'], 'example' => ['body_text' => [['Cliente de ejemplo', 'COT-0001', 'Exacto']]]],
            ],
        ]));
    }

    public function normalizePhone(string $phone): string
    {
        if (! preg_match('/^\+?[0-9\s().-]+$/', trim($phone))) {
            throw ValidationException::withMessages(['to' => 'Indica un WhatsApp válido con código de país.']);
        }
        $digits = preg_replace('/\D/', '', $phone);
        if (strlen($digits) === 10 && ! str_starts_with(trim($phone), '+')) {
            $digits = '52'.$digits;
        } elseif (strlen($digits) === 13 && str_starts_with($digits, '521')) {
            $digits = '52'.substr($digits, 3);
        }
        if (! preg_match('/^[1-9][0-9]{10,14}$/', $digits)) {
            throw ValidationException::withMessages(['to' => 'Indica un WhatsApp válido con código de país (10 dígitos para México).']);
        }

        return $digits;
    }

    public function sendPdf(string $to, string $name, array $parameters, string $pdf, string $filename): string
    {
        $template = collect($this->catalog())->firstWhere('name', $name);
        if (! ($template['canSend'] ?? false)) {
            throw ValidationException::withMessages(['template' => 'La plantilla debe estar aprobada en Meta y ser compatible con el PDF.']);
        }
        $media = $this->result($this->client()->attach('file', $pdf, $filename, ['Content-Type' => 'application/pdf'])
            ->post(config('whatsapp.phone_number_id').'/media', ['messaging_product' => 'whatsapp', 'type' => 'application/pdf']));
        if (empty($media['id'])) {
            throw ValidationException::withMessages(['whatsapp' => 'Meta no devolvió el identificador del PDF.']);
        }
        // No reintentar POST /messages: un timeout no confirma que el envío falló.
        try {
            $message = $this->result($this->client()->post(config('whatsapp.phone_number_id').'/messages', [
                'messaging_product' => 'whatsapp', 'to' => $this->normalizePhone($to), 'type' => 'template',
                'template' => [
                    'name' => $name, 'language' => ['code' => config('whatsapp.language')],
                    'components' => [
                        ['type' => 'header', 'parameters' => [['type' => 'document', 'document' => ['id' => $media['id'], 'filename' => $filename]]]],
                        ['type' => 'body', 'parameters' => array_map(fn ($text) => ['type' => 'text', 'text' => $text], $parameters)],
                    ],
                ],
            ]));
        } catch (ConnectionException $e) {
            throw ValidationException::withMessages(['whatsapp' => 'No se pudo confirmar la respuesta de Meta. Revisa WhatsApp antes de repetir el envío para evitar duplicados.']);
        }
        if (empty($message['messages'][0]['id'])) {
            throw ValidationException::withMessages(['whatsapp' => 'Meta no confirmó el envío. Revisa WhatsApp antes de repetirlo.']);
        }

        return $message['messages'][0]['id'];
    }
}
