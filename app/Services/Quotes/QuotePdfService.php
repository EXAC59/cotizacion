<?php

namespace App\Services\Quotes;

use App\Models\AppSetting;
use App\Models\Quote;
use App\Models\User;
use App\Support\AmountInWords;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class QuotePdfService
{
    public function __construct(
        private readonly QuoteProfitCalculator $calculator,
    ) {}

    public function render(string $quoteId, ?User $signer = null): \Barryvdh\DomPDF\PDF
    {
        if (! Str::isUuid($quoteId)) {
            abort(404, 'Cotización no encontrada.');
        }

        $quote = Quote::query()
            ->with(['lines', 'client', 'creator'])
            ->find($quoteId);

        if ($quote === null) {
            abort(404, 'Cotización no encontrada.');
        }

        if ($quote->lines->isEmpty()) {
            throw ValidationException::withMessages([
                'lines' => ['La cotización no tiene partidas para generar PDF.'],
            ]);
        }

        $settings = AppSetting::current();
        $viewData = $this->buildViewData($quote, $settings, $signer);

        return Pdf::loadView('pdf.quote', $viewData)
            ->setPaper('letter', 'portrait');
    }

    /**
     * @return array<string, mixed>
     */
    private function buildViewData(Quote $quote, AppSetting $settings, ?User $signer = null): array
    {
        $createdAt = $quote->created_at ?? now();
        $validUntil = $createdAt->copy()->addDays((int) $quote->validity_days);

        $lines = $quote->lines->map(function ($line) {
            return [
                'quantity' => (float) $line->quantity,
                'partNumber' => $line->part_number,
                'unit' => 'No',
                'product' => $line->product,
                'salePrice' => (float) $line->sale_price,
                'amount' => (float) $line->amount,
            ];
        })->values()->all();

        $linePayloads = $quote->lines->map(fn ($line) => [
            'quantity' => (float) $line->quantity,
            'cost' => (float) $line->cost,
            'amount' => (float) $line->amount,
        ])->all();

        $totals = $this->calculator->quoteTotals($linePayloads, (float) $quote->tax_percent);

        $logoPath = $settings->logoAbsolutePath();
        $logoDataUri = $this->fileToDataUri($logoPath);

        // Quién firma: firmante explícito (cola) → sesión → creador.
        if (! $signer instanceof User) {
            $authUser = Auth::user();
            $signer = $authUser instanceof User ? $authUser : $quote->creator;
        }

        $userSignaturePath = $signer?->signatureAbsolutePath();
        if ($userSignaturePath !== null) {
            $signatureImageDataUri = $this->fileToDataUri($userSignaturePath);
        } else {
            $signaturePath = storage_path('app/public/'.config('quote_pdf.signature_image'));
            $signatureImageDataUri = is_file($signaturePath) ? $this->fileToDataUri($signaturePath) : null;
        }

        $signatureName = trim((string) ($signer?->name ?? ''));
        if ($signatureName === '') {
            $signatureName = trim((string) $settings->quote_signature_name)
                ?: (string) config('quote_pdf.default_signature_name');
        }

        $signerEmail = trim((string) ($signer?->email ?? ''));
        if ($signerEmail !== '' && ! str_ends_with(strtolower($signerEmail), '@users.local')) {
            $signatureEmail = $signerEmail;
        } else {
            $signatureEmail = trim((string) $settings->quote_signature_email)
                ?: (string) config('quote_pdf.default_signature_email');
        }

        $client = $quote->client;
        $clientCode = '';
        if ($client !== null) {
            $clientCode = Str::upper(Str::substr(preg_replace('/[^A-Za-z0-9]/', '', $client->company) ?? '', 0, 5));
        }

        return [
            'quote' => $quote,
            'settings' => $settings,
            'client' => $client,
            'clientCode' => $clientCode,
            'lines' => $lines,
            'totals' => $totals,
            'taxPercent' => (float) $quote->tax_percent,
            'createdAt' => $createdAt,
            'validUntil' => $validUntil,
            'logoDataUri' => $logoDataUri,
            'totalInWords' => AmountInWords::pesosMx((float) $totals['total']),
            'bankAccounts' => $settings->normalizedBankAccounts(),
            'branches' => $settings->normalizedBranchesForPdf(),
            'legalName' => $settings->pdfLegalName(),
            'companyRfc' => $settings->pdfRfc(),
            'defaultTerms' => config('quote_pdf.default_terms'),
            'signatureName' => $signatureName,
            'signatureEmail' => $signatureEmail,
            'signatureBranch' => config('quote_pdf.default_signature_branch'),
            'signatureImageDataUri' => $signatureImageDataUri,
        ];
    }

    private function fileToDataUri(?string $path): ?string
    {
        if ($path === null || ! is_file($path)) {
            return null;
        }

        $mime = mime_content_type($path) ?: 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($path));
    }
}
