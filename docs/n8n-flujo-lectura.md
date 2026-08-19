# Flujo n8n — motor de lectura

Docling + OCR local: ver **[docling-lectura-local.md](./docling-lectura-local.md)**.

## Si PowerShell dice «docker no se reconoce»

**No tienes Docker instalado** (o no está en el PATH). Opciones:

| Opción | Qué hacer |
|--------|-----------|
| **A — Docker** (recomendado) | Instala [Docker Desktop](https://www.docker.com/products/docker-desktop/), ábrelo, reinicia PowerShell, `.\scripts\lectura-up.ps1` |
| **B — Sin Docker** | `.\scripts\lectura-up-sin-docker.ps1` (Python 3.10+ + Node) |

---

n8n procesa documentos (PDF, Excel, Word) y devuelve el resultado a Laravel.

## Arquitectura

```
[Frontend React] → [Laravel API] → webhook n8n → procesamiento
                         ↑
              POST /api/n8n/lectura ← callback del flujo
```

## Configuración en n8n

1. Abre n8n: http://localhost:5678 (con `docker compose up -d`).
2. Importa el workflow listo: `docs/n8n/lectura-cotizacion.workflow.json`
   - **Webhook** (POST) — path: `lectura-docling`
   - **Docling convert** → markdown del documento
   - **Laravel interpretar** → `POST /api/solicitudes/interpretar` (`LecturaLineParser`, sin LLM)
   - **Laravel callback** → `POST http://host.docker.internal/api/n8n/lectura`
3. Copia la URL del webhook en `.env`:

```env
N8N_WEBHOOK_URL=http://localhost:5678/webhook/lectura-docling
```

## Disparar lectura desde Laravel

```php
use App\Services\N8nClient;

app(N8nClient::class)->dispararLectura([
    'documento_id' => 1,
    'url' => 'https://ejemplo.com/archivo.pdf',
]);
```

## Payload de callback (ejemplo)

```json
{
  "fuente": "upload",
  "tipo": "cotizacion_pdf",
  "contenido": {
    "cliente": "ACME",
    "total": 15000
  },
  "metadata": {
    "workflow_id": "abc123"
  }
}
```
