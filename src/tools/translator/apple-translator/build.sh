#!/usr/bin/env bash
# Compile apple-translator pour macOS 26+ (FoundationModels framework).
# Sortie : ./apple-translator (à côté de main.swift).
#
# Pré-requis : Xcode 26+ (swiftc avec -target macOS 26).
# Usage : ./build.sh && ./apple-translator <<< '{"text":"Bonjour","source":"fr","target":"en"}'
set -euo pipefail

cd "$(dirname "$0")"

SWIFTC="${SWIFTC:-swiftc}"
OUT="apple-translator"

# Cible macOS 26 minimum pour avoir FoundationModels.
MACOS_MIN=26.0

echo "→ Compilation de main.swift vers ./$OUT (cible macOS $MACOS_MIN+)..."
"$SWIFTC" \
    -parse-as-library \
    -O \
    -whole-module-optimization \
    -target "arm64-apple-macosx$MACOS_MIN" \
    -sdk "$(xcrun --show-sdk-path)" \
    main.swift \
    -o "$OUT"

chmod +x "$OUT"
echo "✓ Binaire : $(pwd)/$OUT"
echo
echo "Test rapide :"
echo '{"text":"Bienvenue sur mon blog.","source":"fr","target":"en"}' | "./$OUT"
echo
echo "À renseigner dans WP Admin → Dashboard → Traduction → Apple FoundationModels → chemin du binaire :"
echo "$(pwd)/$OUT"
