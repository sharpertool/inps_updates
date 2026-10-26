#!/bin/bash
# Pull PHP/helper files published for INPS into ~/php_files, verifying sha256.
# Usage: bash ~/bin/getfiles.sh            (pull everything in the manifest)
#        bash ~/bin/getfiles.sh name.php   (pull one file)
set -u
BASE="https://raw.githubusercontent.com/sharpertool/inps_updates/main"
DEST="$HOME/php_files"
mkdir -p "$DEST"
TS=$(date +%s)
M=$(curl -fsSL "$BASE/php_files/MANIFEST?t=$TS") || { echo "STOP: cannot fetch manifest"; exit 1; }
rc=0
while read -r sum name; do
  [ -z "${name:-}" ] && continue
  [ $# -gt 0 ] && [ "$1" != "$name" ] && continue
  tmp=$(mktemp)
  if ! curl -fsSL "$BASE/php_files/$name?t=$TS" -o "$tmp"; then echo "FAIL  $name (download)"; rc=1; rm -f "$tmp"; continue; fi
  got=$(sha256sum "$tmp" | cut -d' ' -f1)
  if [ "$got" != "$sum" ]; then echo "FAIL  $name (sha256 mismatch: got $got, want $sum; GitHub cache lag? retry in a few min)"; rc=1; rm -f "$tmp"; continue; fi
  [ -f "$DEST/$name" ] && cp -p "$DEST/$name" "$DEST/$name.prev"
  mv "$tmp" "$DEST/$name"; chmod 644 "$DEST/$name"
  echo "OK    $name  $(echo $got | cut -c1-12)"
done <<< "$M"
exit $rc
