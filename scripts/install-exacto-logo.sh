#!/usr/bin/env bash
# Instala logo Exacto en storage público y enlaza app_settings.logo_path
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
mkdir -p "$ROOT/storage/app/public/company"
SRC="$ROOT/resources/branding/exacto-logo.png"
DEST="$ROOT/storage/app/public/company/logo.png"
if [[ ! -f "$SRC" ]]; then
  echo "Missing $SRC" >&2
  exit 1
fi
cp -f "$SRC" "$DEST"
echo "Copied logo to $DEST"

# Optional: update DB when running inside api container context
if command -v php >/dev/null 2>&1 && [[ -f "$ROOT/artisan" ]]; then
  php "$ROOT/artisan" tinker --execute="
    \$s = App\Models\AppSetting::current();
    \$s->logo_path = 'company/logo.png';
    \$s->save();
    echo 'logo_path='.\$s->logo_path.PHP_EOL;
  " 2>/dev/null || true
fi
