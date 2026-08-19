<?php

namespace App\Models;

use App\Support\CompanyBranchesParser;
use Illuminate\Database\Eloquent\Model;

class AppSetting extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'id';

    protected $keyType = 'int';

    protected $table = 'app_settings';

    protected $fillable = [
        'default_margin_percent',
        'tax_percent',
        'quote_validity_days',
        'unanswered_quote_days',
        'min_stock_alert',
        'currency_code',
        'app_name',
        'app_tagline',
        'company_name',
        'company_legal_name',
        'company_tagline',
        'company_rfc',
        'company_address',
        'company_branches',
        'company_phone',
        'company_email',
        'company_website',
        'quote_terms',
        'quote_signature_name',
        'quote_signature_email',
        'quote_footer_address',
        'bank_accounts',
        'logo_path',
    ];

    protected function casts(): array
    {
        return [
            'default_margin_percent' => 'decimal:2',
            'tax_percent' => 'decimal:2',
            'quote_validity_days' => 'integer',
            'unanswered_quote_days' => 'integer',
            'min_stock_alert' => 'integer',
            'bank_accounts' => 'array',
            'updated_at' => 'datetime',
        ];
    }

    public static function current(): self
    {
        $settings = static::query()->find(1);

        if ($settings !== null) {
            return $settings;
        }

        return static::query()->create([
            'id' => 1,
            'default_margin_percent' => config('quote_pricing.default_margin_percent', 30),
            'tax_percent' => config('quote_pricing.default_tax_percent', 16),
            'quote_validity_days' => config('quote_pricing.default_validity_days', 15),
            'unanswered_quote_days' => (int) config('dashboard.unanswered_quote_days', 3),
            'min_stock_alert' => 5,
            'currency_code' => 'MXN',
        ]);
    }

    /**
     * @return list<array{bank: string, account: string, clabe: string, currency: string}>
     */
    public function normalizedBankAccounts(): array
    {
        $accounts = $this->bank_accounts;
        if (! is_array($accounts) || $accounts === []) {
            return config('quote_pdf.default_bank_accounts', []);
        }

        return array_values(array_filter(array_map(function ($row) {
            if (! is_array($row)) {
                return null;
            }

            $bank = trim((string) ($row['bank'] ?? ''));
            if ($bank === '') {
                return null;
            }

            return [
                'bank' => $bank,
                'account' => trim((string) ($row['account'] ?? '')),
                'clabe' => trim((string) ($row['clabe'] ?? '')),
                'currency' => trim((string) ($row['currency'] ?? 'M.N.')),
            ];
        }, $accounts)));
    }

    /**
     * @return list<array{label: string, address: string, phone: string}>
     */
    public function normalizedBranches(): array
    {
        $raw = trim((string) ($this->company_branches ?? ''));
        if ($raw !== '') {
            $parsed = CompanyBranchesParser::parse($raw);
            if ($parsed !== []) {
                return $parsed;
            }
        }

        return config('quote_pdf.default_branches', []);
    }

    public function pdfLegalName(): string
    {
        $legal = trim((string) ($this->company_legal_name ?? ''));
        $brand = trim((string) ($this->company_name ?? ''));
        $default = trim((string) config('quote_pdf.default_company_legal_name', ''));

        $isBrandOnly = $legal === ''
            || strcasecmp($legal, 'EXACTO') === 0
            || ($brand !== '' && strcasecmp($legal, $brand) === 0)
            || mb_strlen($legal) < 15;

        if (! $isBrandOnly) {
            return $legal;
        }

        return $default !== '' ? $default : $legal;
    }

    /**
     * @return list<array{label: string, address: string, phone: string}>
     */
    public function normalizedBranchesForPdf(): array
    {
        return array_values(array_filter(
            $this->normalizedBranches(),
            fn (array $branch) => stripos($branch['label'], 'Abasolo') === false,
        ));
    }

    public function pdfRfc(): string
    {
        $rfc = trim((string) ($this->company_rfc ?? ''));

        return $rfc !== '' ? $rfc : (string) config('quote_pdf.default_company_rfc', '');
    }

    public function resolvedAppName(): string
    {
        $name = trim((string) ($this->app_name ?? ''));

        return $name !== '' ? $name : 'Cotización B2B';
    }

    public function resolvedAppTagline(): string
    {
        $tagline = trim((string) ($this->app_tagline ?? ''));

        return $tagline !== '' ? $tagline : 'Uso interno de Exacto';
    }

    public function resolvedUnansweredQuoteDays(): int
    {
        $days = (int) ($this->unanswered_quote_days ?? 0);
        if ($days < 1) {
            return max(1, (int) config('dashboard.unanswered_quote_days', 3));
        }

        return min(365, $days);
    }

    /**
     * Branding de la SPA (menú lateral). Seguro para cualquier usuario autenticado.
     *
     * @return array{appName: string, appTagline: string, logoUrl: string|null}
     */
    public function toBrandingApiArray(): array
    {
        return [
            'appName' => $this->resolvedAppName(),
            'appTagline' => $this->resolvedAppTagline(),
            'logoUrl' => $this->logoPublicUrl(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toCommercialApiArray(): array
    {
        return [
            'defaultMarginPercent' => (float) $this->default_margin_percent,
            'taxPercent' => (float) $this->tax_percent,
            'quoteValidityDays' => (int) $this->quote_validity_days,
            'unansweredQuoteDays' => $this->resolvedUnansweredQuoteDays(),
            'minStockAlert' => (int) $this->min_stock_alert,
            'currencyCode' => $this->currency_code,
            'appName' => $this->app_name,
            'appTagline' => $this->app_tagline,
            'companyName' => $this->company_name,
            'companyLegalName' => $this->company_legal_name,
            'companyTagline' => $this->company_tagline,
            'companyRfc' => $this->company_rfc,
            'companyAddress' => $this->company_address,
            'companyBranches' => $this->company_branches,
            'companyPhone' => $this->company_phone,
            'companyEmail' => $this->company_email,
            'companyWebsite' => $this->company_website,
            'quoteTerms' => $this->quote_terms,
            'quoteSignatureName' => $this->quote_signature_name,
            'quoteSignatureEmail' => $this->quote_signature_email,
            'quoteFooterAddress' => $this->quote_footer_address,
            'bankAccounts' => $this->normalizedBankAccounts(),
            'logoUrl' => $this->logoPublicUrl(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }

    public function logoPublicUrl(): ?string
    {
        $relative = $this->resolvedLogoRelativePath();
        if ($relative === null) {
            return null;
        }

        return '/storage/'.$relative;
    }

    public function logoAbsolutePath(): ?string
    {
        $relative = $this->resolvedLogoRelativePath();
        if ($relative !== null) {
            $path = storage_path('app/public/'.$relative);
            if (is_file($path)) {
                return $path;
            }
        }

        $branding = resource_path('branding/exacto-logo.png');

        return is_file($branding) ? $branding : null;
    }

    /**
     * Ruta relativa bajo storage/app/public (p. ej. company/logo.png).
     */
    private function resolvedLogoRelativePath(): ?string
    {
        if (is_string($this->logo_path) && $this->logo_path !== '') {
            $configured = storage_path('app/public/'.$this->logo_path);
            if (is_file($configured)) {
                return $this->logo_path;
            }
        }

        $default = (string) config('quote_pdf.default_logo', 'company/logo.png');
        if ($default !== '' && is_file(storage_path('app/public/'.$default))) {
            return $default;
        }

        return null;
    }
}
