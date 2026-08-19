---
description: Ejecuta los tests PHPUnit del proyecto (Unit y Feature) y reporta el resultado.
agent: build
---

Ejecuta la suite de tests de Laravel y reporta el resultado de forma concisa:

1. Navega a la raíz del proyecto.
2. Determina el binario de PHP: en Windows usa `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe`; en Linux usa `php`.
3. Ejecuta primero Unit: `php vendor/bin/phpunit --testsuite=Unit`.
4. Luego Feature: `php vendor/bin/phpunit --testsuite=Feature`.
5. Resume cuántos pasaron, cuántos fallaron y las causas exactas de cada fallo (test, archivo, línea y mensaje).
6. Si algo falla, propón el fix con el diff más pequeño y verifícalo corriendo el test puntual.

Argumentos opcionales: `$ARGUMENTS` (p. ej. un archivo de test concreto como `tests/Unit/CvaConnectorTest.php`). Si se provee, ejecuta solo ese archivo en vez de toda la suite.