#!/bin/sh
# Atril: trae de GitHub el index.html en cuanto hay un cambio nuevo.
# Pregunta primero el último cambio (40 caracteres); solo si es nuevo descarga esa versión exacta (sin caché).
cd /home/sigan1/atril.sigan.me || exit 1
SHA=$(curl -fsS --max-time 20 -H "Accept: application/vnd.github.sha" https://api.github.com/repos/jaialai2002/atril/commits/main) || exit 0
[ ${#SHA} -eq 40 ] || exit 0
[ "$SHA" = "$(cat .atril-sha 2>/dev/null)" ] && exit 0
curl -fsS --max-time 60 -o .index-nuevo.html "https://raw.githubusercontent.com/jaialai2002/atril/$SHA/index.html" || exit 0
tail -c 20 .index-nuevo.html | grep -q "</html>" || { rm -f .index-nuevo.html; exit 0; }
mv -f .index-nuevo.html index.html && echo "$SHA" > .atril-sha
