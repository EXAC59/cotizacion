<?php

namespace App\Exceptions;

use Exception;

class N8nWebhookException extends Exception
{
    /**
     * @param  array<string, mixed>|null  $body
     */
    public function __construct(
        public readonly int $httpStatus,
        public readonly ?array $body = null,
        string $message = 'Error al llamar webhook n8n',
    ) {
        parent::__construct($message);
    }

    public function userMessage(): string
    {
        $hint = is_array($this->body) ? ($this->body['hint'] ?? null) : null;
        $n8nMsg = is_array($this->body) ? ($this->body['message'] ?? null) : null;

        if ($this->httpStatus === 404) {
            return 'Webhook n8n no registrado. En http://localhost:5678 activa el workflow «Lectura cotizacion» (interruptor verde arriba a la derecha) y pulsa Save.';
        }

        if ($hint) {
            return (string) $hint;
        }

        if ($n8nMsg) {
            return (string) $n8nMsg;
        }

        return $this->getMessage();
    }
}
