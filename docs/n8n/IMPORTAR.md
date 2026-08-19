# Importar workflow n8n — Lectura cotización

## Antes de cualquier comando

**Siempre** abre PowerShell en la carpeta del proyecto:

```powershell
cd c:\laragon\www\cotizacion
```

Si ves `PS C:\WINDOWS\system32>` estás en la carpeta **equivocada** y los scripts no existen ahí.

---

## Workflow actualizado (jun 2026)

Flujo: **Webhook → Pasar archivo → Docling → Extraer markdown → Laravel `/api/solicitudes/interpretar` → Normalizar interpretar → Lineas OK? → Resolver marcas → Callback `/api/n8n/lectura`**

> **Importante:** no dejes dos workflows activos con el mismo path `lectura-docling`. Si importaste varias veces, en n8n desactiva o archiva el workflow viejo (sin nodo **Pasar archivo**). Solo debe quedar **uno** publicado.

- **Laravel interpretar** aplica `SolicitudLineasValidator` (plantilla Excel **4 columnas** requeridas: CANTIDAD, PRODUCTO, NO.PARTE, MARCA; UNIDAD opcional → `pza`). Si falla → 422 y solicitud en `error`.
- **Resolver marcas** llama `POST /api/marcas/resolver` (catálogo `config/brands.php`, sin LLM).
- Debes enviar `request_id` desde Laravel al webhook (`via=n8n`).
- Reimporta `docs/n8n/lectura-cotizacion.workflow.json` y **Publica** el workflow.

---

## Paso 2 — Probar lectura (sin n8n) ← empieza aquí

> Si en un chat antiguo ves `preparar-prueba-lectura.ps1` o `tu-archivo.pdf`, **ignóralo**. Usa esto:

1. **Laragon** → **Start All**
2. Ejecuta **`scripts\lectura-up.cmd`** (Docker Docling + n8n)
3. **Doble clic** en **`scripts\preparar-prueba-lectura.cmd`**

O en PowerShell (nota: termina en **`.cmd`**, no `.ps1`):

```powershell
cd c:\laragon\www\cotizacion
.\scripts\preparar-prueba-lectura.cmd
```

Al terminar debe decir **`HTTP 200 - OK`** y **`Termino OK.`** (tarda ~1 min la primera vez).

**Con tu PDF:** copia la ruta real del Explorador y:

```powershell
.\scripts\test-solicitud-lectura.cmd "C:\Users\xblack\Downloads\NOMBRE-REAL.pdf"
```

**No uses** `tu-archivo.pdf` ni `C:\ruta\a\...` — son placeholders y el script los rechaza.

---

## 1. Importar

1. Abre **http://localhost:5678**
2. Menú **⋯** → **Import from file**
3. Elige: `docs/n8n/lectura-cotizacion.workflow.json`
4. **Save**
5. Activa el workflow (**Active** / verde)

### Checklist post-import

1. Workflow **Active** (interruptor verde) + **Save**
2. Path del webhook: `lectura-docling`
3. `.env` con `N8N_WEBHOOK_URL=http://localhost:5678/webhook/lectura-docling`
4. Laragon **Start All** + `scripts\lectura-up.cmd` (Docling + n8n)
5. Verificación automatizada:

```powershell
cd c:\laragon\www\cotizacion
php artisan n8n:verify-lectura
```

Debe terminar con **Verificación n8n lectura OK**. Si falla con 404, reimporta el JSON y activa el workflow.

6. Prueba end-to-end:

```powershell
.\scripts\test-solicitud-lectura.cmd "C:\ruta\real\archivo.pdf" n8n
```

Debe devolver **HTTP 202**; el detalle en la SPA hace poll hasta `procesada`.

URL del webhook (producción):

```
http://localhost:5678/webhook/lectura-docling
```

En `.env`:

```env
N8N_WEBHOOK_URL=http://localhost:5678/webhook/lectura-docling
```

---

## Spinner naranja / «no termina de cargar»

**Causa:** el Webhook estaba en **«When last node finishes»** y Docling tarda varios minutos.

**Solución:**

1. Abre el nodo **Webhook**
2. **Respond** → elige **Immediately** (o «When webhook is called» / `onReceived`)
3. **Save** el workflow

O re-importa el JSON actualizado (`responseMode: onReceived`).

Mientras pruebas, usa **Docling directo** (no espera n8n):

**Doble clic** (recomendado en Windows):

```
scripts\preparar-prueba-lectura.cmd
```

O en PowerShell:

```powershell
cd c:\laragon\www\cotizacion
.\scripts\preparar-prueba-lectura.cmd
```

Para ver si n8n sigue trabajando: **Executions** (ejecuciones) en el menú izquierdo.

---

## 2. Si el nodo «Docling convert» falla

Error típico: *binary file 'archivo', but none was found*.

**Causas:**

1. **Prueba sin archivo** (ping, Postman solo JSON) → normal que falle en Docling. Sube un archivo real desde la app o `curl -F archivo=@ruta.pdf`.
2. **Falta el nodo Pasar archivo** entre Webhook y Docling → reimporta el JSON actualizado.
3. **URL incorrecta** en Docling → debe ser `http://docling-serve:5001/v1/convert/file` (**serve**, no `service`).

Abre **Docling convert** y verifica:

- URL exacta: `http://docling-serve:5001/v1/convert/file`
- Body: **multipart/form-data**
- Campo binario: nombre `files`, archivo desde campo **`archivo`** del item anterior

---

## 3. Si «Laravel interpretar» falla (rojo)

Abre el nodo y revisa la respuesta. Causas frecuentes:

| HTTP / mensaje | Qué hacer |
|----------------|-----------|
| **422** — *El archivo no cumple el formato requerido* | Excel debe tener **4 columnas** requeridas: CANTIDAD, PRODUCTO, NO.PARTE, MARCA. **UNIDAD** es opcional (default `pza`). Revisa la solicitud en la app (estado **error**). |
| **422** — *No se detectaron líneas* | Docling no extrajo tabla; revisa el nodo **Extraer markdown** (`markdown` vacío). |
| **Connection refused** | Laragon **Start All**; URL `http://host.docker.internal/...` + header `Host: cotizacion.test`. |

El workflow actualizado usa **neverError** en interpretar y el nodo **Lineas OK?** para no romper el flujo cuando la validación rechaza el archivo.

---

## 4. Si «Laravel callback» falla

Cambia la URL a una de estas (según tu Laragon):

- `http://host.docker.internal/api/n8n/lectura` (recomendado, n8n en Docker)
- Si no funciona: `http://172.17.0.1/api/n8n/lectura` (IP gateway Docker en Windows)

Laragon debe estar **Start All**.

---

## 5. Probar sin n8n (Docling directo desde Laravel)

**No copies** rutas de ejemplo del manual (`C:\ruta\...`, `tu-archivo.pdf`) — no existen.

**Opción A — un clic (recomendado):**

1. Laragon → **Start All**
2. `scripts\lectura-up.cmd` (Docker Docling + n8n)
3. Doble clic en **`scripts\preparar-prueba-lectura.cmd`**

**Opción B — PowerShell:**

```powershell
cd c:\laragon\www\cotizacion
.\scripts\preparar-prueba-lectura.cmd
```

**Opción C — tu archivo** (ruta real, copiada del Explorador):

```powershell
.\scripts\test-solicitud-lectura.cmd "C:\Users\xblack\Downloads\mi-solicitud.pdf"
```

Si PowerShell bloquea `.ps1`, usa siempre los `.cmd` (llevan `-ExecutionPolicy Bypass`).

O Postman: `POST http://cotizacion.test/api/solicitudes/lectura`  
Body: **form-data** → `archivo` = tu PDF.

---

## 5. Probar con n8n

1. **Docker Desktop** abierto → `scripts\lectura-up.cmd` (o doble clic)
2. Abre http://localhost:5678 — si no carga, n8n no está arriba
3. Workflow **Active** (interruptor verde) + **Save**

**Atajo desde la raíz del proyecto:** doble clic en `probar-n8n.cmd`  
(o con tu PDF: `probar-n8n.cmd "C:\Users\xblack\Downloads\OS-2026-001.pdf"`)

**Antes de probar:** el workflow debe estar **Active**. Si no, Laravel devuelve error *webhook not registered*.

Comprueba el webhook (debe ser **HTTP 200**, no 404):

```powershell
curl.exe -sS -o NUL -w "HTTP:%{http_code}`n" -X POST "http://localhost:5678/webhook/lectura-docling" -F "archivo=@C:\ruta\real.pdf;type=application/pdf"
```

Luego:

```powershell
.\scripts\test-solicitud-lectura.cmd "C:\Users\xblack\Downloads\OS-2026-001.pdf" n8n
```

Debe decir **HTTP 202** y *Archivo enviado a n8n*. El procesamiento sigue en **Executions** en n8n (1–2 min).

Revisa `storage/logs/laravel.log` → `Lectura recibida desde n8n` cuando termine el flujo.

### Error HTTP 500 `{"message":"Error in workflow"}`

El webhook **ya está publicado** (bien). Falla un nodo **dentro** del flujo.

1. En n8n → pestaña **Ejecuciones** (arriba al centro)
2. Abre la ejecución **fallida** (roja)
3. El nodo rojo suele ser **Devolución de llamada de Laravel**

**Arreglo (nodo Laravel callback):**

1. Clic en **Devolución de llamada de Laravel** (último nodo)
2. **Send Headers** → activado
3. Añade cabecera:
   - Name: `Host`
   - Value: `cotizacion.test`
4. URL: `http://host.docker.internal/api/n8n/lectura`
5. **Save** + **Publicar** otra vez
6. Repite el `curl` — debe dar **HTTP:200**

(O re-importa `docs/n8n/lectura-cotizacion.workflow.json` — ya trae el header `Host`.)

---

### Error HTTP 503 «webhook not registered»

1. Abre http://localhost:5678  
2. Workflow **Lectura cotizacion** → interruptor **Active** (verde)  
3. **Save**  
4. Nodo Webhook → path debe ser `lectura-docling`  
5. Vuelve a ejecutar el script con `n8n`
