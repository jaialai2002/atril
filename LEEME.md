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
