#!/usr/bin/env bash
# Build dist/discuz-momobird-ai-<version>.zip: one top-level momobird_ai/ directory with the
# plugin files only (no tests, dev, scripts or .superpowers), then check the archive:
# every entry under momobird_ai/, nothing but plugin file types, PHP files that pass php -l,
# and no connection key (mbk_...) or local test credential anywhere in it.
set -euo pipefail
cd "$(dirname "$0")/.."

VERSION=$(php -r '$j = json_decode(file_get_contents("momobird_ai/discuz_plugin_momobird_ai.json"), true); echo $j["Data"]["plugin"]["version"];')
[ -n "$VERSION" ] || { echo "no version in the manifest" >&2; exit 1; }
ZIP="dist/discuz-momobird-ai-$VERSION.zip"

STAGE=$(mktemp -d)
trap 'rm -rf "$STAGE"' EXIT
mkdir -p "$STAGE/momobird_ai" dist
# Only what Discuz! loads: PHP, manifests, the admin script and the directory guards.
(cd momobird_ai && find . -type f \( -name '*.php' -o -name '*.xml' -o -name '*.json' -o -name '*.js' -o -name 'index.htm' \) \
  ! -name '.*' -print0 | xargs -0 -I{} sh -c 'mkdir -p "$1/$(dirname "$2")" && cp "$2" "$1/$2"' _ "$STAGE/momobird_ai" {})
cp LICENSE "$STAGE/momobird_ai/LICENSE"

# Fixed timestamps and order so the same tree gives the same archive.
find "$STAGE/momobird_ai" -exec touch -t 202610290000 {} +
rm -f "$ZIP"
(cd "$STAGE" && find momobird_ai -print | LC_ALL=C sort | zip -q -X -D "$OLDPWD/$ZIP" -@)

# --- checks on the archive --------------------------------------------------------------------
fail() { echo "PACKAGE FAIL: $*" >&2; exit 1; }
LIST=$(unzip -Z1 "$ZIP")
echo "$LIST" | grep -v '^momobird_ai/' && fail "entries outside momobird_ai/"
echo "$LIST" | grep -E '(^|/)(tests|dev|scripts|\.superpowers|\.git|\.cache|dist)/' && fail "non-plugin directories"
echo "$LIST" | grep -E '\.(md|sh|log|env|zip|bak|orig)$|/\.' && fail "non-plugin files"
for f in momobird_ai/discuz_plugin_momobird_ai.xml momobird_ai/discuz_plugin_momobird_ai.json momobird_ai/momobird_ai.class.php \
  momobird_ai/install.php momobird_ai/uninstall.php momobird_ai/admincp.inc.php momobird_ai/cron/cron_momobird_ai.php momobird_ai/LICENSE; do
  echo "$LIST" | grep -qx "$f" || fail "missing $f"
done

CHECK=$(mktemp -d)
trap 'rm -rf "$STAGE" "$CHECK"' EXIT
unzip -q "$ZIP" -d "$CHECK"
# The only "mbk_" allowed is the literal key-format hint in the UI text and the redaction pattern;
# a key is mbk_ followed by 16 or more key characters.
if grep -rEn 'mbk_[A-Za-z0-9_-]{16,}' "$CHECK"; then fail "a connection key is in the archive"; fi
for secret in 'DISCUZLOCALTESTKEY' 'Admin#12345' 'Member#12345' 'it-root-pw' 'rootpw'; do
  if grep -rnF "$secret" "$CHECK"; then fail "test credential \"$secret\" is in the archive"; fi
done
while IFS= read -r f; do
  php -l "$f" >/dev/null || fail "php -l $f"
done < <(find "$CHECK" -name '*.php')

COUNT=$(echo "$LIST" | grep -vc '/$' || true)
SIZE=$(wc -c < "$ZIP" | tr -d ' ')
echo "PACKAGE PASS: $ZIP ($COUNT files, $SIZE bytes)"
