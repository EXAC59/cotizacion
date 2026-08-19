# CT Internacional — CT Connect API

Documentación: [api.ctonline.mx/documentacion.html](https://api.ctonline.mx/documentacion.html)

## Puertos API (requisito CT)

CT publica la API en puertos no estándar:

| Entorno | URL base | Puerto |
|---------|----------|--------|
| **Producción** | `http://connect.ctonline.mx:3001` (alternativa documentada: `https://api.ctonline.mx:3001`) | 3001 |
| **Sandbox** | `https://api.ctonline.mx:4000` | 4000 |

En el **VPS** deben estar accesibles (firewall):

```bash
bash scripts/vps-open-ct-ports.sh   # UFW 4000/tcp y 3001/tcp
```

También en **Hostinger hPanel → VPS → Firewall**: permitir TCP **4000** y **3001**.

Registrar en CT Connect: **No. de cliente** + **IP fija** `2.25.78.222` (correo a ecom@ctin.com.mx).

## Variables `.env`

```env
WHOLESALER_DEMO_OFFERS=false
WHOLESALER_COMPARE_CODES=CT

# Producción (responde desde VPS; api.ctonline.mx:3001 suele timeout)
WHOLESALER_CT_BASE_URL=http://connect.ctonline.mx:3001
# Alternativa HTTPS si CT abre whitelist: https://api.ctonline.mx:3001
# Sandbox: https://api.ctonline.mx:4000

WHOLESALER_CT_API_KEY=              # token x-auth
# O token automático:
# WHOLESALER_CT_EMAIL=
# WHOLESALER_CT_CLIENTE=
# WHOLESALER_CT_RFC=
WHOLESALER_CT_LOOKUP_PATH=/existencia/promociones/{part_number}
WHOLESALER_CT_SOURCE_IP=2.25.78.222
WHOLESALER_CT_TIMEOUT=20
```

## Catálogo FTP (mapeo numParte → clave CT)

```env
WHOLESALER_CT_FTP_HOST=216.70.82.104
WHOLESALER_CT_FTP_USER=PAZ0074
WHOLESALER_CT_FTP_PASSWORD=
WHOLESALER_CT_FTP_PATH=/catalogo_xml/productos.json
WHOLESALER_CT_FTP_EXTRA_PATHS=/catalogo_xml/productos_especiales_PAZ0074.xml
WHOLESALER_CT_CATALOG_TTL=15
```

Si la consulta por part number del fabricante no trae precio, el conector resuelve la `clave` CT vía este JSON (+ XML de especiales) y vuelve a consultar la API.

El catálogo **especiales** (`productos_especiales_PAZ0074.xml`) se fusiona con el principal. También hay copia local en `docs/integraciones/catalogo_xml/` y `storage/app/ct-catalog/` como respaldo.

### Instrucciones que debe cumplir el contenedor `api` (Docker)

La API CT **no tiene** búsqueda/autocomplete. Docker (`api` / `queue`) debe:

1. **Descargar y cachear** el catálogo FTP (`productos.json`) con `WHOLESALER_CT_FTP_*` y `WHOLESALER_CT_CATALOG_TTL`.
2. **Autocomplete** (`GET /api/mayoristas/ct/autocomplete`):
   - Solo `sku` **o** solo `descripcion` → buscar en el catálogo por ese campo.
   - **Ambos** con texto usable (≥2) → devolver solo productos que coincidan en **los dos** (AND, mismo peso). Clase: `CtCatalogIndex::searchMatching`.
   - Ejemplo: `sku=CARBRT3` + `descripcion=Tóner BROTHER TN630` → `CARBRT2300` (no `CARBRT340` / TN15).
3. **Lookup de precio** (`GET /existencia/promociones/{clave}`): usar clave CT resuelta; candidatos vía `candidateClaves`.
4. **≥3 coincidencias de catálogo** para un SKU incompleto → consultar precio/existencia de **todas** y devolverlas como ofertas CT.
5. **n8n comparador** solo recibe `part_number`; la resolución SKU/descripción ocurre en Laravel dentro de Docker, no en n8n.

Código de referencia:

| Pieza | Archivo |
|-------|---------|
| Índice / AND search | `app/Services/Wholesalers/CtCatalogIndex.php` |
| Conector multi-match | `app/Services/Wholesalers/Connectors/CtInternacionalConnector.php` |
| Endpoint | `MayoristaController::autocompleteCt` |
| SPA | `frontend/src/components/quotes/CtSkuAutocompleteInput.tsx` |

Probar dentro de Docker:

```bash
docker compose exec -T api php artisan tinker --execute="
\$c = app(App\Services\Wholesalers\CtCatalogIndex::class);
print_r(\$c->searchMatching('CARBRT3', 'Tóner BROTHER TN630', 5));
"
```

### FTP incompleto vs API

El FTP a veces omite artículos que sí existen en la web/API (ej. Tinta HP 662: modelo `CZ103AL` → clave `CARHPP2110`). Mitigaciones:

1. **Aliases** — `config/ct_part_aliases.php` o `WHOLESALER_CT_PART_ALIASES=CZ103AL:CARHPP2110`
2. **UPC** — `php artisan wholesalers:ct-catalog-gaps --sync-upc` indexa UPC→clave de productos solo-API
3. Pedir a CT el catálogo FTP completo (mismo set que la tienda)

## IP dedicada

Registrar **2.25.78.222** (VPS) en el panel CT.

## Aplicar credenciales

```powershell
copy scripts\.wholesaler-ct.local.example scripts\.wholesaler-ct.local
# Editar scripts/.wholesaler-ct.local
.\scripts\apply-ct-credentials.ps1 -TargetVps
```

## Prueba

```bash
php artisan wholesalers:test-ct ACCBLC010
docker compose -f docker-compose.yml -f docker-compose.staging.yml exec -T api php artisan wholesalers:test-ct ACCBLC010
```

## Respuesta mapeada

El conector `CtInternacionalConnector` usa `GET /existencia/promociones/{codigo}` y mapea:

| CT | Comparador |
|----|------------|
| `precio` / `promocion.precio` | `cost` (MXN; USD × tipo de cambio) |
| suma `almacenes` | `stock` |
| almacén con más stock | `warehouse` legible (ej. `Guadalajara (13A)`) vía `config/ct_warehouses.php` |

## n8n comparador

Tras actualizar `docs/n8n/comparador-precios.workflow.json`, reimportar en n8n UI.
Con `WHOLESALER_DEMO_OFFERS=false` y `WHOLESALER_COMPARE_CODES=CT` el comparador
solo consulta CT (sin Ingram/Exel inventados). En VPS: `.\scripts\vps-disable-demo-wholesalers.ps1`.
