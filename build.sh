#!/usr/bin/env bash
# Construye ahm-connect.zip con la carpeta ahm-connect/ en la raíz, que es lo
# que espera el instalador de plugins de WordPress.
set -euo pipefail

cd "$(dirname "$0")"

VERSION_HEADER=$(grep -m1 '^ \* Version:' ahm-connect.php | tr -d ' ' | cut -d: -f2)
VERSION_CONST=$(grep -m1 "define( 'RMAI_VERSION'" ahm-connect.php | sed -E "s/.*'([0-9.]+)'.*/\1/")
VERSION_JSON=$(grep -m1 '"version"' ahm-connect.json | sed -E 's/.*"version"[^"]*"([^"]+)".*/\1/')

if [ "$VERSION_HEADER" != "$VERSION_CONST" ] || [ "$VERSION_HEADER" != "$VERSION_JSON" ]; then
    echo "Las versiones no coinciden:" >&2
    echo "  cabecera Version: $VERSION_HEADER" >&2
    echo "  RMAI_VERSION:     $VERSION_CONST" >&2
    echo "  ahm-connect.json: $VERSION_JSON" >&2
    exit 1
fi

php -l ahm-connect.php > /dev/null

BUILD=$(mktemp -d)
trap 'rm -rf "$BUILD"' EXIT

mkdir -p "$BUILD/ahm-connect"
cp ahm-connect.php "$BUILD/ahm-connect/"

mkdir -p dist
rm -f dist/ahm-connect.zip
(cd "$BUILD" && zip -qr - ahm-connect) > dist/ahm-connect.zip

echo "ahm-connect.zip listo — v$VERSION_HEADER ($(du -h dist/ahm-connect.zip | cut -f1))"
