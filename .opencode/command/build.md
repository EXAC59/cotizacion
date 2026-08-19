---
description: Construye el SPA de producción (npm run build) y deja el artefacto en public/spa/.
agent: build
---

Construye el frontend SPA de producción:

1. Ve a `frontend/`.
2. Ejecuta `npm run build`.
3. Verifica que el build generó el hash nuevo en `public/spa/assets/`.
4. Si el comando falla, lee el error de Vite/TypeScript y corrígelo antes de dar por cerrado.
5. Al terminar recuerda al usuario recargar la SPA con **Ctrl+F5** (caché del SPA).

Argumentos opcionales: `$ARGUMENTS` — si contiene `lint`, ejecuta también `npm run lint` y corrige problemas de estilo.