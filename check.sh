#!/usr/bin/env bash
# Проверка сайта после выкладки. SITE_URL — адрес, SITE_IP — (необязательно) IP хостинга.
set -u
U="${SITE_URL%/}"; HOST=$(echo "$U" | sed -E 's#^https?://([^/:]+).*#\1#')
PORT=80; [[ "$U" == https://* ]] && PORT=443
R=(); [ -n "${SITE_IP:-}" ] && R=(--resolve "$HOST:$PORT:$SITE_IP")
fail=0
get(){ curl -s -m 30 "${R[@]}" -o /tmp/body -w '%{http_code}' "$U$1"; }
title(){ grep -o '<title>[^<]*' /tmp/body | head -1 | sed 's/<title>//'; }
ok(){ echo "OK   $1"; }; bad(){ echo "FAIL $1"; fail=1; }

c=$(get /); home=$(title); [ "$c" = 200 ] && ok "/ → 200" || bad "/ → $c"
c=$(get /kalkulyatory/drr); t=$(title)
[ "$c" = 200 ] && echo "$t" | grep -q "Калькулятор ДРР" && ok "/kalkulyatory/drr → 200, «$t»" || bad "/kalkulyatory/drr → $c, «$t»"
c=$(get /blog); t=$(title)
[ "$c" = 200 ] && [ -n "$t" ] && [ "$t" != "$home" ] && ok "/blog → 200, свой заголовок" || bad "/blog → $c, «$t» (совпадает с главной?)"
c=$(get /net-takoi-stranicy-123); [ "$c" = 404 ] && ok "несуществующая → 404" || bad "несуществующая → $c (ждали 404)"
c=$(get /sitemap.xml); [ "$c" = 200 ] && ok "/sitemap.xml → 200" || bad "/sitemap.xml → $c"
c=$(get /robots.txt);  [ "$c" = 200 ] && ok "/robots.txt → 200"  || bad "/robots.txt → $c"
c=$(get /.ftp-deploy-sync-state.json); [ "$c" = 404 ] && ok "служебный файл закрыт" || bad "/.ftp-deploy-sync-state.json → $c (ждали 404)"
c=$(get /api/lead.php); grep -q '"service":"lead"' /tmp/body && [ "$c" = 405 ] && ok "обработчик заявок работает (PHP)" || bad "/api/lead.php → $c (ждали 405 и ответ обработчика)"
c=$(get /api/config.php); [ "$c" = 404 ] && ok "настройки обработчика закрыты" || bad "/api/config.php → $c (ждали 404)"
c=$(get /api/data/leads.csv); { [ "$c" = 404 ] || [ "$c" = 403 ]; } && ok "журнал заявок закрыт ($c)" || bad "/api/data/leads.csv → $c (ждали 403 или 404)"
[ $fail = 0 ] && echo "Все проверки пройдены" || { echo "Есть ошибки — см. выше"; exit 1; }
