<?php

namespace App\Mail;

use App\Models\AppSetting;
use App\Models\Quote;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class QuoteSentMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Quote $quote,
        public AppSetting $settings,
        public string $pdfBytes,
        public ?string $customMessage = null,
        public ?string $customSubject = null,
    ) {}

    public function envelope(): Envelope
    {
        $companyName = $this->settings->company_name ?: (string) config('app.name');
        $subject = $this->customSubject ?? "Cotización {$this->quote->folio} — {$companyName}";

        $replyTo = [];
        $replyEmail = trim((string) ($this->settings->quote_signature_email ?: $this->settings->company_email ?: ''));
        if ($replyEmail !== '') {
            $replyTo = [new Address($replyEmail)];
        }

        return new Envelope(
            subject: $subject,
            replyTo: $replyTo,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.quote-sent',
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        $filename = preg_replace('/[^A-Za-z0-9._-]+/', '_', $this->quote->folio).'.pdf';

        return [
            Attachment::fromData(fn () => $this->pdfBytes, $filename)
                ->withMime('application/pdf'),
        ];
    }
}
