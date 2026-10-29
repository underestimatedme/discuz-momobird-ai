#!/usr/bin/env bash
# Docker integration suite for the plugin on a real Discuz!:
#
#   scripts/integration.sh x35   Discuz! X3.5 (gitee tag v3.5-20260504), php:7.4-apache (mod_php)
#   scripts/integration.sh x5    Discuz! X5.0 (gitee MitFrame 992ace3), php:8.2-fpm + nginx
#
# Starts MariaDB 10.11, the Discuz! web server and dev/mock-connect.php (PHP built-in
# server in its own container, reached by its Docker host name), installs Discuz!
# without the wizard, installs the plugin, then runs scripts/integration/checks.php,
# which drives the forum over HTTP and checks what the mock received.
#
# Everything started here is removed on exit (MB_IT_KEEP=1 keeps it for debugging).
# Options (environment):
#   MB_IT_SRC    an existing checkout of the Discuz! source (the directory holding upload/)
#   MB_IT_CACHE  where sources are cloned (default .cache/discuz)
#   MB_IT_PORT   host port of the forum (default 18135 for x35, 18150 for x5)
#
# The admin account, the database password and the connection key below are local test
# values for these throwaway containers only.
set -euo pipefail
cd "$(dirname "$0")/.."
REPO=$PWD

SUITE=${1:-}
case "$SUITE" in
  x35)
    REF=v3.5-20260504; SHA=ccf55748e13c1a5497c08d6eb7b7f29b65a6d2a7; DEFAULT_PORT=18135 ;;
  x5)
    REF=MitFrame; SHA=992ace30db7c85163bd9ecf5aee076f812312d04; DEFAULT_PORT=18150 ;;
  *)
    echo "usage: scripts/integration.sh x35|x5" >&2; exit 2 ;;
esac

PORT=${MB_IT_PORT:-$DEFAULT_PORT}
P=mbit-$SUITE
NET=$P-net
SITE=http://localhost:$PORT
IMAGE=mbit-$SUITE
export IT_DB_PASSWORD='it-root-pw'
export IT_ADMIN_USER='admin'
export IT_ADMIN_PASSWORD='Admin#12345'
export IT_ADMIN_EMAIL='admin@example.test'
export IT_MEMBER_USER='member1'
export IT_MEMBER_PASSWORD='Member#12345'
export IT_KEY='mbk_DISCUZLOCALTESTKEYDISCUZLOCALTESTKEYDISC'

log() { printf '[%s %s] %s\n' "$SUITE" "$(date +%H:%M:%S)" "$*"; }

remove_all() {
  docker rm -f "$P-web" "$P-db" "$P-mock" >/dev/null 2>&1 || true
  docker network rm "$NET" >/dev/null 2>&1 || true
}
cleanup() {
  if [ "${MB_IT_KEEP:-0}" = "1" ]; then
    log "keeping containers ($P-web, $P-db, $P-mock) and network $NET"
    return
  fi
  remove_all
}
trap cleanup EXIT

# --- Discuz! source at the fixed version -------------------------------------------------
if [ -n "${MB_IT_SRC:-}" ]; then
  SRC=$MB_IT_SRC
else
  SRC=${MB_IT_CACHE:-$REPO/.cache/discuz}/$SUITE
  if [ ! -d "$SRC/.git" ]; then
    log "cloning Discuz! $REF"
    mkdir -p "$(dirname "$SRC")"
    for attempt in 1 2 3; do
      rm -rf "$SRC.tmp"
      if git clone -q --depth 20 --branch "$REF" https://gitee.com/Discuz/DiscuzX.git "$SRC.tmp"; then
        mv "$SRC.tmp" "$SRC"; break
      fi
      [ "$attempt" = 3 ] && { echo "could not clone Discuz! $REF" >&2; exit 1; }
      sleep 5
    done
  fi
  git -C "$SRC" checkout -q "$SHA"
fi
HEAD=$(git -C "$SRC" rev-parse HEAD 2>/dev/null || echo unknown)
if [ "$HEAD" != "$SHA" ]; then
  echo "Discuz! source at $HEAD, expected $SHA ($REF)" >&2; exit 1
fi
[ -f "$SRC/upload/forum.php" ] || { echo "no upload/ in $SRC" >&2; exit 1; }
log "Discuz! $REF ($SHA)"

# --- containers --------------------------------------------------------------------------
remove_all
docker build -q -t "$IMAGE" -f "scripts/integration/Dockerfile.$SUITE" scripts/integration >/dev/null
docker network create "$NET" >/dev/null
docker run -d --name "$P-db" --network "$NET" -e MARIADB_ROOT_PASSWORD="$IT_DB_PASSWORD" -e MARIADB_DATABASE=discuz mariadb:10.11 >/dev/null

docker create --name "$P-web" --network "$NET" -p "127.0.0.1:$PORT:80" -e IT_HTTP_HOST="localhost:$PORT" "$IMAGE" >/dev/null
docker cp "$SRC/upload/." "$P-web:/var/www/html/"
docker cp momobird_ai "$P-web:/var/www/html/source/plugin/momobird_ai"
docker cp scripts/integration "$P-web:/opt/it"
docker start "$P-web" >/dev/null
docker exec "$P-web" chown -R www-data:www-data /var/www/html

docker create --name "$P-mock" --network "$NET" \
  -e MOCK_KEY="$IT_KEY" -e MOCK_SITE_URL="$SITE" -e MOCK_STORE=/mock/store.json -e MOCK_LOG=/mock/requests.log \
  "$IMAGE" php -S 0.0.0.0:8080 /mock/mock-connect.php >/dev/null
docker cp dev/. "$P-mock:/mock"
docker start "$P-mock" >/dev/null

for _ in $(seq 1 60); do
  docker exec "$P-db" mariadb -uroot -p"$IT_DB_PASSWORD" -e 'SELECT 1' >/dev/null 2>&1 && break
  sleep 1
done
for _ in $(seq 1 30); do
  curl -s -o /dev/null "$SITE/robots.txt" && break
  sleep 1
done

# --- headless Discuz! install (installer XML mode, research §9) ---------------------------
log "installing Discuz!"
B="$SITE/install/index.php"
CURL=(curl -s -b 'LANG=SC_UTF8')
"${CURL[@]}" "$B?step=1&view_off=1" >/dev/null
"${CURL[@]}" -X POST "$B?step=3&method=db_init&view_off=1&install_ucenter=yes" \
  --data-urlencode "dbinfo[dbhost]=$P-db" --data-urlencode 'dbinfo[dbname]=discuz' --data-urlencode 'dbinfo[dbuser]=root' \
  --data-urlencode "dbinfo[dbpw]=$IT_DB_PASSWORD" --data-urlencode 'dbinfo[tablepre]=pre_' --data-urlencode "dbinfo[adminemail]=$IT_ADMIN_EMAIL" \
  --data-urlencode "admininfo[username]=$IT_ADMIN_USER" --data-urlencode "admininfo[password]=$IT_ADMIN_PASSWORD" \
  --data-urlencode "admininfo[password2]=$IT_ADMIN_PASSWORD" --data-urlencode "admininfo[email]=$IT_ADMIN_EMAIL" >/dev/null
docker exec "$P-web" test -f /var/www/html/config/config_global.php || { echo "installer wrote no config" >&2; exit 1; }
ALL=$(php -r '$a=array("dbhost"=>$argv[1],"dbuser"=>"root","dbpw"=>$argv[2],"dbname"=>"discuz","tablepre"=>"pre_","username"=>$argv[3],"password"=>$argv[4],"email"=>$argv[5],"dzucfull"=>true,"dzucstl"=>0,"uid"=>1,"myisam2innodb"=>""); echo rawurlencode(base64_encode(serialize($a)));' \
  "$P-db" "$IT_DB_PASSWORD" "$IT_ADMIN_USER" "$IT_ADMIN_PASSWORD" "$IT_ADMIN_EMAIL")
"${CURL[@]}" --max-time 600 "$B?method=do_db_init&view_off=1&allinfo=$ALL" >/dev/null
"${CURL[@]}" --max-time 600 "$B?method=do_db_data_init&view_off=1&allinfo=$ALL" >/dev/null
"${CURL[@]}" "$B?step=5&method=ext_info&view_off=1" >/dev/null
docker exec "$P-web" test -f /var/www/html/data/install.lock || { echo "install did not finish" >&2; exit 1; }

# The mock's host name may use http:// and a private address (config_global.php only).
# config_global.php ends with "?>": insert before it.
docker exec "$P-web" php -r '
$f = "/var/www/html/config/config_global.php";
$s = file_get_contents($f);
$add = "\$_config[\"momobird_ai\"][\"http_hosts\"] = array(\"" . $argv[1] . "\");\n";
$pos = strrpos($s, "?>");
$s = $pos === false ? $s . "\n" . $add : substr($s, 0, $pos) . $add . substr($s, $pos);
file_put_contents($f, $s);' "$P-mock"
# PHP-FPM's OPcache may hold the installer's copy (same-second mtime): reload the workers.
docker exec "$P-web" sh -c 'for p in /proc/[0-9]*; do [ "$(head -c 15 "$p/cmdline" 2>/dev/null)" = "php-fpm: master" ] && kill -USR2 "${p#/proc/}"; done; true'
sleep 1

log "setting up forums and installing the plugin"
mkdir -p "$REPO/.cache"
SETUP_OUT="$REPO/.cache/integration-$SUITE-setup.out"
docker exec -u www-data -e IT_MEMBER_USER -e IT_MEMBER_PASSWORD "$P-web" php /opt/it/dz.php setup "$SITE/" > "$SETUP_OUT" 2>&1 || true
tail -1 "$SETUP_OUT" > "$REPO/.cache/integration-$SUITE-setup.json"
php -r '$d = json_decode(file_get_contents($argv[1]), true); exit(is_array($d) && !empty($d["installed"]) ? 0 : 1);' "$REPO/.cache/integration-$SUITE-setup.json" || {
  echo "setup failed:" >&2; php -r 'echo substr(preg_replace("/\s+/", " ", strip_tags(file_get_contents($argv[1]))), 0, 3000), "\n";' "$SETUP_OUT" >&2; exit 1; }

log "running checks"
php scripts/integration/checks.php "$SUITE" "$P" "$SITE" "$REPO/.cache/integration-$SUITE-setup.json"
