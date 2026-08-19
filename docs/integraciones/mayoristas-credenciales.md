# Credenciales mayoristas — comparador de precios

Variables en `.env` del VPS. Tras cambiar: `docker compose ... restart api queue`.

Modo demo (sin APIs reales): `WHOLESALER_DEMO_OFFERS=true` — genera ofertas ficticias CT/Ingram/Exel.

Producción: `WHOLESALER_DEMO_OFFERS=false` y completar credenciales abajo.

---

## CT Connect — https://api.ctonline.mx/documentacion.html

Variables en `.env` del VPS. Tras cambiar: `docker compose ... restart api queue`.

Modo demo (ofertas ficticias): `WHOLESALER_DEMO_OFFERS=true` — **desactivado en producción**.

Solo CT en comparador: `WHOLESALER_COMPARE_CODES=CT`

---

## CT Internacional (API) — piloto

| Variable | Descripción | Ejemplo |
|----------|-------------|---------|
| `WHOLESALER_CT_BASE_URL` | API CT Connect | `https://api.ctonline.mx:3001` (prod) o `:4000` (sandbox) |
| `WHOLESALER_CT_API_KEY` | Token `x-auth` (recomendado) | `(de CT)` |
| `WHOLESALER_CT_EMAIL` | Si no hay token fijo | correo cliente CT |
| `WHOLESALER_CT_CLIENTE` | Número de cliente CT | `HMO0001` |
| `WHOLESALER_CT_RFC` | RFC registrado en CT | |
| `WHOLESALER_CT_LOOKUP_PATH` | Existencia + precio | `/existencia/promociones/{part_number}` |
| `WHOLESALER_CT_SOURCE_IP` | IP whitelisted en CT | `2.25.78.222` |
| `WHOLESALER_CT_TIMEOUT` | Segundos | `20` |

CT exige **whitelist de IP** — registrar la IP del VPS en su portal.

Aplicar credenciales (local o VPS):

```powershell
# 1. Copiar scripts/.wholesaler-ct.local.example → scripts/.wholesaler-ct.local
# 2. Completar token o email/cliente/rfc
.\scripts\apply-ct-credentials.ps1          # local
.\scripts\apply-ct-credentials.ps1 -TargetVps
```

Verificar:

```bash
docker compose ... exec -T api php artisan wholesalers:test-ct ACCBLC010
```

---

## Ingram Micro (API)

| Variable | Descripción |
|----------|-------------|
| `WHOLESALER_INGRAM_BASE_URL` | URL base del API Ingram (entregar por cuenta) |
| `WHOLESALER_INGRAM_API_KEY` | Bearer token o API key |
| `WHOLESALER_INGRAM_LOOKUP_PATH` | Default: `/products/{part_number}` |
| `WHOLESALER_INGRAM_TIMEOUT` | Default: `15` |
| `WHOLESALER_INGRAM_AUTH_HEADER` | Si no es `Authorization: Bearer`, p. ej. `IM-CustomerNumber` |

Respuesta esperada (JSON): campos `cost`/`price`/`customerPrice`, `stock`/`quantity`/`availableQuantity`, `warehouse`. También acepta envelopes `data`, `product`, `products[0]`, `items[0]` y bloques `pricing` / `availability`.

Sin credenciales reales de Ingram el comparador no inventa precios (salvo `WHOLESALER_DEMO_OFFERS=true` en local).

---

## Exel del Norte (CSV)

Integración tipo **CSV** — el conector lee un catálogo local o una URL.

| Variable | Uso |
|----------|-----|
| `WHOLESALER_EXEL_CSV_PATH` | Ruta al CSV (absoluta o relativa a `storage/` / `storage/app/`) |
| `WHOLESALER_EXEL_BASE_URL` | URL de descarga del CSV (alternativa a path) |
| `WHOLESALER_EXEL_API_KEY` | Auth opcional si la URL la requiere |
| `WHOLESALER_EXEL_CSV_TTL` | Minutos de caché del catálogo (default `15`) |
| `WHOLESALER_EXEL_TIMEOUT` | Timeout descarga HTTP (default `30`) |

Columnas reconocidas: `part_number`/`sku`/`no_parte`, `cost`/`precio`/`costo`, `stock`/`existencia`, `warehouse`/`almacen`, `description`/`producto` (opcional).

Plantilla de ejemplo: `storage/app/wholesalers/exel.example.csv`

```env
WHOLESALER_EXEL_CSV_PATH=wholesalers/exel.csv
```

Para incluir Exel en el comparador: `WHOLESALER_COMPARE_CODES=CT,EXEL` (o vacío = todos activos configurados).

---

## CVA (API) — https://apicvaservices.grupocva.com/documentation/

| Variable | Descripción | Ejemplo |
|----------|-------------|---------|
| `WHOLESALER_CVA_USER` | Usuario API CVA | `admin37274` |
| `WHOLESALER_CVA_PASSWORD` | Contraseña API | `(secreto)` |
| `WHOLESALER_CVA_BASE_URL` | Base API v2 | `https://apicvaservices.grupocva.com/api/v2` |
| `WHOLESALER_CVA_TIMEOUT` | Segundos | `20` |
| `WHOLESALER_CVA_FX` | Tipo de cambio USD→MXN si hiciera falta | opcional |

Login: `POST /user/login` → Bearer 12 h. Lookup: `precios_stock_ofertas` por `clave` o `codigo` (con `MonedaPesos=1`).

```bash
# 1. Completar scripts/.wholesaler-cva.local
# 2. Aplicar:
./scripts/apply-cva-credentials.sh          # local
./scripts/apply-cva-credentials.sh --vps    # VPS

# 3. Probar:
php artisan wholesalers:test-cva PR-2586
# o en VPS:
docker compose ... exec -T api php artisan wholesalers:test-cva PR-2586
```

En comparador: `WHOLESALER_COMPARE_CODES=CT,CVA` (ventas ve CVA como **BODEGA02**).

### Catálogo completo (sync)

```bash
# Descarga precios/stock (+ descripciones vía lista_precios) a storage/app/cva-catalog/
php artisan wholesalers:sync-cva-catalog --batch=LG
# Solo stock/precios (más rápido):
php artisan wholesalers:sync-cva-catalog --batch=XL --skip-descriptions
# Estado:
php artisan wholesalers:sync-cva-catalog --status
```

En VPS queda programado cada 4 horas (`bootstrap/app.php`). Autocomplete: `GET /api/mayoristas/cva/autocomplete`.

---

## CVA, Syscom, Team (legado / otros)

Syscom/Team: mismo patrón genérico `WHOLESALER_{CODE}_BASE_URL`, `_API_KEY`, `_LOOKUP_PATH`, `_TIMEOUT`.

CVA ya no usa CSV: ver sección **CVA (API)** arriba.

Prefijos en `config/wholesalers.php` → `env_prefix`.

---

## Checklist comparador real

1. `.env`: `WHOLESALER_DEMO_OFFERS=false`
2. Credenciales CT (+ Ingram si aplica)
3. `php artisan n8n:verify-comparador`
4. En SPA: solicitud con SKU → **Comparar precios**
5. Revisar n8n workflow **comparador-precios** activo

---

## Seguridad

- No commitear `.env` ni API keys
- Rotar keys si se exponen en chat o logs
- IP del VPS fija o actualizar whitelist en CT al cambiar servidor
