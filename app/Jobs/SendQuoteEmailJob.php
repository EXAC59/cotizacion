<?php

namespace App\Jobs;

use App\Mail\QuoteSentMail;
use App\Models\AppSetting;
use App\Models\Quote;
use App\Models\User;
use App\Services\Quotes\QuotePdfService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

class SendQuoteEmailJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120];

    public function __construct(
        public string $quoteId,
        public string $toEmail,
        public ?string $subject = null,
        public ?string $message = null,
        public ?int $sentByUserId = null,
    ) {}

    public function handle(QuotePdfService $pdfService): void
    {
        $quote = Quote::query()
            ->with(['lines', 'client'])
            ->find($this->quoteId);

        if ($quote === null) {
            throw new RuntimeException("Cotización {$this->quoteId} no encontrada.");
        }

        if ($quote->lines->isEmpty()) {
            throw new RuntimeException('La cotización no tiene partidas.');
        }

        $settings = AppSetting::current();
        $signer = $this->sentByUserId
            ? User::query()->find($this->sentByUserId)
            : null;
        $pdfBytes = $pdfService->render($this->quoteId, $signer)->output();

        Mail::to($this->toEmail)->send(new QuoteSentMail(
            quote: $quote,
            settings: $settings,
            pdfBytes: $pdfBytes,
            customMessage: $this->message,
            customSubject: $this->subject,
        ));

        $sentFrom = config('quotes.sent_from_statuses', []);

        if (in_array($quote->status, $sentFrom, true)) {
            $quote->update([
                'status' => 'enviada',
                'sent_at' => now(),
                'response_received_at' => null,
            ]);
        } elseif ($quote->status === 'enviada' && $quote->sent_at === null) {
            $quote->update(['sent_at' => now()]);
        }
    }
}
