<?php
// Atril · enlace limpio de una obra: atril.sigan.me/<obra>
// El .htaccess manda aquí atril.sigan.me/<obra> (sin #). Este programa entrega la misma página del Atril (index.html)
// con la tarjeta para compartir (título, autor, imagen) de esa obra, que es lo que leen WhatsApp, Telegram, etc.
// En el navegador la página abre la obra como siempre.
// Imagen propia de una obra: obras/<obra>/tarjeta.jpg (o .png, .webp), 1200×630. Si no hay, la común (atril-og.png).
// Este archivo se sube a mano una vez; NO se actualiza solo desde GitHub.

$id = (string) ($_GET['id'] ?? '');
if (!preg_match('/^[a-z0-9-]{1,120}$/', $id)) { header('Location: /', true, 302); exit; }
$o = json_decode((string) @file_get_contents(__DIR__ . '/obras/' . $id . '/obra.json'), true);
$html = @file_get_contents(__DIR__ . '/index.html');
if (!is_array($o) || $html === false) { header('Location: /#' . $id, true, 302); exit; }   // obra desconocida: como antes

$h = function ($t) { return htmlspecialchars((string) $t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
$titulo = trim((string) ($o['obra'] ?? '')) ?: 'Atril';
$autor = trim((string) ($o['autor'] ?? ''));
$desc = ($autor !== '' ? $autor . ' · ' : '') . 'Partitura y voces para ensayar';
$url = 'https://' . $_SERVER['HTTP_HOST'] . '/' . $id;

$cambios = [
  '<title>Atril</title>' => '<title>Atril · ' . $h($titulo) . '</title>',
  '<meta property="og:title" content="Atril">' => '<meta property="og:title" content="' . $h($titulo) . '">' . "\n" . '<meta property="og:url" content="' . $h($url) . '">',
  '<meta property="og:description" content="Partitura y voces por separado para ensayar">' => '<meta property="og:description" content="' . $h($desc) . '">',
  '<meta name="description" content="Partitura y voces por separado para ensayar">' => '<meta name="description" content="' . $h($desc) . '">',
  // la página lee la obra del #: se lo pone antes de arrancar (y luego ella deja la dirección limpia)
  '<head>' => '<head>' . "\n" . '<script>history.replaceState(null, "", "/#" + ' . json_encode($id) . ');</script>',
];
$html = strtr($html, $cambios);

// imagen propia de la obra, si la hay; ?v= con la fecha del archivo: al cambiarla, WhatsApp y compañía la leen como nueva
foreach (['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'] as $ext => $tipo) {
  $f = __DIR__ . '/obras/' . $id . '/tarjeta.' . $ext;
  if (!is_file($f)) continue;
  $img = 'https://' . $_SERVER['HTTP_HOST'] . '/obras/' . $id . '/tarjeta.' . $ext . '?v=' . filemtime($f);
  $tam = @getimagesize($f);
  $html = preg_replace('#<meta property="og:image" content="[^"]*">#', '<meta property="og:image" content="' . $h($img) . '">', $html, 1);
  $html = preg_replace('#<meta property="og:image:type" content="[^"]*">#', '<meta property="og:image:type" content="' . $tipo . '">', $html, 1);
  if ($tam) {
    $html = preg_replace('#<meta property="og:image:width" content="[^"]*">#', '<meta property="og:image:width" content="' . (int) $tam[0] . '">', $html, 1);
    $html = preg_replace('#<meta property="og:image:height" content="[^"]*">#', '<meta property="og:image:height" content="' . (int) $tam[1] . '">', $html, 1);
  }
  $html = preg_replace('#<meta property="og:image:alt" content="[^"]*">#', '<meta property="og:image:alt" content="' . $h($titulo) . '">', $html, 1);
  break;
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');
echo $html;
