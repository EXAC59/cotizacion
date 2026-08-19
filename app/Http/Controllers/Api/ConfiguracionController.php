<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\ComparatorSetting;
use App\Services\Wholesalers\UserComparatorPreferenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class ConfiguracionController extends Controller
{
    public function branding(): JsonResponse
    {
        return response()->json(AppSetting::current()->toBrandingApiArray());
    }

    public function comercial(): JsonResponse
    {
        return response()->json(AppSetting::current()->toCommercialApiArray());
    }

    public function updateComercial(Request $request): JsonResponse
    {
        // Inputs vacíos de email no pasan la regla "email"; tratarlos como null.
        foreach (['companyEmail', 'quoteSignatureEmail'] as $emailKey) {
            if ($request->exists($emailKey) && trim((string) $request->input($emailKey)) === '') {
                $request->merge([$emailKey => null]);
            }
        }

        $validated = $request->validate([
            'defaultMarginPercent' => ['nullable', 'numeric', 'min:0', 'max:999'],
            'taxPercent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'quoteValidityDays' => ['nullable', 'integer', 'min:1', 'max:365'],
            'unansweredQuoteDays' => ['nullable', 'integer', 'min:1', 'max:365'],
            'minStockAlert' => ['nullable', 'integer', 'min:0'],
            'currencyCode' => ['nullable', 'string', 'size:3'],
            'appName' => ['nullable', 'string', 'max:120'],
            'appTagline' => ['nullable', 'string', 'max:160'],
            'companyName' => ['nullable', 'string', 'max:255'],
            'companyRfc' => ['nullable', 'string', 'max:20'],
            'companyAddress' => ['nullable', 'string', 'max:2000'],
            'companyPhone' => ['nullable', 'string', 'max:40'],
            'companyEmail' => ['nullable', 'string', 'email', 'max:120'],
            'companyWebsite' => ['nullable', 'string', 'max:255'],
            'quoteTerms' => ['nullable', 'string', 'max:10000'],
            'companyLegalName' => ['nullable', 'string', 'max:255'],
            'companyTagline' => ['nullable', 'string', 'max:255'],
            'companyBranches' => ['nullable', 'string', 'max:5000'],
            'quoteSignatureName' => ['nullable', 'string', 'max:120'],
            'quoteSignatureEmail' => ['nullable', 'string', 'email', 'max:120'],
            'quoteFooterAddress' => ['nullable', 'string', 'max:2000'],
            'bankAccounts' => ['nullable', 'array'],
            'bankAccounts.*.bank' => ['required_with:bankAccounts', 'string', 'max:80'],
            'bankAccounts.*.account' => ['nullable', 'string', 'max:40'],
            'bankAccounts.*.clabe' => ['nullable', 'string', 'max:30'],
            'bankAccounts.*.currency' => ['nullable', 'string', 'max:10'],
        ]);

        $settings = AppSetting::current();

        $user = $request->user();
        $user?->loadMissing('role');
        $isAdmin = $user?->role_slug === 'administrador';

        $update = [
            'default_margin_percent' => $validated['defaultMarginPercent'] ?? $settings->default_margin_percent,
            'tax_percent' => $validated['taxPercent'] ?? $settings->tax_percent,
            'quote_validity_days' => $validated['quoteValidityDays'] ?? $settings->quote_validity_days,
            'min_stock_alert' => $validated['minStockAlert'] ?? $settings->min_stock_alert,
            'currency_code' => isset($validated['currencyCode'])
                ? strtoupper($validated['currencyCode'])
                : $settings->currency_code,
        ];

        if ($isAdmin && array_key_exists('unansweredQuoteDays', $validated)) {
            $update['unanswered_quote_days'] = $validated['unansweredQuoteDays'];
        }

        $settings->update(array_merge($update, [
            'app_name' => array_key_exists('appName', $validated)
                ? (trim((string) ($validated['appName'] ?? '')) !== ''
                    ? trim((string) $validated['appName'])
                    : null)
                : $settings->app_name,
            'app_tagline' => array_key_exists('appTagline', $validated)
                ? (trim((string) ($validated['appTagline'] ?? '')) !== ''
                    ? trim((string) $validated['appTagline'])
                    : null)
                : $settings->app_tagline,
            'company_name' => array_key_exists('companyName', $validated)
                ? $validated['companyName']
                : $settings->company_name,
            'company_rfc' => array_key_exists('companyRfc', $validated)
                ? $validated['companyRfc']
                : $settings->company_rfc,
            'company_address' => array_key_exists('companyAddress', $validated)
                ? $validated['companyAddress']
                : $settings->company_address,
            'company_phone' => array_key_exists('companyPhone', $validated)
                ? $validated['companyPhone']
                : $settings->company_phone,
            'company_email' => array_key_exists('companyEmail', $validated)
                ? $validated['companyEmail']
                : $settings->company_email,
            'company_website' => array_key_exists('companyWebsite', $validated)
                ? $validated['companyWebsite']
                : $settings->company_website,
            'quote_terms' => array_key_exists('quoteTerms', $validated)
                ? $validated['quoteTerms']
                : $settings->quote_terms,
            'company_legal_name' => array_key_exists('companyLegalName', $validated)
                ? $validated['companyLegalName']
                : $settings->company_legal_name,
            'company_tagline' => array_key_exists('companyTagline', $validated)
                ? $validated['companyTagline']
                : $settings->company_tagline,
            'company_branches' => array_key_exists('companyBranches', $validated)
                ? $validated['companyBranches']
                : $settings->company_branches,
            'quote_signature_name' => array_key_exists('quoteSignatureName', $validated)
                ? $validated['quoteSignatureName']
                : $settings->quote_signature_name,
            'quote_signature_email' => array_key_exists('quoteSignatureEmail', $validated)
                ? $validated['quoteSignatureEmail']
                : $settings->quote_signature_email,
            'quote_footer_address' => array_key_exists('quoteFooterAddress', $validated)
                ? $validated['quoteFooterAddress']
                : $settings->quote_footer_address,
            'bank_accounts' => array_key_exists('bankAccounts', $validated)
                ? $validated['bankAccounts']
                : $settings->bank_accounts,
            'updated_at' => now(),
        ]));

        return response()->json($settings->fresh()->toCommercialApiArray());
    }

    public function comparador(): JsonResponse
    {
        return response()->json(ComparatorSetting::current()->toApiArray());
    }

    public function updateComparador(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'weights' => ['nullable', 'array'],
            'weights.price' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'weights.stock' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'weights.warehouse' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'weights.performance' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'weights.preferred' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'warehousePriority' => ['nullable', 'array'],
            'warehousePriority.*' => ['string', 'max:20'],
            'preferredWholesalerIds' => ['nullable', 'array'],
            'preferredWholesalerIds.*' => ['uuid'],
            'importPenalty' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'leadDayPenalty' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'minStockThreshold' => ['nullable', 'integer', 'min:0'],
        ]);

        $settings = ComparatorSetting::current();
        $settings->update([
            'weights' => array_key_exists('weights', $validated)
                ? array_merge($settings->normalizedWeights(), $validated['weights'] ?? [])
                : $settings->weights,
            'warehouse_priority' => $validated['warehousePriority'] ?? $settings->warehouse_priority,
            'preferred_wholesaler_ids' => $validated['preferredWholesalerIds'] ?? $settings->preferred_wholesaler_ids,
            'import_penalty' => $validated['importPenalty'] ?? $settings->import_penalty,
            'lead_day_penalty' => $validated['leadDayPenalty'] ?? $settings->lead_day_penalty,
            'min_stock_threshold' => $validated['minStockThreshold'] ?? $settings->min_stock_threshold,
            'updated_at' => now(),
        ]);

        return response()->json(ComparatorSetting::query()->findOrFail($settings->getKey())->toApiArray());
    }

    public function comparadorPreferencias(Request $request, UserComparatorPreferenceService $prefs): JsonResponse
    {
        $email = Auth::user()?->email;

        return response()->json($prefs->forEmail(is_string($email) ? $email : null));
    }

    public function updateComparadorPreferencias(Request $request, UserComparatorPreferenceService $prefs): JsonResponse
    {
        $validated = $request->validate([
            'preferredWarehouse' => ['nullable', 'string', 'max:40'],
            'preferredWarehouses' => ['nullable', 'array', 'max:500'],
            'preferredWarehouses.*' => ['string', 'max:40'],
            'autoApplyBest' => ['nullable', 'boolean'],
            'preferredWholesalerIds' => ['nullable', 'array'],
            'preferredWholesalerIds.*' => ['uuid'],
        ]);

        $email = Auth::user()?->email;

        return response()->json($prefs->upsertForEmail(is_string($email) ? $email : null, $validated));
    }

    public function uploadLogo(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'logo' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ]);

        $settings = AppSetting::current();
        $file = $validated['logo'];

        if ($settings->logo_path) {
            Storage::disk('public')->delete($settings->logo_path);
        }

        $extension = $file->getClientOriginalExtension() ?: 'png';
        $storedPath = $file->storeAs('company', 'logo.'.$extension, 'public');

        $settings->update([
            'logo_path' => $storedPath,
            'updated_at' => now(),
        ]);

        return response()->json($settings->fresh()->toCommercialApiArray());
    }
}
