<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int|null $role_id
 * @property string|null $uuid
 * @property string|null $folio_code
 * @property string|null $signature_path
 * @property string|null $username
 * @property-read Role|null $role
 * @property-read string|null $role_slug
 *
 * @method static \Illuminate\Database\Eloquent\Builder<User> query()
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'username',
        'password',
        'uuid',
        'role_id',
        'active',
        'folio_code',
        'signature_path',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'active' => 'boolean',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function getRoleSlugAttribute(): ?string
    {
        return $this->role?->slug;
    }

    public function isAdministrator(): bool
    {
        return $this->role_slug === 'administrador';
    }

    /**
     * @return array{id: string, name: string, email: string, username: string|null, role: string|null, active: bool, folioCode: string|null}
     */
    public function toSessionArray(): array
    {
        return [
            'id' => (string) ($this->uuid ?: $this->getKey()),
            'name' => $this->name,
            'email' => $this->email,
            'username' => $this->username,
            'role' => $this->role_slug,
            'active' => (bool) $this->active,
            'folioCode' => $this->resolveQuoteFolioCode(),
        ];
    }

    public static function normalizeUsername(?string $username): ?string
    {
        if ($username === null) {
            return null;
        }

        $normalized = strtolower(trim($username));
        if ($normalized === '' || ! preg_match('/^[a-z0-9._-]{2,64}$/', $normalized)) {
            return null;
        }

        return $normalized;
    }

    public function resolveQuoteFolioCode(): ?string
    {
        $explicit = self::normalizeFolioCode($this->folio_code);
        if ($explicit !== null) {
            return $explicit;
        }

        return self::deriveFolioCodeFromName($this->name);
    }

    /**
     * Ruta absoluta de la firma (disco local privado; fallback a public legado).
     */
    public function signatureAbsolutePath(): ?string
    {
        $relative = trim((string) $this->signature_path);
        if ($relative === '') {
            return null;
        }

        $relative = ltrim($relative, '/');

        foreach (['local', 'public'] as $disk) {
            if (Storage::disk($disk)->exists($relative)) {
                return Storage::disk($disk)->path($relative);
            }
        }

        return null;
    }

    public function hasSignature(): bool
    {
        return $this->signatureAbsolutePath() !== null;
    }

    /**
     * URL autenticada (admin) — no exponer /storage/signatures público.
     */
    public function signaturePublicUrl(): ?string
    {
        if (! $this->hasSignature()) {
            return null;
        }

        $id = (string) ($this->uuid ?: $this->getKey());

        return url('/api/admin/users/'.$id.'/signature');
    }

    public static function normalizeFolioCode(?string $code): ?string
    {
        if ($code === null) {
            return null;
        }

        $normalized = Str::upper((string) preg_replace('/[^A-Z0-9]+/i', '', $code));
        $length = strlen($normalized);

        if ($length < 2 || $length > 12) {
            return null;
        }

        return $normalized;
    }

    public static function deriveFolioCodeFromName(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        $firstWord = trim(Str::of($name)->squish()->explode(' ')->first() ?? '');
        $preferred = self::normalizeFolioCode(Str::upper(Str::ascii($firstWord)));
        if ($preferred !== null) {
            return $preferred;
        }

        $allText = Str::upper(Str::ascii($name));
        $clean = (string) preg_replace('/[^A-Z0-9]+/', '', $allText);
        if ($clean === '') {
            return null;
        }

        return self::normalizeFolioCode(substr($clean, 0, 12));
    }
}
