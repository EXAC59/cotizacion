---
description: Revisa y corrige la lógica/problemas del proyecto (tests, bugs, warnings) de forma enfocada.
agent: build
---

Revisa la lógica del proyecto y corrige los problemas, de forma enfocada y con diffs mínimos:

1. Carga la skill correspondiente al área (cotizacion-check-robot, solicitudes-workflow, comparador-precios o rbac-permisos) según el alcance.
2. Ejecuta la suite de tests para detectar fallos (`/test`).
3. Analiza cada fallo: test → causa raíz → fix más pequeño sin tocar flujos que ya funcionan (regla `no-modificar-existente`).
4. Corrige y verifica corriendo el test puntual.
5. Si no hay fallos, haz una revisión rápida buscando warnings/errores obvios en el área pedida y propón mejoras sin modificar sin permiso.

Reporta: qué se encontró, qué se corrigió, qué quedó pendiente.

Argumentos: `$ARGUMENTS` — área específica o archivo sobre el que enfocarse.