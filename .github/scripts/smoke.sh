#!/usr/bin/env bash
# اختبار تشغيل على ووردبريس حقيقي (Docker): يثبّت ملفَي dist/ (شغّل build.sh أولاً) مع محتوى
# تجريبي، ثم يفتح صفحات الموقع ويجرّب واجهة REST كزائر وعضو ومدير.
# يفشل عند أي صفحة برمز غير متوقع، أو أي خطأ أو تحذير PHP في سجل ووردبريس.
#
# الاستخدام: .github/scripts/smoke.sh [إصدار PHP، الافتراضي 8.4]
set -euo pipefail
cd "$(dirname "$0")/../.."

PHP_VERSION="${1:-8.4}"
REGISTRY="${REGISTRY:-mirror.gcr.io/library}"
PORT="${PORT:-8089}"
BASE="http://localhost:${PORT}"
NET=rv-smoke
DB=rv-smoke-db
WP=rv-smoke-wp
CORE=rv-smoke-core
TMP="$(mktemp -d)"
FAILED=0

cleanup() {
	docker rm -f "$WP" "$DB" > /dev/null 2>&1 || true
	docker volume rm "$CORE" > /dev/null 2>&1 || true
	docker network rm "$NET" > /dev/null 2>&1 || true
	rm -rf "$TMP"
}
trap cleanup EXIT
cleanup
mkdir -p "$TMP"

wp() {
	docker run --rm -i --network "$NET" --volumes-from "$WP" --user 33:33 -e HOME=/tmp \
		-e WORDPRESS_DB_HOST="$DB" -e WORDPRESS_DB_USER=wp -e WORDPRESS_DB_PASSWORD=wp -e WORDPRESS_DB_NAME=wp \
		"$REGISTRY/wordpress:cli" wp "$@"
}

fail() {
	echo "::error::$*"
	FAILED=1
}

# check <الوصف> <المتوقع> <الفعلي>
check() {
	if [ "$3" = "$2" ]; then echo "ok $2 $1"; else fail "$1 returned $3 (expected $2)"; fi
}

echo "== WordPress + PHP ${PHP_VERSION}"
docker network create "$NET" > /dev/null
docker run -d --name "$DB" --network "$NET" -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=wp \
	-e MARIADB_USER=wp -e MARIADB_PASSWORD=wp "$REGISTRY/mariadb:11" > /dev/null
# أحدث ووردبريس من صورة PHP 8.4، ويعمل بإصدار PHP المطلوب (صورة PHP 7.4 تحمل ووردبريس قديماً).
docker volume create "$CORE" > /dev/null
docker run --rm -v "$CORE:/out" --entrypoint sh "$REGISTRY/wordpress:php8.4-apache" -c 'cp -a /usr/src/wordpress/. /out/ && chown -R 33:33 /out'
docker run -d --name "$WP" --network "$NET" -p "${PORT}:80" \
	-e WORDPRESS_DB_HOST="$DB" -e WORDPRESS_DB_USER=wp -e WORDPRESS_DB_PASSWORD=wp -e WORDPRESS_DB_NAME=wp \
	-e WORDPRESS_DEBUG=1 \
	-e WORDPRESS_CONFIG_EXTRA="define( 'WP_DEBUG_LOG', '/var/www/html/wp-content/debug.log' ); define( 'WP_DEBUG_DISPLAY', false );" \
	-v "$CORE:/var/www/html" "$REGISTRY/wordpress:php${PHP_VERSION}-apache" > /dev/null

for _ in $(seq 1 60); do
	if docker exec "$DB" mariadb -uwp -pwp -e 'SELECT 1' wp > /dev/null 2>&1 && curl -s -o /dev/null "$BASE/"; then
		break
	fi
	sleep 2
done
docker exec "$WP" php -v | head -n 1

echo "== Install"
wp core install --url="$BASE" --title=RetroVault --admin_user=admin --admin_password=admin \
	--admin_email=admin@example.com --skip-email
docker cp dist/retrovault-core.zip "$WP:/var/www/html/wp-content/retrovault-core.zip"
docker cp dist/retrovault-theme.zip "$WP:/var/www/html/wp-content/retrovault-theme.zip"
docker cp .github/scripts/seed.php "$WP:/var/www/html/wp-content/seed.php"
docker exec "$WP" sh -c 'touch wp-content/debug.log && chown -R www-data:www-data wp-content'
wp plugin install wp-content/retrovault-core.zip --activate
wp theme install wp-content/retrovault-theme.zip --activate
wp rewrite structure '/%postname%/'
wp option update users_can_register 1
wp eval-file wp-content/seed.php
# كل ملفات PHP بإصدار PHP الموقع نفسه (بعضها لا يُحمَّل إلا في صفحات معينة).
docker exec "$WP" sh -c 'for f in $(find wp-content/plugins/retrovault-core wp-content/themes/retrovault -name "*.php"); do php -l "$f" > /tmp/lint 2>&1 || { cat /tmp/lint; exit 1; }; done'
GAME=$(wp post list --post_type=rv_game --name=pixel-quest --field=ID)
docker exec -u www-data "$WP" sh -c ': > wp-content/debug.log'

# expect <رمز متوقع> <المسار> [ملف الكوكيز]
expect() {
	local want="$1" path="$2" jar="${3:-}" code
	if [ -n "$jar" ]; then
		code=$(curl -s -b "$jar" -o "$TMP/body" -w '%{http_code}' "$BASE$path")
	else
		code=$(curl -s -o "$TMP/body" -w '%{http_code}' "$BASE$path")
	fi
	if [ "$code" != "$want" ]; then
		fail "$path returned $code (expected $want)${jar:+ as $(basename "$jar" .jar)}"
	elif grep -qiE 'There has been a critical error|Fatal error|Parse error' "$TMP/body"; then
		fail "$path shows a PHP error${jar:+ as $(basename "$jar" .jar)}"
	else
		echo "ok $code $path${jar:+ ($(basename "$jar" .jar))}"
	fi
}

login() {
	curl -s -c "$TMP/$1.jar" -b 'wordpress_test_cookie=WP%20Cookie%20check' -o /dev/null \
		--data-urlencode "log=$1" --data-urlencode "pwd=$1" -d 'wp-submit=Log+In&testcookie=1' "$BASE/wp-login.php"
}

# rest <المسار> <اسم الحقل المتوقع في الرد> [وسائط curl...] (كعضو)
rest() {
	local path="$1" want="$2" out
	shift 2
	out=$(curl -s -b "$TMP/member.jar" -H "X-WP-Nonce: $NONCE" "$@" "$BASE/wp-json/retrovault/v1/$path")
	if printf '%s' "$out" | grep -q "\"$want\""; then
		echo "ok REST $path"
	else
		fail "REST $path: $out"
	fi
}

echo "== Guest"
expect 200 /
expect 200 /games/
expect 200 '/games/?sort=rating'
expect 200 '/games/?sort=trending&players=multi&status=released'
expect 200 '/games/?q=pixel'
expect 200 /games/pixel-quest/
expect 200 /games/pixel-quest/play/
expect 200 /games/pixel-quest/download/
expect 403 /games/star-racer/download/
expect 200 /games/void-shooter/play/
expect 200 /system/nes/
expect 200 /genre/rpg/
expect 200 '/?s=pixel'
expect 200 /devlog/
expect 200 /devlog-first-update/
expect 302 /account/
expect 404 /this-page-does-not-exist/
expect 200 /wp-login.php
expect 200 '/?rv_sw=1'
expect 200 '/?rv_manifest=1'
expect 200 '/?rv_offline=1'
expect 302 '/?rv_random=1'
expect 200 /feed/
expect 200 "/wp-json/retrovault/v1/games/$GAME/rating"

echo "== Fonts and preloads"
# كل خط يُطلب مبكراً (preload) هو الرابط نفسه في fonts.css، وإلا نزّله المتصفح مرتين. والملفات موجودة.
curl -s -o "$TMP/home.html" "$BASE/"
fonts_css=$(grep -m 1 -oP "id='rvt-fonts-css' href='\K[^']+" "$TMP/home.html" || true)
curl -s -o "$TMP/fonts.css" "$fonts_css"
grep -oP '<link rel="preload" href="\K[^"]+(?=" as="font")' "$TMP/home.html" > "$TMP/preloads" || true
check "font preloads on the home page" 8 "$(wc -l < "$TMP/preloads")"
while read -r url; do
	if ! grep -qF "url(\"${url##*/}\")" "$TMP/fonts.css"; then
		fail "preloaded font ${url##*/} is not the URL fonts.css uses"
	fi
	got=$(curl -s -o "$TMP/font" -w '%{http_code}' "$url")
	if [ "$got" != 200 ] || [ "$(wc -c < "$TMP/font")" -lt 1000 ]; then
		fail "font $url returned $got"
	fi
done < "$TMP/preloads"
# أكبر صورة في أعلى صفحة اللعبة (صورة المشغّل) والتدوينة (الصورة البارزة) تُطلب قبل الخطوط وبالرابط نفسه.
curl -s -o "$TMP/game.html" "$BASE/games/pixel-quest/"
check "the game page preloads its player image" \
	"$(grep -m 1 -oP 'class="rv-player__poster[^"]*" src="\K[^"]+' "$TMP/game.html" || echo none)" \
	"$(grep -m 1 -oP '<link rel="preload" href="\K[^"]+(?=" as="image")' "$TMP/game.html" || echo missing)"
wp eval 'set_post_thumbnail( get_page_by_path( "devlog-first-update", OBJECT, "post" ), get_post_thumbnail_id( get_page_by_path( "pixel-quest", OBJECT, "rv_game" ) ) );'
curl -s -o "$TMP/post.html" "$BASE/devlog-first-update/"
check "the post preloads its featured image with the same srcset" \
	"$(grep -m 1 -oP '<img [^>]*wp-post-image[^>]* srcset="\K[^"]+' "$TMP/post.html" || echo none)" \
	"$(grep -m 1 -oP '<link rel="preload" [^>]*as="image" imagesrcset="\K[^"]+' "$TMP/post.html" || echo missing)"

echo "== Game file protection"
# status <وسائط curl...>: رمز الرد فقط. check <الوصف> <المتوقع> <الفعلي>.
status() { curl -s -o /dev/null -w '%{http_code}' "$@"; }
xhr=(-H 'Sec-Fetch-Mode: cors' -H 'Sec-Fetch-Site: same-origin' -H 'Sec-Fetch-Dest: empty')
curl -s -D "$TMP/player.headers" -c "$TMP/player.jar" "$BASE/games/pixel-quest/play/" > "$TMP/player.html"
rom=$(grep -oE 'EJS_gameUrl = "[^"]+"' "$TMP/player.html" | sed -e 's/^EJS_gameUrl = "//' -e 's/"$//' -e 's#\\/#/#g' || true)
if grep -qi '^set-cookie: rv_player_.*HttpOnly.*SameSite=Lax' "$TMP/player.headers"; then
	echo "ok player issues an HttpOnly SameSite session cookie"
else
	fail "player session cookie is missing or insecure"
fi
file=$(wp eval "echo wp_get_attachment_url( (int) get_post_meta( $GAME, '_rv_rom_id', true ) );")
case "$rom" in
	"$BASE"/games/pixel-quest/rom/*) echo "ok player gets the protected link" ;;
	*) fail "player does not get the protected link: $rom" ;;
esac
check "game file for the player" 200 "$(status -b "$TMP/player.jar" "${xhr[@]}" "$rom")"
check "game file size check (HEAD)" 200 "$(status -b "$TMP/player.jar" -I "${xhr[@]}" "$rom")"
check "copied link without its browser session" 403 "$(status "${xhr[@]}" "$rom")"
curl -s -c "$TMP/other-player.jar" "$BASE/games/pixel-quest/play/" > /dev/null
check "copied link in another browser session" 403 "$(status -b "$TMP/other-player.jar" "${xhr[@]}" "$rom")"
check "game file opened in a browser tab" 403 "$(status -b "$TMP/player.jar" -H 'Sec-Fetch-Mode: navigate' -H 'Sec-Fetch-Dest: document' "$rom")"
check "game file requested by another site" 403 "$(status -b "$TMP/player.jar" -H 'Sec-Fetch-Mode: cors' -H 'Sec-Fetch-Site: cross-site' "$rom")"
check "same-site subdomain cannot request the ROM" 403 "$(status -b "$TMP/player.jar" -H 'Sec-Fetch-Mode: cors' -H 'Sec-Fetch-Site: same-site' "$rom")"
check "ROM request with no source headers" 403 "$(status -b "$TMP/player.jar" "$rom")"
check "legacy player with same-origin referer" 200 "$(status -b "$TMP/player.jar" -e "$BASE/games/pixel-quest/play/" "$rom")"
check "legacy request with a foreign referer" 403 "$(status -b "$TMP/player.jar" -e 'https://other.example/player/' "$rom")"
check "unsupported ROM method" 405 "$(status -b "$TMP/player.jar" "${xhr[@]}" -X POST "$rom")"
check "unsupported download method" 405 "$(status -X POST "$BASE/games/pixel-quest/download/")"
check "game file with a wrong token" 403 "$(status -b "$TMP/player.jar" "${xhr[@]}" "$(printf '%s' "$rom" | sed -E 's#/rom/[0-9a-f]{32}/#/rom/00000000000000000000000000000000/#')")"
if curl -s -D - -o /dev/null -b "$TMP/player.jar" "${xhr[@]}" "$rom" | grep -qi '^cache-control:.*no-store'; then
	echo "ok browser HTTP cache cannot bypass server authorization"
else
	fail "ROM response allows an unchecked HTTP-cache replay"
fi
check "game file at its direct address" 403 "$(status "$file")"
if curl -s -D - -o /dev/null "$BASE/games/pixel-quest/download/" | grep -qi '^content-disposition: attachment'; then
	echo "ok allowed download is sent as an attachment"
else
	fail "allowed download is not sent as an attachment"
fi
if curl -s "$BASE/wp-json/wp/v2/media?per_page=100" | grep -q 'retrovault-roms'; then
	fail "game file is listed in the public media API"
else
	echo "ok game file is hidden from the public media API"
fi
wp post update "$GAME" --post_password=rom-test --quiet
check "password-protected ROM without the password" 403 "$(status -b "$TMP/player.jar" "${xhr[@]}" "$rom")"
check "password-protected download" 403 "$(status "$BASE/games/pixel-quest/download/")"
wp post update "$GAME" --post_password= --quiet
wp post update "$GAME" --post_status=private --quiet
check "unpublished ROM cannot be read by a guest" 404 "$(status -b "$TMP/player.jar" "${xhr[@]}" "$rom")"
wp post update "$GAME" --post_status=publish --quiet
wp option patch update retrovault_settings downloads 0 --format=json --quiet
check "globally disabled downloads" 403 "$(status "$BASE/games/pixel-quest/download/")"
check "disabling downloads preserves authorized play" 200 "$(status -b "$TMP/player.jar" "${xhr[@]}" "$rom")"
wp option patch update retrovault_settings downloads 1 --format=json --quiet

echo "== Member"
login member
expect 200 / "$TMP/member.jar"
expect 200 /games/pixel-quest/ "$TMP/member.jar"
expect 200 /games/pixel-quest/play/ "$TMP/member.jar"
NONCE=$(grep -oE '"nonce":"[a-f0-9]+"' "$TMP/body" | head -n 1 | cut -d'"' -f4 || true)
head -c 4096 /dev/urandom > "$TMP/state.bin"
printf 'sram' > "$TMP/sram.bin"
rest "games/$GAME/rating" average -X POST -d rating=4
rest "games/$GAME/favorite" favorite -X POST
rest "games/$GAME/save" slot -F "state=@$TMP/state.bin" -F core=fceumm
rest "games/$GAME/sram" hash -F "sram=@$TMP/sram.bin" -F hash=abc123 -F base=
rest me/notify email -H 'Content-Type: application/json' -d '{"email":true}'
expect 200 /account/ "$TMP/member.jar"
expect 302 /wp-admin/ "$TMP/member.jar"

echo "== Instant sign-up"
# كلمة مرور فيها ' لأن ووردبريس يضيف لها شرطة مائلة، والدخول لاحقاً يقارنها بالصيغة نفسها.
signup=$(curl -s -c "$TMP/newbie.jar" -o /dev/null -w '%{http_code} %{redirect_url}' \
	--data-urlencode 'user_login=newbie' --data-urlencode 'user_email=newbie@example.com' \
	--data-urlencode "rv_pass=it's-a-pass1" --data-urlencode "redirect_to=$BASE/games/pixel-quest/" \
	-d 'wp-submit=Register' "$BASE/wp-login.php?action=register")
check "sign-up returns to the page it started from" "302 $BASE/games/pixel-quest/" "$signup"
expect 200 /account/ "$TMP/newbie.jar"
if grep -q 'rv-account-form' "$TMP/body"; then
	echo "ok account settings form on the account page"
else
	fail "account settings form is missing"
fi
check "the chosen password logs in" 302 "$(status -b 'wordpress_test_cookie=WP%20Cookie%20check' --data-urlencode 'log=newbie' \
	--data-urlencode "pwd=it's-a-pass1" -d 'wp-submit=Log+In&testcookie=1' "$BASE/wp-login.php")"

echo "== Security limits"
# خمس كلمات مرور خاطئة لاسم واحد من اتصال واحد: حتى الصحيحة تُرفض بعدها 15 دقيقة (200 بدل التحويل 302).
for _ in 1 2 3 4 5; do
	curl -s -o /dev/null -b 'wordpress_test_cookie=WP%20Cookie%20check' --data-urlencode 'log=newbie' \
		--data-urlencode 'pwd=wrong-password' -d 'wp-submit=Log+In&testcookie=1' "$BASE/wp-login.php"
done
check "five wrong passwords lock that login for this connection" 200 "$(status -b 'wordpress_test_cookie=WP%20Cookie%20check' \
	--data-urlencode 'log=newbie' --data-urlencode "pwd=it's-a-pass1" -d 'wp-submit=Log+In&testcookie=1' "$BASE/wp-login.php")"
curl -s -D "$TMP/account.headers" -o /dev/null -b "$TMP/member.jar" "$BASE/account/"
if grep -qi '^x-frame-options: sameorigin' "$TMP/account.headers"; then
	echo "ok the account page cannot be framed by other sites"
else
	fail "the account page can be framed by other sites"
fi

echo "== Follower emails and devlog"
# فتح رابط الإيقاف وحده (كما تفعل برامج فحص الروابط) لا يغيّر شيئاً؛ الإيقاف بزر أو بضغطة واحدة من برنامج البريد.
unsub=$(wp eval 'echo RetroVault\Notifier::unsubscribe_url( get_user_by( "login", "member" )->ID );')
curl -s -o /dev/null "$unsub"
check "opening the unsubscribe link alone changes nothing" 1 "$(wp user meta get member _rv_notify_email)"
curl -s -o /dev/null -d 'List-Unsubscribe=One-Click' "$unsub"
check "one-click unsubscribe from the mail app" 0 "$(wp user meta get member _rv_notify_email)"
curl -s -o /dev/null -d 'rv_choice=on' "$unsub"
check "undo re-enables update emails" 1 "$(wp user meta get member _rv_notify_email)"
# إلى ملف لا أنبوب: grep -q يخرج عند أول تطابق فيُقتل curl أثناء الكتابة، ومع pipefail يفشل الفحص.
curl -s -o "$TMP/devlog.html" "$BASE/devlog-first-update/"
if grep -q '<meta property="og:type" content="article">' "$TMP/devlog.html"; then
	echo "ok devlog posts have share tags"
else
	fail "devlog posts are missing share tags"
fi

echo "== Admin"
login admin
for path in /wp-admin/ '/wp-admin/edit.php?post_type=rv_game' '/wp-admin/post-new.php?post_type=rv_game' \
	"/wp-admin/post.php?post=$GAME&action=edit" '/wp-admin/edit-tags.php?taxonomy=rv_system&post_type=rv_game' \
	'/wp-admin/edit.php?post_type=rv_game&page=retrovault-settings' \
	'/wp-admin/edit.php?post_type=rv_game&page=retrovault-stats&period=365' \
	/wp-admin/plugins.php /wp-admin/themes.php /wp-admin/customize.php \
	'/wp-admin/edit.php?post_type=rv_game&page=retrovault-stats'; do
	expect 200 "$path" "$TMP/admin.jar"
done
# آخر صفحة فُتحت هي صفحة الإحصائيات: منها رابط التصدير.
export_url=$(grep -oE 'href="[^"]*rv_export=csv[^"]*"' "$TMP/body" | head -n 1 | sed -e 's/^href="//' -e 's/"$//' -e 's/&amp;/\&/g' -e 's/&#038;/\&/g' || true)
if [ -n "$export_url" ] && curl -s -b "$TMP/admin.jar" -D "$TMP/headers" -o "$TMP/export.csv" "$export_url" && grep -qi '^content-type: text/csv' "$TMP/headers"; then
	echo "ok CSV export ($(wc -l < "$TMP/export.csv") lines)"
else
	fail "CSV export did not return a CSV file"
fi

echo "== Regression tests"
docker cp .github/scripts/regression.php "$WP:/var/www/html/wp-content/regression.php"
wp eval-file wp-content/regression.php

echo "== PHP log"
# سطور الاتصال بـ wordpress.org لا تخص الإضافة (بيئات بلا إنترنت).
log=$(docker exec "$WP" cat wp-content/debug.log | grep -v -i 'wordpress\.org' || true)
if [ -n "$log" ]; then
	echo "$log"
	fail "PHP notices, warnings or errors were logged (see above)"
else
	echo "ok no PHP notices, warnings or errors"
fi

exit "$FAILED"
