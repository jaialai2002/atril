# Atril

Reproductor web de partituras con mezclador de pistas, para ensayar.
Web: **https://atril.sigan.me/**

Este repositorio guarda solo **el programa** (`index.html`). Las obras (partitura,
pistas y `obra.json`) no están aquí: las genera Cantamus Taller (⇧ + REC) y se suben
directamente a `atril.sigan.me/obras/`.

## Cómo llega a la web

Una tarea programada de cPanel ejecuta cada 2 minutos `atril-actualizar.sh` (guardado en
`/home/sigan1/`, fuera de la web). El script pregunta a GitHub cuál es el último cambio de `main`
(40 caracteres) y, solo si es nuevo, descarga el `index.html` de esa versión exacta (sin caché),
comprueba que termina en `</html>` y lo coloca en `atril.sigan.me`. Los cambios llegan en 1-2 minutos.

Para volver a una versión anterior: en GitHub, abrir el historial de `index.html`,
copiar la versión buscada y volver a subirla (o pedírselo a Claude).

## Archivos

- `index.html` — el reproductor y el índice de obras (una sola página, sin dependencias
  salvo Verovio desde jsDelivr y las fuentes de Google).
- `Atril-icono.svg` — el logo.
- `atril-actualizar.sh` — el script de la tarea programada (copia de referencia).
- `htaccess-ejemplo.txt` — copia del `.htaccess` que hay en `atril.sigan.me`
  (no se sincroniza solo; se sube a mano si cambia).

Formato de las obras y detalles: skill «atril» de Claude.

## Dentro de la app Repertoriüm (Android)

(Desde el 28-11-2026 la app se llama **Repertoriüm**; el identificador interno, el repositorio `jaialai2002/repertorio` y la marca `Repertorio/<versión>` del user agent **no cambian**: la detección sigue buscando `Repertorio/`.)

Repertorio (repositorio privado `jaialai2002/repertorio`, otro chat de Claude) es el lector de partituras del
usuario para Android. Abre el Atril en **su propio navegador integrado**: pantalla completa, sin barras, en una
ventana aparte (otra tarea de Android) que sigue viva al volver a Repertorio. Repertorio solo lee el Atril; no
sube obras ni toca `obras.json`. Lo que el Atril tiene para Repertorio, y que hay que respetar al cambiarlo:

- **Cómo se reconoce que está dentro de Repertorio.** El navegador integrado añade `Repertorio/<versión>` al
  final del user agent y ofrece `window.RepertorioApp` con dos métodos: `volver()` (pasa a Repertorio; el Atril
  sigue abierto detrás tal como estaba) y `dividir()` (pantalla dividida Repertorio + Atril). En Chrome, Safari
  o WhatsApp no existe ninguno de los dos.
- **Botón rojo de Repertorio en la barra** (`#aRepertorio`, en `index.html`, delante de `.opciones`):
  oculto por defecto; el script del final de `index.html` lo enseña solo si hay `window.RepertorioApp` *y* el
  user agent dice `Repertorio/`. Toque = `volver()`; mantenido 0,55 s = `dividir()`. Se ve en el índice y en
  las obras. No quitarlo ni moverlo dentro de `.opciones` (en el índice `.opciones` está oculto).
- **Entrada sin Google** (Google no deja iniciar sesión en apps): `lista.php` acepta un formulario
  `POST clave_app=…&ir=<id>` (no JSON). Con una clave de `'apps'` de `atril-config.php`
  (`'apps' => ['clave de 8+ caracteres' => 'correo que esté en correos']`) abre la misma sesión de 30 días
  que Google y redirige (303) a `/` o a `/<id>`. Si falla, responde una página HTML con el motivo exacto
  (no hay `'apps'`, clave corta, longitud distinta, correo fuera de `correos`) sin mostrar nunca la clave.
  **Freno:** 5 fallos desde la misma conexión = 1 hora bloqueada (429); el contador vive en
  `/home/sigan1/atril-intentos.json` con un HMAC de la IP, nunca la IP. Un acierto lo pone a cero.
  Además pone la cookie `atril_app=repertorio` (5 años, no httponly) para que la página de entrada, si la
  sesión caduca, no enseñe el botón de Google sino cómo entrar desde Repertorio.
- **Enlaces:** Repertorio guarda por obra su id del Atril y abre `https://atril.sigan.me/<id>`; si cambia el
  formato de los enlaces de obra, avisar (hay que tocar Repertorio). Los enlaces fuera de `*.sigan.me` se abren
  en el navegador normal; `accounts.google.com` cierra la ventana del Atril con un aviso.
- Al cambiar algo de lo anterior: anotarlo aquí y en la skill «atril», y avisar al usuario para el chat de Repertorio.
