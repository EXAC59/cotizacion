# Docling + parser heurístico — lectura local (§2.2)

Motor **Docling** (PDF, Excel, Word + tablas) con **OCR PP-OCR** (RapidOCR, familia Paddle) integrado en Docling. La interpretación de líneas la hace **`LecturaLineParser`** en Laravel (sin LLM ni OpenAI).

## Qué se agregó al proyecto

| Archivo | Uso |
|---------|-----|
| `docker-compose.lectura.yml` | Solo Docling + n8n (ideal con **Laragon**) |
| `docker-compose.yml` | Stack completo + servicio `docling-serve` |
| `config/docling.php` | URL y opciones OCR |
| `app/Services/DoclingClient.php` | Cliente HTTP desde Laravel |
| `scripts/test-docling.ps1` | Prueba rápida desde PowerShell |

---

## Paso 1 — Docker Desktop

Abre **Docker Desktop** y espera a que diga *Running*.

---

## Paso 2 — Levantar Docling + n8n

### Con Docker Desktop (recomendado)

PowerShell en la carpeta del proyecto:

```powershell
cd c:\laragon\www\cotizacion
.\scripts\lectura-up.ps1
```

O manualmente:

```powershell
docker compose -f docker-compose.lectura.yml up -d
```

> **Si sale:** `docker no se reconoce` → **no tienes Docker instalado**. Ve a la sección [Sin Docker](#sin-docker) abajo.

Primera vez: descarga ~4–5 GB. Puede tardar 10–20 min.

### Sin Docker

Si no quieres o no puedes instalar Docker:

1. **Node.js** (ya lo tienes) → n8n:
   ```powershell
   cd c:\laragon\www\cotizacion
   .\scripts\start-n8n.ps1
   ```
   Deja esa ventana abierta. Abre http://localhost:5678

2. **Python 3.10+** → Docling:
   - Instala: https://www.python.org/downloads/ (marca **Add python.exe to PATH**)
   - Luego:
     ```powershell
     pip install "docling-serve[ui]"
     .\scripts\start-docling.ps1
     ```
   - Otra ventana PowerShell. Abre http://localhost:5001/ui

Guía rápida: `.\scripts\lectura-up-sin-docker.ps1`

En n8n, la URL de Docling será `http://127.0.0.1:5001` (no `docling-serve`, eso solo aplica dentro de Docker).

Comprueba contenedores:

```powershell
docker ps --filter name=cotizacion_
```

Debes ver `cotizacion_docling` y `cotizacion_n8n`.

---

## Paso 3 — Variables en `.env`

Añade o actualiza en `c:\laragon\www\cotizacion\.env`:

```env
DOCLING_BASE_URL=http://127.0.0.1:5001
DOCLING_TIMEOUT=180
DOCLING_DO_OCR=true
DOCLING_OCR_LANGS=es,en
DOCLING_TABLE_MODE=accurate
DOCLING_PORT=5001

N8N_BASE_URL=http://localhost:5678
N8N_WEBHOOK_URL=http://localhost:5678/webhook/lectura-docling
N8N_WEBHOOK_BASE=http://localhost:5678
N8N_TIMEOUT=120
```

Luego:

```powershell
php artisan config:clear
php artisan docling:ping
```

Debe decir **Docling OK**.

También: **http://cotizacion.test/api/health** → `services.docling.ok: true`.

---

## Paso 4 — Probar Docling en el navegador

1. Abre **http://localhost:5001/ui**
2. Sube un PDF o Excel de solicitud de cotización
3. Salida: **Markdown**
4. PDF escaneado: OCR **On** | Excel/Word: OCR **Off**

---

## Paso 5 — Probar desde Laravel (opcional)

**Prueba automática (recomendado):** doble clic en `scripts\preparar-prueba-lectura.cmd`

O PowerShell:

```powershell
cd c:\laragon\www\cotizacion
.\scripts\preparar-prueba-lectura.cmd
```

Con tu archivo:

```powershell
.\scripts\test-solicitud-lectura.cmd "C:\Users\TU_USUARIO\Downloads\solicitud.pdf"
```

Artisan (ruta real):

```powershell
php artisan docling:convert "C:\ruta\real\solicitud.pdf"
```

---

## Paso 6 — Workflow n8n

1. Abre **http://localhost:5678** (crea usuario la primera vez)
2. **New workflow** → nombre: `Lectura cotización`
3. Añade nodos:

### Nodo 1 — Webhook

- **HTTP Method:** POST  
- **Path:** `lectura-docling`  
- **Response:** When last node finishes  

URL de producción: `http://localhost:5678/webhook/lectura-docling`

### Nodo 2 — HTTP Request (Docling)

- **Method:** POST  
- **URL:** `http://docling-serve:5001/v1/convert/file`  
- **Send Body:** multipart-form-data  
- **Body parameters:**
  - `files` → binary → campo del webhook con el archivo (ajusta según payload)
  - `to_formats` = `md`
  - `do_ocr` = `true` (PDF) o `false` (Excel)
  - `ocr_lang` = `es`
  - `table_mode` = `accurate`

> En pruebas manuales, usa la UI de Docling primero. El webhook con archivo binario se afina cuando Laravel suba el archivo.

### Nodo 3 — Code (normalizar)

Ejemplo mínimo — extrae markdown y líneas tipo `5 Producto SKU`:

```javascript
const md = $json.document?.md_content ?? $json.md_content ?? '';
const lineas = md.split('\n')
  .map(l => l.trim())
  .filter(Boolean)
  .map((line, i) => {
    const m = line.match(/^(\d+)\s+(.+)$/);
    return {
      quantity: m ? Number(m[1]) : 1,
      product: m ? m[2] : line,
      partNumber: `LINE-${i + 1}`,
    };
  });

return [{ json: { markdown: md, lineas } }];
```

### Nodo 4 — HTTP Request (Laravel)

- **Method:** POST  
- **URL:** `http://host.docker.internal/api/n8n/lectura`  
  (Laragon en Windows; n8n va dentro de Docker)  
- **JSON body:**

```json
{
  "fuente": "upload",
  "tipo": "solicitud_cotizacion",
  "contenido": {
    "lineas": "={{ $json.lineas }}",
    "markdown_raw": "={{ $json.markdown }}"
  }
}
```

4. **Save** → activa el workflow (toggle **Active**)

---

## Paso 7 — Verificar callback Laravel

Revisa `storage/logs/laravel.log` — debe aparecer `Lectura recibida desde n8n`.

---

## Comandos útiles

```powershell
# Levantar solo lectura (Laragon)
docker compose -f docker-compose.lectura.yml up -d

# Detener
docker compose -f docker-compose.lectura.yml down

# Logs Docling
docker logs -f cotizacion_docling

# Stack Docker completo (API + frontend + todo)
docker compose up -d
make lectura-up   # alias lectura
```

---

## Regla OCR (híbrido Docling + PP-OCR)

| Archivo | `do_ocr` |
|---------|----------|
| `.xlsx`, `.docx` | `false` |
| PDF con texto seleccionable | `false` |
| PDF escaneado / imagen | `true` |

---

## API Laravel (listo)

| Método | Ruta | Uso |
|--------|------|-----|
| POST | `/api/solicitudes/lectura` | Sube PDF/Excel/Word |
| POST | `/api/solicitudes/lectura?via=docling` | Docling directo (default) |
| POST | `/api/solicitudes/lectura?via=n8n` | Envía a webhook n8n |

Prueba:

```powershell
.\scripts\preparar-prueba-lectura.cmd
```

Workflow n8n: importar `docs/n8n/lectura-cotizacion.workflow.json` — ver [n8n/IMPORTAR.md](./n8n/IMPORTAR.md).

## Plantilla Excel (4 columnas requeridas)

Para archivos **Excel** (`.xlsx` / `.xls`), el markdown de Docling debe incluir estas columnas en la cabecera:

| CANTIDAD | PRODUCTO | NO.PARTE | MARCA |

Columna opcional:

| UNIDAD | → si falta o viene vacía, se usa `pza` |

Si falta alguna de las 4 requeridas → **HTTP 422** con mensaje explícito. Cada fila válida exige:

- **PRODUCTO**: descripción real (mín. 5 caracteres), no solo SKU.
- **NO.PARTE**: valor no vacío; no se aceptan `LINE-N` autogenerados en Excel.
- **CANTIDAD**: numérica &gt; 0.
- **UNIDAD**: opcional; celda vacía → default `pza`.
- **MARCA**: puede venir vacía → se resuelve después (heurística + lookup).

Si **0 líneas válidas** o **más del 50% de filas inválidas** → se rechaza el archivo completo.

Respuesta 422 de ejemplo:

```json
{
  "message": "El archivo no cumple el formato requerido.",
  "errors": {
    "columnas_faltantes": ["MARCA"],
    "lineas_invalidas": 3,
    "ejemplo": "Fila sin NO.PARTE"
  }
}
```

PDF y Word usan reglas más flexibles (sin exigir las 4 columnas de plantilla Excel).

---

## Tipo de archivo automático

En **Nueva solicitud** ya no hay selector manual de tipo. Al elegir archivo se muestra un badge, p. ej. *Tipo detectado: Excel (.xlsx)*. El backend deriva `source` por extensión.

---

## Marcas (sin LLM)

1. **Parser** (`guessBrand`) — heurísticas en texto del producto.
2. **`POST /api/marcas/resolver`** — catálogo `config/brands.php` para líneas con `Genérico`.
3. **n8n** — nodo *Resolver marcas* entre interpretar y callback (reimportar workflow).

Líneas no resueltas quedan `Genérico` (editables en detalle).

---

## Paso 7 — Frontend (Nueva solicitud)

La pantalla **Solicitudes → Nueva solicitud** (`RequestNewPage.tsx`) usa siempre **n8n + Docling** (async):

| Modo | Parámetro | Comportamiento |
|------|-----------|----------------|
| Async (n8n) | `via=n8n` | HTTP 202 → poll en detalle hasta `procesada` o `error` |
| Síncrono (Docling) | `via=docling` | Solo vía API/scripts; OCR + parser en la misma petición |

```
POST /api/solicitudes/lectura  (via=docling|n8n)
```

Requisitos: Laragon **Start All**, Docling en `http://localhost:5001`, frontend con proxy `/api` → `cotizacion.test` (Vite dev) o build en `/spa/`.

**Opción A — doble clic:** `scripts\frontend-dev.cmd`

**Opción B — PowerShell:**

```powershell
cd c:\laragon\www\cotizacion\frontend
npm run dev
```

> Si ves `vite no se reconoce`, estás en la carpeta equivocada. Debe ser `...\cotizacion\frontend`, no solo `...\cotizacion`.

Abre la app → **Nueva solicitud** → sube PDF/Excel/Word.

Ver también: [n8n-flujo-lectura.md](./n8n-flujo-lectura.md)

---

## Objetivo 1 cerrado (§2.2)

Arquitectura final:

```
Archivo → Validación plantilla (Excel 5 cols) → Docling (OCR) → LecturaLineParser
    → Validación por línea → MarcaResolver (Laravel) → [n8n lookup marcas] → BD → SPA
```

### Criterios de aceptación

- Subir PDF/xlsx/xls/docx → líneas en BD; detalle editable y guardable (`PUT /api/solicitudes/{id}/lineas`).
- CRUD clientes en API (`/api/clientes`); asignar `client_id` UUID en nueva solicitud.
- Modo n8n en UI: 202 → poll → `procesada` (workflow activo en `localhost:5678`).
- `php artisan test` — tests de parser y API.
- `php artisan n8n:verify-lectura` — webhook y Docling accesibles.
- Interpretación = **parser heurístico** (no LLM).
