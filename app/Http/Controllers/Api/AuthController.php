<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // Acepta usuario o correo (compatibilidad con clientes antiguos que mandan "email").
            'username' => ['nullable', 'string', 'max:190'],
            'email' => ['nullable', 'string', 'max:190'],
            'password' => ['required', 'string'],
        ]);

        $login = trim((string) ($validated['username'] ?? $validated['email'] ?? ''));
        if ($login === '') {
            throw ValidationException::withMessages([
                'username' => ['Indica tu usuario o correo.'],
            ]);
        }

        $loginLower = strtolower($login);

        $user = User::query()
            ->with('role')
            ->where(function ($query) use ($loginLower) {
                $query->whereRaw('LOWER(username) = ?', [$loginLower])
                    ->orWhereRaw('LOWER(email) = ?', [$loginLower]);
            })
            ->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'username' => ['Usuario/correo o contraseña incorrectos.'],
            ]);
        }

        if ($user->active === false) {
            return response()->json(['message' => 'Cuenta desactivada.'], 403);
        }

        Auth::login($user);

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        // SPA autentica por cookie de sesión (httpOnly). No emitir token bearer
        // persistente en el navegador (mitiga robo vía XSS + localStorage).
        $user->tokens()->where('name', 'spa')->delete();

        return response()->json([
            'user' => $user->toSessionArray(),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user) {
            $accessToken = $user->currentAccessToken();
            if ($accessToken instanceof PersonalAccessToken) {
                $accessToken->delete();
            }
            $user->tokens()->where('name', 'spa')->delete();
        }

        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => 'Sesión cerrada.']);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'No autenticado.'], 401);
        }

        $user->loadMissing('role');

        return response()->json([
            'user' => $user->toSessionArray(),
        ]);
    }
}
