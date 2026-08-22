#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUTPUT_PATH="${1:-"$ROOT_DIR/../bilal-store-production-$(date +%Y%m%d-%H%M%S).zip"}"
OUTPUT_PATH="$(python3 -c 'import os,sys; print(os.path.abspath(sys.argv[1]))' "$OUTPUT_PATH")"
mkdir -p "$(dirname "$OUTPUT_PATH")"

# Never let the archive ingest itself (e.g. OUTPUT placed inside the tree).
if [ "$OUTPUT_PATH" = "$ROOT_DIR" ] || [[ "$OUTPUT_PATH" == "$ROOT_DIR"/* ]]; then
    echo "Refusing OUTPUT_PATH inside the project tree: $OUTPUT_PATH" >&2
    exit 1
fi
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

FORBIDDEN_LIST="$(mktemp)"
trap 'rm -f "$FORBIDDEN_LIST"' EXIT
if unzip -Z1 "$OUTPUT_PATH" | grep -E '(^|/)\.git(/|$)|(^|/)\.env($|\.)|config\.local\.php|(^|/)(install|migrate)\.php$|\.(pem|key|p12|pfx|kdb|jks)$' >"$FORBIDDEN_LIST"; then
    echo "Forbidden files found in production package:" >&2
    cat "$FORBIDDEN_LIST" >&2
    rm -f "$OUTPUT_PATH"
    exit 1
fi

echo "Production package created: $OUTPUT_PATH"
echo "Files: $(unzip -Z1 "$OUTPUT_PATH" | wc -l | tr -d ' ')"
