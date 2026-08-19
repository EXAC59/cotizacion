<?php

namespace App\Services;

use App\Exceptions\XlsConversionException;
use Illuminate\Support\Facades\Process;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class LegacyXlsConverter
{
    public function isLegacyXls(string $path): bool
    {
        return strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'xls';
    }

    /**
     * @return array{path: string, converted: bool, temp_path: ?string}
     */
    public function prepareForDocling(string $absolutePath): array
    {
        if (! $this->isLegacyXls($absolutePath)) {
            return [
                'path' => $absolutePath,
                'converted' => false,
                'temp_path' => null,
            ];
        }

        $tempPath = $this->buildTempXlsxPath();
        $this->convert($absolutePath, $tempPath);

        return [
            'path' => $tempPath,
            'converted' => true,
            'temp_path' => $tempPath,
        ];
    }

    public function deleteTemp(?string $tempPath): void
    {
        if ($tempPath !== null && is_file($tempPath)) {
            @unlink($tempPath);
        }
    }

    public function canConvertOnThisHost(): bool
    {
        return class_exists(IOFactory::class) || $this->canUseExcelCom();
    }

    /**
     * @throws XlsConversionException
     */
    public function convert(string $inputPath, string $outputPath): void
    {
        if (! is_file($inputPath)) {
            throw new XlsConversionException("Archivo .xls no encontrado: {$inputPath}");
        }

        $inputPath = realpath($inputPath) ?: $inputPath;

        $outputDir = dirname($outputPath);
        if (! is_dir($outputDir)) {
            mkdir($outputDir, 0777, true);
        }

        if ($this->convertWithPhpSpreadsheet($inputPath, $outputPath)) {
            return;
        }

        if ($this->convertWithExcelCom($inputPath, $outputPath)) {
            return;
        }

        throw new XlsConversionException(
            'No se pudo convertir .xls a .xlsx. Abre el archivo en Excel y guárdalo como .xlsx.',
        );
    }

    private function convertWithPhpSpreadsheet(string $inputPath, string $outputPath): bool
    {
        if (! class_exists(IOFactory::class)) {
            return false;
        }

        try {
            $spreadsheet = IOFactory::load($inputPath);
            $writer = new Xlsx($spreadsheet);
            $writer->save($outputPath);
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);

            return is_file($outputPath);
        } catch (\Throwable) {
            return false;
        }
    }

    private function convertWithExcelCom(string $inputPath, string $outputPath): bool
    {
        if (! $this->canUseExcelCom()) {
            return false;
        }

        $tempInput = $this->copyToTempXls($inputPath);

        try {
            $result = Process::timeout(120)->run([
                'powershell',
                '-NoProfile',
                '-ExecutionPolicy',
                'Bypass',
                '-File',
                $this->scriptPath(),
                '-InputPath',
                $tempInput,
                '-OutputPath',
                $outputPath,
            ]);

            return $result->successful() && is_file($outputPath);
        } catch (\Throwable) {
            return false;
        } finally {
            @unlink($tempInput);
        }
    }

    private function canUseExcelCom(): bool
    {
        return PHP_OS_FAMILY === 'Windows' && is_file($this->scriptPath());
    }

    private function copyToTempXls(string $inputPath): string
    {
        $tempInput = sys_get_temp_dir().DIRECTORY_SEPARATOR.'lectura-'.uniqid('', true).'.xls';

        if (! @copy($inputPath, $tempInput)) {
            throw new XlsConversionException("No se pudo preparar el archivo .xls para conversión: {$inputPath}");
        }

        return realpath($tempInput) ?: $tempInput;
    }

    private function buildTempXlsxPath(): string
    {
        return sys_get_temp_dir().DIRECTORY_SEPARATOR.'lectura-'.uniqid('xlsx', true).'.xlsx';
    }

    private function scriptPath(): string
    {
        return base_path('scripts/convert-xls-to-xlsx.ps1');
    }
}
