<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Clients\ClientImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ClienteImportController extends Controller
{
    public function plantilla(ClientImportService $importService): StreamedResponse
    {
        return response()->streamDownload(function () use ($importService) {
            $temp = tempnam(sys_get_temp_dir(), 'clientes-template-');
            if ($temp === false) {
                throw new \RuntimeException('No se pudo crear archivo temporal.');
            }

            $path = $temp.'.xlsx';
            rename($temp, $path);

            try {
                $importService->writeTemplateTo($path);
                readfile($path);
            } finally {
                @unlink($path);
            }
        }, 'plantilla-clientes.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function import(Request $request, ClientImportService $importService): JsonResponse
    {
        $validated = $request->validate([
            'archivo' => ['required', 'file', 'mimes:xlsx,xls', 'max:5120'],
        ]);

        $file = $validated['archivo'];
        $path = $file->getRealPath() ?: $file->path();

        $result = $importService->importFromPath($path);

        return response()->json($result);
    }
}
