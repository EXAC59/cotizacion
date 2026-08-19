<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Cotización {{ $quote->folio }}</title>
</head>
<body style="font-family: Arial, sans-serif; font-size: 14px; color: #222; line-height: 1.5;">
    @php
        $clientName = $quote->client?->company ?? 'cliente';
        $companyName = $settings->company_name ?: config('app.name');
        $signatureName = $settings->quote_signature_name ?: $companyName;
    @endphp

    <p>Estimado(a) {{ $clientName }},</p>

    @if($customMessage)
        <p>{!! nl2br(e($customMessage)) !!}</p>
    @else
        <p>Adjunto encontrará la cotización <strong>{{ $quote->folio }}</strong>.</p>
    @endif

    <p>
        <strong>Folio:</strong> {{ $quote->folio }}<br>
        <strong>Total:</strong> ${{ number_format((float) $quote->total, 2) }} MXN
    </p>

    <p>Quedamos atentos a sus comentarios.</p>

    <p style="margin-top: 24px;">
        Saludos cordiales,<br>
        <strong>{{ $signatureName }}</strong>
        @if($settings->quote_signature_email)
            <br>{{ $settings->quote_signature_email }}
        @elseif($settings->company_email)
            <br>{{ $settings->company_email }}
        @endif
    </p>
</body>
</html>
