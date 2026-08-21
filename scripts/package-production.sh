#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUTPUT_PATH="${1:-"$ROOT_DIR/../bilal-store-production-$(date +%Y%m%d-%H%M%S).zip"}"
OUTPUT_PATH="$(python3 -c 'import os,sys; print(os.path.abspath(sys.argv[1]))' "$OUTPUT_PATH")"
mkdir -p "$(dirname "$OUTPUT_PATH")"
rm -f "$OUTPUT_PATH"

# Build from the working tree while excluding source-control metadata, local
# secrets, development caches, one-time bootstrap utilities, and the packaging
# script itself. The developer's .git directory is never modified.
(
    cd "$ROOT_DIR"
    zip -qr "$OUTPUT_PATH" . \
        -x './.git' './.git/*' '*/.git/*' \
           './.env' './.env.*' '*/.env' '*/.env.*' \
           './config/config.local.php' '*/config.local.php' \
           '*.pem' '*.key' '*.p12' '*.pfx' '*.kdb' '*.jks' \
           '*.log' './node_modules/*' './.cache/*' './.arena/*' \
           './scripts/*' './tests/*' './admin/install.php' './admin/migrate.php' \
           './*.zip' './production-packages/*'
)

if unzip -Z1 "$OUTPUT_PATH" | grep -E '(^|/)\.git(/|$)|(^|/)\.env($|\.)|config\.local\.php|(^|/)(install|migrate)\.php$|\.(pem|key|p12|pfx|kdb|jks)$' >/tmp/production-package-forbidden.txt; then
    echo "Forbidden files found in production package:" >&2
    cat /tmp/production-package-forbidden.txt >&2
    rm -f "$OUTPUT_PATH"
    exit 1
fi

echo "Production package created: $OUTPUT_PATH"
echo "Files: $(unzip -Z1 "$OUTPUT_PATH" | wc -l | tr -d ' ')"
