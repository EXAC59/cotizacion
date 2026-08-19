<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Cotización {{ $quote->folio }}</title>
    <style>
        @page { margin: 22px 28px 24px 28px; }
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 9px;
            color: #1a1a1a;
            line-height: 1.35;
        }

        /* Encabezado: logo + razon social + direcciones (sin fondos) */
        .header-table { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
        .header-table td { vertical-align: top; padding: 0; border: none; }
        .logo-cell { width: 34%; vertical-align: middle; }
        .logo { max-height: 72px; max-width: 210px; width: auto; height: auto; display: block; }
        .brand-fallback {
            font-size: 26px;
            font-weight: bold;
            letter-spacing: 1px;
            margin: 0;
            line-height: 1;
            color: #0175b4;
        }
        .tagline { font-size: 8px; margin-top: 2px; color: #0175b4; }
        .company-cell { width: 66%; padding-left: 12px; vertical-align: middle; }
        .branch-line { font-size: 10px; color: #000; line-height: 1.5; margin-bottom: 2px; }
        .branch-line .branch-label { font-weight: bold; color: #000; }
        .rfc-line { font-size: 10px; color: #000; font-weight: bold; margin-top: 2px; }

        /* Fecha / clave (sin fondo) */
        .doc-meta { text-align: right; font-size: 9px; margin: 2px 0 6px; }
        .doc-meta .meta-row { margin-top: 1px; }
        .doc-meta strong { color: #000; }

        .meta-table { width: 100%; border-collapse: collapse; margin: 6px 0 4px; font-size: 9px; }
        .meta-table td { vertical-align: top; padding: 2px 0; }
        .meta-label { font-weight: bold; width: 72px; color: #333; }

        .intro { font-size: 9px; margin: 8px 0 6px; color: #1a1a1a; }

        /* Tabla de partidas: encabezados azul claro */
        .items-table { width: 100%; border-collapse: collapse; margin-bottom: 0; }
        .items-table th {
            background: #d6e4f5;
            color: #134a8a;
            border: 1px solid #9db8d9;
            padding: 5px 4px;
            font-size: 8px;
            text-align: center;
        }
        .items-table td {
            border: 1px solid #d6dde6;
            padding: 5px 4px;
            vertical-align: top;
            font-size: 8.5px;
            background: #ffffff;
        }
        .items-table .num { text-align: right; white-space: nowrap; }
        .items-table .center { text-align: center; }
        .items-table .desc { text-align: left; }

        /* Totales (sin fondo) */
        .totals-box { width: 250px; margin-left: auto; margin-top: 0; border-collapse: collapse; font-size: 9px; }
        .totals-box td { padding: 3px 6px; }
        .totals-box .label { text-align: right; font-weight: bold; padding-right: 8px; color: #333; }
        .totals-box .value { text-align: right; width: 110px; }
        .totals-box .grand .label,
        .totals-box .grand .value {
            font-size: 10px;
            font-weight: bold;
            padding-top: 4px;
            color: #000000;
            border-top: 1px solid #9db8d9;
        }
        .amount-words {
            text-align: right;
            font-size: 8px;
            font-weight: bold;
            margin: 4px 0 10px;
            text-transform: uppercase;
            color: #333;
        }

        /* Condiciones: fondo gris claro */
        .terms {
            font-size: 7.5px;
            line-height: 1.45;
            white-space: pre-line;
            margin-bottom: 14px;
            padding: 8px 10px;
            background: #f3f4f6;
            border: 1px solid #e5e7eb;
            color: #333;
        }

        /* Firma */
        .signature { text-align: center; margin: 16px 0 8px; font-size: 9px; color: #1a1a1a; }
        .signature .atentamente { font-weight: bold; letter-spacing: 0.5px; margin-bottom: 2px; color: #000; }
        .signature .firma-img { height: 56px; width: auto; display: block; margin: 0 auto 2px; }
        .signature .firma-name { font-weight: bold; color: #000; }
        .signature .firma-email { color: #134a8a; }
        .signature .firma-branch { font-weight: bold; color: #000; margin-top: 1px; }

        .bank-title { font-size: 8px; font-weight: bold; margin-bottom: 4px; color: #000; }
        .bank-table { width: 100%; border-collapse: collapse; font-size: 8px; }
        .bank-table th {
            background: #d6e4f5;
            color: #134a8a;
            border: 1px solid #9db8d9;
            padding: 4px;
            text-align: center;
        }
        .bank-table td {
            border: 1px solid #d6dde6;
            padding: 4px;
            text-align: center;
            background: #ffffff;
        }
    </style>
</head>
<body>
    {{-- Encabezado: logo EXACTO + razon social + direcciones --}}
    <table class="header-table">
        <tr>
            <td class="logo-cell">
                @if($logoDataUri)
                    <img src="{{ $logoDataUri }}" class="logo" alt="{{ $settings->company_name ?: 'EXACTO' }}">
                @else
                    <p class="brand-fallback">{{ $settings->company_name ?: 'EXACTO' }}</p>
                    @if($settings->company_tagline)
                        <div class="tagline">{{ $settings->company_tagline }}</div>
                    @endif
                @endif
            </td>
            <td class="company-cell">
                @foreach($branches as $branch)
                    @php($branchLabel = (string) ($branch['label'] ?? ''))
                    @php($branchAddress = (string) ($branch['address'] ?? ''))
                    @php($branchPhone = (string) ($branch['phone'] ?? ''))
                    <div class="branch-line">
                        @if($branchLabel !== '')
                            <span class="branch-label">{{ $branchLabel }}:</span>
                        @endif
                        {{ $branchAddress }}@if($branchPhone !== '') - {{ $branchPhone }}@endif
                    </div>
                @endforeach
                @if($companyRfc !== '')
                    <div class="rfc-line">R.F.C. {{ $companyRfc }}</div>
                @endif
            </td>
        </tr>
    </table>

    {{-- Fecha / clave de cliente (sin fondo) --}}
    <div class="doc-meta">
        <div class="meta-row"><strong>Fecha:</strong> {{ $createdAt->timezone('America/Mexico_City')->format('d/m/Y') }}</div>
        @if($clientCode !== '')
            <div class="meta-row"><strong>Clave cliente:</strong> {{ $clientCode }}</div>
        @endif
    </div>

    {{-- Datos cotización / cliente --}}
    <table class="meta-table">
        <tr>
            <td style="width: 100%;">
                <table style="width:100%; border-collapse:collapse;">
                    <tr>
                        <td class="meta-label">COTIZACIÓN:</td>
                        <td>{{ $quote->folio }}</td>
                    </tr>
                    <tr>
                        <td class="meta-label">Cliente:</td>
                        <td>{{ $client?->company ?? '—' }}</td>
                    </tr>
                    <tr>
                        <td class="meta-label">Domicilio:</td>
                        <td>{{ $client?->address ?? '—' }}</td>
                    </tr>
                    <tr>
                        <td class="meta-label">RFC:</td>
                        <td>{{ $client?->rfc ?? '—' }}</td>
                    </tr>
                    <tr>
                        <td class="meta-label">Correo:</td>
                        <td>{{ $client?->email ?? '' }}</td>
                    </tr>
                    <tr>
                        <td class="meta-label">Atención:</td>
                        <td>{{ $client?->contact_name ?? '' }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <p class="intro">En atención a su solicitud, le presentamos la siguiente cotización:</p>

    {{-- Tabla de partidas --}}
    <table class="items-table">
        <thead>
            <tr>
                <th style="width:8%;">CANTIDAD</th>
                <th style="width:10%;">CLAVE</th>
                <th style="width:8%;">UNIDAD</th>
                <th style="width:44%;">DESCRIPCIÓN</th>
                <th style="width:15%;">PRECIO UNITARIO</th>
                <th style="width:15%;">IMPORTE</th>
            </tr>
        </thead>
        <tbody>
            @foreach($lines as $line)
                <tr>
                    <td class="num center">{{ number_format($line['quantity'], 2) }}</td>
                    <td class="center">{{ $line['partNumber'] ?: '' }}</td>
                    <td class="center">{{ $line['unit'] }}</td>
                    <td class="desc">{{ $line['product'] }}</td>
                    <td class="num">{{ number_format($line['salePrice'], 2) }}</td>
                    <td class="num">{{ number_format($line['amount'], 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    {{-- Totales --}}
    <table class="totals-box">
        <tr>
            <td class="label">SUBTOTAL:</td>
            <td class="value">$ {{ number_format($totals['subtotal'], 2) }} MXN</td>
        </tr>
        <tr>
            <td class="label">I.V.A.:</td>
            <td class="value">$ {{ number_format($totals['tax'], 2) }} MXN</td>
        </tr>
        <tr class="grand">
            <td class="label">TOTAL:</td>
            <td class="value">$ {{ number_format($totals['total'], 2) }} MXN</td>
        </tr>
    </table>
    <div class="amount-words">{{ $totalInWords }}</div>

    @if(trim((string) ($quote->customer_observations ?? '')) !== '')
        <div class="terms">
            <strong>OBSERVACIONES:</strong><br>
            {!! nl2br(e($quote->customer_observations)) !!}
        </div>
    @endif

    {{-- Condiciones --}}
    <div class="terms">{{ $settings->quote_terms ?: $defaultTerms }}</div>

    {{-- Firma --}}
    <div class="signature">
        <div class="atentamente">ATENTAMENTE</div>
        @if($signatureImageDataUri)
            <img src="{{ $signatureImageDataUri }}" class="firma-img" alt="Firma">
        @endif
        @if($signatureName)
            <div class="firma-name">{{ $signatureName }}</div>
        @endif
        @if($signatureEmail)
            <div class="firma-email">{{ $signatureEmail }}</div>
        @endif
        @if($signatureBranch)
            <div class="firma-branch">{{ $signatureBranch }}</div>
        @endif
    </div>

    {{-- Datos bancarios --}}
    @if(count($bankAccounts) > 0)
        <div class="bank-title">Para transferencia o depósito:</div>
        <table class="bank-table">
            <thead>
                <tr>
                    <th style="width:22%;">BANCO</th>
                    <th style="width:22%;">CUENTA</th>
                    <th style="width:36%;">CLABE</th>
                    <th style="width:20%;">MONEDA</th>
                </tr>
            </thead>
            <tbody>
                @foreach($bankAccounts as $account)
                    <tr>
                        <td>{{ $account['bank'] }}</td>
                        <td>{{ $account['account'] }}</td>
                        <td>{{ $account['clabe'] }}</td>
                        <td>{{ $account['currency'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</body>
</html>
