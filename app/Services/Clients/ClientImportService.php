<?php

namespace App\Services\Clients;

use App\Models\Client;
use App\Services\LegacyXlsConverter;
use App\Support\MexicanRfc;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ClientImportService
{
    /** @var array<string, string> */
    private const HEADER_ALIASES = [
        'empresa' => 'company',
        'company' => 'company',
        'razon social' => 'company',
        'razón social' => 'company',
        'rfc' => 'rfc',
        'direccion' => 'address',
        'dirección' => 'address',
        'domicilio' => 'address',
        'address' => 'address',
        'contacto' => 'contact',
        'contact' => 'contact',
        'correo' => 'email',
        'email' => 'email',
        'e-mail' => 'email',
        'whatsapp' => 'whatsapp',
        'condiciones de pago' => 'paymentterms',
        'payment terms' => 'paymentterms',
        'paymentterms' => 'paymentterms',
        'pago' => 'paymentterms',
    ];

    public function __construct(
        private readonly LegacyXlsConverter $xlsConverter,
    ) {}

    /**
     * @return array{created: int, updated: int, skipped: int, errors: list<array{row: int, message: string}>}
     */
    public function importFromPath(string $absolutePath): array
    {
        $path = $absolutePath;
        $tempPath = null;

        if ($this->xlsConverter->isLegacyXls($path)) {
            $tempPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'client-import-'.uniqid('', true).'.xlsx';
            $this->xlsConverter->convert($path, $tempPath);
            $path = $tempPath;
        }

        try {
            $spreadsheet = IOFactory::load($path);
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray(null, true, true, false);

            return $this->importRows($rows);
        } finally {
            $this->xlsConverter->deleteTemp($tempPath);
        }
    }

    /**
     * @param  list<array<int, mixed>>  $rows
     * @return array{created: int, updated: int, skipped: int, errors: list<array{row: int, message: string}>}
     */
    public function importRows(array $rows): array
    {
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];

        if ($rows === []) {
            return compact('created', 'updated', 'skipped', 'errors');
        }

        $headerRow = array_shift($rows);
        $columnMap = $this->mapHeaders($headerRow ?? []);

        if (! in_array('company', $columnMap, true)) {
            return [
                'created' => 0,
                'updated' => 0,
                'skipped' => 0,
                'errors' => [['row' => 1, 'message' => 'Falta la columna Empresa en el encabezado.']],
            ];
        }

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2;
            $data = $this->extractRow($row, $columnMap);

            if ($this->rowIsEmpty($data)) {
                $skipped++;

                continue;
            }

            if ($data['company'] === '') {
                $errors[] = ['row' => $rowNumber, 'message' => 'Empresa es obligatoria.'];

                continue;
            }

            if ($data['rfc'] !== '' && ! MexicanRfc::isValid($data['rfc'])) {
                $errors[] = ['row' => $rowNumber, 'message' => 'RFC inválido.'];

                continue;
            }

            $normalizedRfc = Client::normalizeRfc($data['rfc']);

            try {
                if ($normalizedRfc !== null) {
                    $client = Client::query()->whereNormalizedRfc($normalizedRfc)->first();
                    if ($client !== null) {
                        $client->update($this->toModelAttributes($data));
                        $updated++;

                        continue;
                    }
                }

                Client::query()->create($this->toModelAttributes($data));
                $created++;
            } catch (\Throwable $e) {
                $errors[] = ['row' => $rowNumber, 'message' => $e->getMessage()];
            }
        }

        return compact('created', 'updated', 'skipped', 'errors');
    }

    public function buildTemplateSpreadsheet(): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Clientes');
        $sheet->fromArray([
            ['Empresa', 'RFC', 'Dirección', 'Contacto', 'Correo', 'WhatsApp', 'Condiciones de pago'],
        ]);
        $sheet->freezePane('A2');
        $sheet->setAutoFilter('A1:G1');
        $sheet->getStyle('A1:G1')->applyFromArray([
            'font' => [
                'bold' => true,
                'color' => ['ARGB' => 'FFFFFFFF'],
            ],
            'fill' => [
                'fillType' => 'solid',
                'startColor' => ['ARGB' => 'FF4F46E5'],
            ],
            'alignment' => [
                'vertical' => 'center',
            ],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(24);

        foreach (['A' => 34, 'B' => 18, 'C' => 40, 'D' => 26, 'E' => 32, 'F' => 18, 'G' => 24] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }

        $sheet->getComment('A1')->getText()->createTextRun('Obligatoria. Escribe una empresa por fila.');
        $sheet->getComment('B1')->getText()->createTextRun('Opcional. Si el RFC ya existe, se actualizará ese cliente.');

        return $spreadsheet;
    }

    public function writeTemplateTo(string $outputPath): void
    {
        $writer = new Xlsx($this->buildTemplateSpreadsheet());
        $writer->save($outputPath);
    }

    /**
     * @param  array<int, mixed>  $headerRow
     * @return array<int, string>
     */
    private function mapHeaders(array $headerRow): array
    {
        $map = [];

        foreach ($headerRow as $index => $cell) {
            $key = $this->normalizeHeaderKey((string) $cell);
            if ($key !== '' && isset(self::HEADER_ALIASES[$key])) {
                $map[$index] = self::HEADER_ALIASES[$key];
            }
        }

        return $map;
    }

    private function normalizeHeaderKey(string $value): string
    {
        $value = trim(mb_strtolower($value));

        return preg_replace('/\s+/', ' ', $value) ?? '';
    }

    /**
     * @param  array<int, mixed>  $row
     * @param  array<int, string>  $columnMap
     * @return array{company: string, rfc: string, address: string, contact: string, email: string, whatsapp: string, paymentTerms: string}
     */
    private function extractRow(array $row, array $columnMap): array
    {
        $data = [
            'company' => '',
            'rfc' => '',
            'address' => '',
            'contact' => '',
            'email' => '',
            'whatsapp' => '',
            'paymentTerms' => '',
        ];

        foreach ($columnMap as $index => $field) {
            $value = trim((string) ($row[$index] ?? ''));
            if ($field === 'paymentterms') {
                $data['paymentTerms'] = $value;
            } elseif (array_key_exists($field, $data)) {
                $data[$field] = $value;
            }
        }

        return $data;
    }

    /**
     * @param  array{company: string, rfc: string, address: string, contact: string, email: string, whatsapp: string, paymentTerms: string}  $data
     * @return array<string, string>
     */
    private function toModelAttributes(array $data): array
    {
        return [
            'company' => $data['company'],
            'rfc' => $data['rfc'],
            'address' => $data['address'],
            'contact_name' => $data['contact'],
            'email' => $data['email'],
            'whatsapp' => $data['whatsapp'],
            'payment_terms' => $data['paymentTerms'],
        ];
    }

    /**
     * @param  array{company: string, rfc: string, address: string, contact: string, email: string, whatsapp: string, paymentTerms: string}  $data
     */
    private function rowIsEmpty(array $data): bool
    {
        foreach ($data as $value) {
            if (trim($value) !== '') {
                return false;
            }
        }

        return true;
    }
}
