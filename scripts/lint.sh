#!/usr/bin/env bash
# Syntax check every PHP file under momobird_ai/:
#   1. inside php:7.4-cli (the oldest supported PHP) via docker,
#   2. with the host php and error_reporting=E_ALL so deprecations show.
set -euo pipefail
cd "$(dirname "$0")/.."

files=$(find momobird_ai -name '*.php' | sort)
if [ -z "$files" ]; then
  echo "no PHP files under momobird_ai/" >&2
  exit 1
fi

echo "== php 7.4 (docker) =="
docker run --rm -v "$PWD":/w -w /w php:7.4-cli sh -c \
  'find momobird_ai -name "*.php" | sort | while read -r f; do php -l "$f" || exit 1; done'

echo "== host php $(php -r 'echo PHP_VERSION;') (E_ALL) =="
status=0
while IFS= read -r f; do
  out=$(php -d error_reporting=-1 -d display_errors=1 -l "$f" 2>&1) || status=1
  echo "$out"
  # php -l exits 0 on deprecations; fail on anything other than the OK line.
  if [ "$out" != "No syntax errors detected in $f" ]; then status=1; fi
done <<< "$files"

if [ "$status" -ne 0 ]; then
  echo "LINT FAIL" >&2
  exit 1
fi
echo "LINT PASS"
