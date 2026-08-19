<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cotización B2B</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: system-ui, sans-serif;
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            color: #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
        }
        .card {
            max-width: 32rem;
            width: 100%;
            background: #fff;
            color: #1e293b;
            border-radius: 1rem;
            padding: 2rem;
            box-shadow: 0 25px 50px -12px rgba(0,0,0,.4);
        }
        h1 { margin: 0 0 .5rem; font-size: 1.5rem; color: #0f172a; }
        p { margin: 0 0 1rem; font-size: .95rem; color: #64748b; line-height: 1.5; }
        code {
            display: block;
            background: #f1f5f9;
            padding: .75rem 1rem;
            border-radius: .5rem;
            font-size: .85rem;
            margin: .75rem 0 1.25rem;
            word-break: break-all;
        }
        a.btn {
            display: inline-block;
            background: #4f46e5;
            color: #fff !important;
            text-decoration: none;
            padding: .75rem 1.25rem;
            border-radius: .5rem;
            font-weight: 600;
            font-size: .95rem;
        }
        a.btn:hover { background: #4338ca; }
        .muted { font-size: .8rem; color: #94a3b8; margin-top: 1.5rem; }
        .api { margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #e2e8f0; }
        .api a { color: #4f46e5; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Cotización B2B</h1>
        <p>La interfaz aún no está compilada. En la terminal, dentro del proyecto:</p>
        <code>cd frontend<br>npm install<br>npm run build</code>
        <p>Después recarga esta página o entra directo a la aplicación:</p>
        <a class="btn" href="{{ url('/spa/') }}">Abrir aplicación →</a>
        <div class="api">
            <p class="muted" style="margin:0">API Laravel:</p>
            <a href="{{ url('/api/health') }}">{{ url('/api/health') }}</a>
        </div>
    </div>
</body>
</html>
