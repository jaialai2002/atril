# Atril

Reproductor web de partituras con mezclador de pistas, para ensayar.
Web: **https://atril.sigan.me/**

Este repositorio guarda solo **el programa** (`index.html`). Las obras (partitura,
pistas y `obra.json`) no están aquí: las genera Cantamus Taller (⇧ + REC) y se suben
directamente a `atril.sigan.me/obras/`.

## Cómo llega a la web

Una tarea programada de cPanel (cada 15 minutos) descarga
`https://raw.githubusercontent.com/jaialai2002/atril/main/index.html`
y solo sustituye el `index.html` de `atril.sigan.me` si la descarga está completa
(termina en `</html>`). Así, cada cambio que se sube aquí aparece solo en la web.

Para volver a una versión anterior: en GitHub, abrir el historial de `index.html`,
copiar la versión buscada y volver a subirla (o pedírselo a Claude).

## Archivos

- `index.html` — el reproductor y el índice de obras (una sola página, sin dependencias
  salvo Verovio desde jsDelivr y las fuentes de Google).
- `Atril-icono.svg` — el logo.
- `htaccess-ejemplo.txt` — copia del `.htaccess` que hay en `atril.sigan.me`
  (no se sincroniza solo; se sube a mano si cambia).

Formato de las obras y detalles: skill «atril» de Claude.
