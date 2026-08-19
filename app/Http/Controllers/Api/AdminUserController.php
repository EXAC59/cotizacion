<?php

namespace App\Http\Controllers\Api;

use App\Models\Role;
use App\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AdminUserController extends Controller
{
    public function index(): JsonResponse
    {
        $users = User::query()
            ->with('role')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $users->map(fn (User $user) => $this->toApiArray($user))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request, null);

        $user = User::query()->create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'username' => $validated['username'],
            'password' => $validated['password'],
            'role_id' => $validated['role_id'],
            'active' => $validated['active'],
            'folio_code' => $validated['folio_code'],
            'uuid' => (string) Str::uuid(),
        ]);

        $this->applySignatureUpload($request, $user);

        return response()->json($this->toApiArray($user->fresh()->load('role')), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $user = $this->findByPublicId($id);
        $validated = $this->validatePayload($request, $user);

        $payload = [
            'name' => $validated['name'],
            'email' => $validated['email'],
            'username' => $validated['username'],
            'role_id' => $validated['role_id'],
            'active' => $validated['active'],
            'folio_code' => $validated['folio_code'],
        ];

        if (! empty($validated['password'])) {
            $payload['password'] = $validated['password'];
        }

        $user->update($payload);
        $this->applySignatureUpload($request, $user->fresh() ?? $user);

        return response()->json($this->toApiArray($user->fresh()->load('role')));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = $this->findByPublicId($id);
        $current = $request->user();

        if ($current instanceof User && (string) ($current->uuid ?: $current->getKey()) === (string) ($user->uuid ?: $user->getKey())) {
            return response()->json(['message' => 'No puedes eliminar tu propia cuenta.'], 422);
        }

        $this->deleteSignatureFile($user);
        $user->delete();

        return response()->json(['message' => 'Usuario eliminado.']);
    }

    public function signature(string $id): BinaryFileResponse|Response
    {
        $user = $this->findByPublicId($id);
        $path = $user->signatureAbsolutePath();
        if ($path === null) {
            abort(404, 'Firma no encontrada.');
        }

        return response()->file($path, [
            'Cache-Control' => 'private, no-store, no-cache, must-revalidate',
            'Content-Disposition' => 'inline',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, ?User $existing): array
    {
        $roles = config('rbac.roles', []);

        $rules = [
            'name' => [$existing ? 'sometimes' : 'required', 'string', 'max:120'],
            'username' => [
                $existing ? 'sometimes' : 'required',
                'string',
                'min:2',
                'max:64',
                'regex:/^[A-Za-z0-9._-]+$/',
                Rule::unique('users', 'username')->ignore($existing?->id),
            ],
            'email' => [
                'nullable',
                'email',
                'max:190',
                // Correo puede repetirse entre cuentas; el acceso es por usuario.
            ],
            'role' => [$existing ? 'sometimes' : 'required', 'string', Rule::in($roles)],
            'active' => ['sometimes', 'boolean'],
            'folioCode' => [
                $existing ? 'sometimes' : 'required',
                'string',
                'min:2',
                'max:12',
                'regex:/^[A-Za-z0-9]+$/',
                Rule::unique('users', 'folio_code')->ignore($existing?->id),
            ],
            'password' => [
                $existing ? 'nullable' : 'required',
                'string',
                'min:8',
                'max:120',
                'confirmed',
            ],
            'signature' => ['nullable', 'file', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'removeSignature' => ['sometimes', 'boolean'],
        ];

        // En edición, cadena vacía = no cambiar contraseña (evita fallar "confirmed").
        if ($existing && ! $request->filled('password')) {
            $request->merge(['password' => null, 'password_confirmation' => null]);
            $rules['password'] = ['nullable'];
        }

        // FormData envía booleans como "true"/"1"/"false".
        if ($request->has('removeSignature')) {
            $request->merge([
                'removeSignature' => filter_var($request->input('removeSignature'), FILTER_VALIDATE_BOOLEAN),
            ]);
        }
        if ($request->has('active') && ! is_bool($request->input('active'))) {
            $request->merge([
                'active' => filter_var($request->input('active'), FILTER_VALIDATE_BOOLEAN),
            ]);
        }

        $validated = $request->validate($rules);

        $name = $validated['name'] ?? $existing?->name ?? '';
        $username = User::normalizeUsername($validated['username'] ?? $existing?->username);
        if ($username === null) {
            throw ValidationException::withMessages([
                'username' => ['El usuario debe tener 2-64 caracteres (letras, números, . _ -).'],
            ]);
        }

        $emailRaw = $validated['email'] ?? $existing?->email ?? null;
        $email = is_string($emailRaw) && trim($emailRaw) !== ''
            ? strtolower(trim($emailRaw))
            : strtolower($username).'@users.local';

        $roleSlug = $validated['role'] ?? $existing?->role_slug ?? 'ventas';
        $folioCode = User::normalizeFolioCode($validated['folioCode'] ?? $existing?->folio_code)
            ?? User::deriveFolioCodeFromName($name);

        if ($folioCode === null) {
            throw ValidationException::withMessages([
                'folioCode' => ['Define un código de folio válido (2 a 12 caracteres alfanuméricos).'],
            ]);
        }

        $roleId = Role::query()->where('slug', $roleSlug)->value('id');
        if (! $roleId) {
            throw ValidationException::withMessages([
                'role' => ["Rol desconocido: {$roleSlug}"],
            ]);
        }

        return [
            'name' => $name,
            'email' => $email,
            'username' => $username,
            'password' => $validated['password'] ?? null,
            'role_id' => $roleId,
            'active' => array_key_exists('active', $validated)
                ? (bool) $validated['active']
                : ($existing?->active ?? true),
            'folio_code' => $folioCode,
        ];
    }

    private function applySignatureUpload(Request $request, User $user): void
    {
        $remove = filter_var($request->input('removeSignature'), FILTER_VALIDATE_BOOLEAN);
        if ($remove && ! $request->hasFile('signature')) {
            $this->deleteSignatureFile($user);
            $user->signature_path = null;
            $user->save();

            return;
        }

        if (! $request->hasFile('signature')) {
            return;
        }

        /** @var UploadedFile $file */
        $file = $request->file('signature');
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'png');
        if (! in_array($ext, ['png', 'jpg', 'jpeg', 'webp'], true)) {
            $ext = 'png';
        }

        $relative = 'signatures/'.$user->id.'.'.$ext;
        $this->deleteSignatureFile($user);

        // Disco local (no público vía /storage).
        Storage::disk('local')->putFileAs('signatures', $file, $user->id.'.'.$ext);

        $user->signature_path = $relative;
        $user->save();
    }

    private function deleteSignatureFile(User $user): void
    {
        $relative = trim((string) $user->signature_path);
        if ($relative === '') {
            return;
        }

        foreach (['local', 'public'] as $disk) {
            if (Storage::disk($disk)->exists($relative)) {
                Storage::disk($disk)->delete($relative);
            }
        }
    }

    private function findByPublicId(string $id): User
    {
        $user = User::query()
            ->with('role')
            ->where(function ($query) use ($id) {
                $query->where('uuid', $id);
                if (ctype_digit($id)) {
                    $query->orWhere('id', (int) $id);
                }
            })
            ->first();

        if (! $user) {
            abort(404, 'Usuario no encontrado.');
        }

        return $user;
    }

    /**
     * @return array{id: string, name: string, email: string, username: string|null, role: string|null, active: bool, folioCode: string|null, hasSignature: bool, signatureUrl: string|null, createdAt: string|null}
     */
    private function toApiArray(User $user): array
    {
        return [
            'id' => (string) ($user->uuid ?: $user->getKey()),
            'name' => $user->name,
            'email' => $user->email,
            'username' => $user->username,
            'role' => $user->role_slug,
            'active' => (bool) $user->active,
            'folioCode' => $user->resolveQuoteFolioCode(),
            'hasSignature' => $user->hasSignature(),
            'signatureUrl' => $user->signaturePublicUrl(),
            'createdAt' => $user->created_at?->toIso8601String(),
        ];
    }
}
