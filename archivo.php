<?php
// Atril · portería de los archivos de cada obra (/obras/<id>/…): el .htaccess manda aquí todas esas peticiones.
//
//   Acceso de cada obra (lo decide la cuenta admin en Organizar; va en /home/sigan1/atril-indice.json, obras[id].acceso):
//     'enlace'    -> cualquiera con el enlace (como era todo antes)
//     'yo'        -> solo las cuentas 'admin' (lo que tiene una obra recién subida hasta que se decida)
//     'invitados' -> las cuentas 'admin' y los correos de obras[id].invitados (personas dadas de alta en Organizar)
//   Las obras que ya estaban cuando llegó esta portería quedaron en 'enlace' (marca acceso_v en el índice).
//
//   Sin sesión en una obra que no es 'enlace': 401 {"entrar": true, "cliente": …} (la página enseña el botón de Google).
//   Con sesión pero sin permiso: 403. El audio admite «Range» (saltar dentro de la pista) y se puede guardar en el
//   navegador (privado si la obra no es pública). Este archivo se sube a mano (desde la rueda); NO llega de GitHub.

$fuera = dirname(__DIR__);
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet, noimageindex');

function sale_a($codigo, $datos) {
  http_response_code($codigo); header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
  echo json_encode($datos, JSON_UNESCAPED_UNICODE); exit;
}
$id = (string) ($_GET['o'] ?? ''); $f = (string) ($_GET['f'] ?? '');
if (!preg_match('/^[a-z0-9-]{1,120}$/', $id) || $f === '' || strpos($f, '..') !== false || !preg_match('~^[\w .,()+\-/]{1,200}$~u', $f)) sale_a(404, ['error' => 'No existe']);
$base = realpath(__DIR__ . '/obras/' . $id);
$ruta = $base ? realpath($base . '/' . $f) : false;
if (!$base || !$ruta || strpos($ruta, $base . '/') !== 0 || !is_file($ruta)) sale_a(404, ['error' => 'No existe']);

// ---------- quién puede
$cfg = @include $fuera . '/atril-config.php';
if (!is_array($cfg) || empty($cfg['correos'])) sale_a(500, ['error' => 'Falta atril-config.php']);
$admin = array_map('strtolower', array_map('trim', (array) ($cfg['admin'] ?? [$cfg['correos'][0]])));
$INDICE = $fuera . '/atril-indice.json';
$ix = json_decode((string) @file_get_contents($INDICE), true); if (!is_array($ix)) $ix = [];
$org = is_array($ix['obras'][$id] ?? null) ? $ix['obras'][$id] : [];
$acceso = (string) ($org['acceso'] ?? (empty($ix['acceso_v']) ? 'enlace' : 'yo'));
if (!in_array($acceso, ['enlace', 'yo', 'invitados'], true)) $acceso = 'yo';

function sesion_a($fuera) {   // correo de la sesión (firma válida y sin caducar); la lista de quién vale se mira después
  $s = @file_get_contents($fuera . '/.atril-secreto'); if ($s === false || strlen($s) < 32) return false;
  $p = explode('.', (string) ($_COOKIE['atril_sesion'] ?? '')); if (count($p) !== 3) return false;
  [$c, $hasta, $fi] = $p;
  if (!hash_equals(hash_hmac('sha256', $c . '.' . $hasta, $s), $fi) || (int) $hasta < time()) return false;
  return strtolower(base64_decode(strtr($c, '-_', '+/')));
}
if ($acceso !== 'enlace') {
  $e = sesion_a($fuera);
  $invitados = array_map('strtolower', (array) ($org['invitados'] ?? []));
  $vale = $e && (in_array($e, $admin, true) || ($acceso === 'invitados' && in_array($e, $invitados, true)));
  if (!$e) sale_a(401, ['entrar' => true, 'cliente' => (string) ($cfg['cliente'] ?? ''), 'acceso' => $acceso]);
  if (!$vale) sale_a(403, ['error' => 'Esta obra no está compartida contigo', 'acceso' => $acceso]);
}

// ---------- entregar el archivo
$ext = strtolower(pathinfo($ruta, PATHINFO_EXTENSION));
$tipos = ['json' => 'application/json; charset=utf-8', 'musicxml' => 'application/vnd.recordare.musicxml+xml; charset=utf-8',
  'm4a' => 'audio/mp4', 'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'pdf' => 'application/pdf'];
header('Content-Type: ' . ($tipos[$ext] ?? 'application/octet-stream'));
$publica = $acceso === 'enlace';
if (in_array($ext, ['m4a', 'mp3', 'wav'], true))   // llevan ?v=<fecha de la versión>: cambian de dirección al cambiar
  header('Cache-Control: ' . ($publica ? 'public' : 'private') . ', max-age=31536000, immutable');
else header('Cache-Control: ' . ($publica ? '' : 'private, ') . 'no-cache');   // se guarda pero se pregunta (304 si no cambió)
if (!$publica) header('Vary: Cookie');
$tam = filesize($ruta); $mod = filemtime($ruta);
$etag = '"' . dechex($mod) . '-' . dechex($tam) . '"';
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mod) . ' GMT'); header('ETag: ' . $etag); header('Accept-Ranges: bytes');
if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag
    || (!isset($_SERVER['HTTP_IF_NONE_MATCH']) && isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) && strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) >= $mod)) {
  http_response_code(304); exit;
}
$ini = 0; $fin = $tam - 1;
if (isset($_SERVER['HTTP_RANGE']) && preg_match('/^bytes=(\d*)-(\d*)$/', trim($_SERVER['HTTP_RANGE']), $r) && ($r[1] !== '' || $r[2] !== '')) {
  if ($r[1] === '') { $ini = max(0, $tam - (int) $r[2]); } else { $ini = (int) $r[1]; if ($r[2] !== '') $fin = min($fin, (int) $r[2]); }
  if ($ini > $fin || $ini >= $tam) { http_response_code(416); header('Content-Range: bytes */' . $tam); exit; }
  http_response_code(206); header("Content-Range: bytes $ini-$fin/$tam");
}
header('Content-Length: ' . ($fin - $ini + 1));
if ($_SERVER['REQUEST_METHOD'] === 'HEAD') exit;
while (ob_get_level()) ob_end_clean();
$h = fopen($ruta, 'rb'); fseek($h, $ini); $quedan = $fin - $ini + 1;
while ($quedan > 0 && !feof($h)) { $b = fread($h, min(262144, $quedan)); echo $b; $quedan -= strlen($b); flush(); }
fclose($h);
