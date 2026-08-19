# Importar workflow n8n — Comparador de precios

## Antes de empezar

1. Laragon **Start All**
2. Docker: `.\scripts\lectura-up.cmd` (n8n corriendo)
3. En `.env`:

```env
N8N_COMPARATOR_WEBHOOK_URL=http://localhost:5678/webhook/comparador-precios
COMPARATOR_POLL_TIMEOUT=60
WHOLESALER_DEMO_OFFERS=true
```

## Importar

1. Abre http://localhost:5678
2. **Import from file** → `docs/n8n/comparador-precios.workflow.json`
3. **Save** y activa el workflow (interruptor verde)

Path del webhook: `comparador-precios`

## Verificar

```powershell
cd c:\laragon\www\cotizacion
php artisan n8n:verify-comparador
```

## Flujo

```
Webhook comparador-precios
  → GET /api/n8n/mayoristas?active=1   (middleware n8n.webhook, sin Sanctum)
  → Contexto (job_id, part_number, quantity, searchAllWarehouses=true)
  → IF demo_mode:
       → Code mock por mayorista activo
     ELSE:
       → Fan-out por mayorista
       → POST /api/n8n/mayoristas/{id}/consultar  (Laravel/Docker resuelve SKU→oferta)
       → Unir ofertas
  → POST /api/n8n/comparador (Laravel rankea, marca mejor oferta; el usuario elige en UI)
```

**Búsqueda:** todos los almacenes de cada mayorista (nacional). Los “almacenes preferidos” de la UI **no limitan** existencias; solo son referencia. n8n orquesta; Docker (API Laravel) resuelve CT/CVA y el ranking.

**Importante:** no uses `log.exacto.mx` ni `/v1/brands`. Las URLs correctas van a `http://nginx/api/n8n/...` (red Docker) o, desde fuera, `https://exacto.mx/api/n8n/...`.

## Modo demo vs consultas reales

- **`demo_mode: true`** (default): usa el nodo **Generar ofertas mock** — no llama APIs de mayoristas.
- **`demo_mode: false`** en el body del webhook: fan-out a `POST /api/n8n/mayoristas/{id}/consultar` por cada mayorista activo.
- Si ningún conector devuelve ofertas, **Unir ofertas** genera mock de respaldo para no dejar el job vacío.

En Laravel, `WHOLESALER_DEMO_OFFERS=true` también activa ofertas demo locales cuando el lookup paralelo no obtiene resultados.

## Probar desde Laravel

```powershell
php artisan tinker --execute="
\$r = app(\App\Services\Wholesalers\ComparatorJobService::class)->dispatch('C9200L-24T-4G-E', 2, 'CDMX');
echo \$r->id.' '.\$r->status;
"
```

Luego poll: `GET /api/comparador/{jobId}`

## Integración real (API keys)

1. Configura cada mayorista en **Mayoristas** con `config_json.env_prefix`.
2. Dispara comparador con `demo_mode: false` en el webhook o desactiva `WHOLESALER_DEMO_OFFERS`.
3. El fan-out n8n reutiliza los conectores Laravel vía `POST /api/n8n/mayoristas/{id}/consultar` — no duplica lógica de conectores.

El callback a Laravel (`POST /api/n8n/comparador`) no cambia.
