# Reporte de mejoras: flujo de atención de Compras

Fecha: 22 de septiembre de 2026
Sistema: Cotizaciones Exacto

## Problema atendido

Las solicitudes de cotización llegaban a Compras, pero no existía un responsable visible. Dos compradores podían comenzar a trabajar la misma solicitud y los avisos no indicaban si ya estaba siendo atendida. Tampoco había escalamiento cuando nadie la tomaba.

## Cambios implementados

1. **Tomar solicitud**
   - Se agregó el botón “Tomar solicitud” en el detalle de una cotización en estado `Solicitud de cotización`.
   - La toma se ejecuta dentro de una transacción con bloqueo para impedir que dos compradores la tomen simultáneamente.

2. **Responsable visible**
   - Se registra comprador responsable, usuario que asignó, fecha de toma y estado de atención.
   - El dashboard muestra `Disponible`, `En atención` o `Atendida`.
   - Se puede liberar una solicitud para que otro comprador la tome.

3. **Protección al editar**
   - Un comprador debe tomar la solicitud antes de editarla.
   - Si otro comprador ya la tomó, el sistema bloquea el guardado y muestra quién la está atendiendo.
   - La actividad real continúa registrándose mediante `last_activity_at` y `last_activity_by`; abrir la cotización no reinicia el contador.

4. **Cierre de avisos**
   - Al avanzar la cotización fuera de `Solicitud de cotización`, los avisos de Compras se marcan como atendidos.
   - La bitácora registra el cierre y el estado al que avanzó.

5. **Bitácora**
   - Tomar y liberar una solicitud genera una entrada interna con autor y fecha.
   - Los guardados y cambios de estado conservan sus tareas e historial existentes.

6. **Escalamiento**
   - Las solicitudes sin responsable que superan el umbral configurado se marcan como urgentes.
   - Administración recibe un aviso individual.
   - El proceso es idempotente y no duplica avisos.

7. **Dashboard y campana**
   - El dashboard integra solicitudes pendientes junto con las alertas de “Sin avance”.
   - La campana mantiene avisos individuales por comprador, incluso si no estaba conectado cuando se generaron.

## Automatizaciones activas

- Cada 5 minutos: recuperación de avisos faltantes para solicitudes pendientes.
- Cada hora: escalamiento de solicitudes antiguas sin responsable.

## Verificación realizada

- 309 pruebas backend ejecutadas correctamente.
- 1,424 aserciones verificadas.
- Build de React/Vite completado correctamente.
- Migración aplicada en VPS:
  `2026_09_22_120000_add_purchase_attention_to_quotes`.
- API, frontend y scheduler activos en producción.
- URL de producción respondió HTTP 200.

## Resultado en producción

Al momento del despliegue:

- 7 solicitudes en estado `Solicitud de cotización`.
- 6 ya escaladas por antigüedad.
- 1 disponible para ser tomada por Compras.
- 12 avisos urgentes creados para Administración.

## Prueba manual recomendada

1. Crear una solicitud con Ventas.
2. Entrar con dos cuentas de Compras.
3. Confirmar que ambas reciben el aviso.
4. Tomarla con la primera cuenta.
5. Confirmar que la segunda ve “En atención por…” y no puede editarla.
6. Guardar un cambio con la primera cuenta.
7. Avanzar el estado y verificar que los avisos quedan atendidos.
8. Confirmar en la bitácora quién tomó, editó y avanzó la cotización.
