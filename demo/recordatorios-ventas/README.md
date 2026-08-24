# Demo — Bandeja y recordatorios de ventas

Prototipo estático (HTML/CSS/JS). Contrato de producto: ver [REGLAS.md](REGLAS.md).

## Qué demuestra

- Avisos **Compras → Ventas** solo si:
  - **Lista / Terminada**, o
  - **En elaboración** con **3+ días** sin avance
- **Dedupe:** no hay segundo aviso no leído del mismo motivo
- Seguimiento: select **Negociación** (fecha ≥ hoy) / **Ganada** (factura 2–60) / **Perdida** (comentarios 3–500)
- Capas separadas: el workflow de cotización no se reemplaza

No usa Laravel/React. **No se sube al VPS** en esta fase.

## Cómo abrir

```bash
cd demo/recordatorios-ventas
python3 -m http.server 8765
```

Abre: [http://localhost:8765](http://localhost:8765)

## Guion

1. Compras → **Candidatas** (`0001`, `0002`); `0003` en Otras.
2. Avisar → campana Ventas con motivo; repetir aviso = “Ya hay un aviso pendiente”.
3. Ventas: select estatus; Negociación despliega fecha (≥ hoy).
4. Reagendar solo si la fecha venció.

**Restablecer demo** limpia `localStorage` (clave `v8`).

El **historial de seguimiento** se ve en cada tarjeta (resumen) y al inicio del panel de detalle.
## Historial y avisos cruzados

- **Ventas** y **Compras** ven el **historial de seguimiento** (quién marcó Negociación/Ganada/Perdida).
- Cuando ventas guarda un estatus, **Compras** recibe aviso en su campana (“María López marcó…”).
- En rol Ventas puedes cambiar el vendedor activo (María / Carlos) para simular distinto personal.
