<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\WhatsApp\WhatsAppCloudService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\Client\ConnectionException;

class WhatsAppTemplateController extends Controller
{
    public function index(WhatsAppCloudService $whatsapp): JsonResponse
    {
        try {
            $catalog = $whatsapp->catalog();
        } catch (ValidationException | ConnectionException $exception) {
            return response()->json([
                'configured' => false, 'canCreate' => false,
                'connectionError' => $exception instanceof ValidationException
                    ? collect($exception->errors())->flatten()->first()
                    : 'No se pudo conectar con Meta. Intenta actualizar el estado más tarde.',
                'data' => $whatsapp->catalog(false),
            ]);
        }
        return response()->json([
            'configured' => $whatsapp->configured(),
            'canCreate' => $whatsapp->configured() && filled(config('whatsapp.app_id')),
            'data' => $catalog,
        ]);
    }

    public function store(Request $request, WhatsAppCloudService $whatsapp): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', Rule::in(array_keys(config('whatsapp.templates')))],
            'sample' => ['required', 'file', 'mimes:pdf', 'max:5120'],
        ]);
        $result = $whatsapp->createTemplate($validated['name'], file_get_contents($request->file('sample')->getRealPath()));

        return response()->json(['message' => 'Plantilla registrada en Meta para revisión.', 'data' => $result], 201);
    }
}
