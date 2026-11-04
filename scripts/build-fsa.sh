#!/usr/bin/env bash
# Builds resources/assets/fsa.min.js (the file the SDK serves at /_fsc/a/fsa.js) from resources/assets/fsa.js. Run after every change
# to fsa.js and commit both; tests/js/fsa.test.mjs runs every test against both and fails when the build is stale.
set -euo pipefail
cd "$(dirname "$0")/.."
SRC=resources/assets/fsa.js
OUT=resources/assets/fsa.min.js
HASH=$(shasum -a 256 "$SRC" | cut -d' ' -f1)
BODY=$(bun build "$SRC" --minify --target=browser --format=iife 2>/dev/null)
printf '/*! fsa.js sha256:%s */\n%s\n' "$HASH" "$BODY" > "$OUT"
echo "$OUT: $(wc -c < "$OUT") bytes, $(gzip -c "$OUT" | wc -c) gzipped"
