<?php
// Atril · anotaciones sobre la partitura (lápiz y texto), en dos capas:
//   «mia»      : las de cada cuenta de Google (solo las ve quien las hizo, en cualquier dispositivo)
//   «director» : las de las cuentas 'admin' de atril-config.php (las ven todos)
//
//   GET  notas.php?obra=<id>                                 -> {admin, mia:{items,actualizado}, director:{items,actualizado}}
//   POST notas.php {"obra": "<id>", "capa": "mia"|"director", "items": […]}   -> guarda esa capa entera
//
// Sesión: la misma cookie que abre lista.php al entrar con Google (misma clave de firma, fuera de la web).
// Las anotaciones van FUERA de la web, en /home/sigan1/atril-notas/<obra>/:
//   director.json · <hash del correo>.json (el correo no aparece en el nombre) y, de cada una, las 5 versiones anteriores
//   en .anteriores/ por si hay que recuperar algo.
// Este archivo se sube con la rueda del Taller; NO se actualiza solo desde GitHub.

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$fuera = dirname(__DIR__);
$cfg = @include $fuera . '/atril-config.php';
if (!is_array($cfg) || empty($cfg['correos'])) { http_response_code(500); echo '{"error":"Falta o está incompleto atril-config.php"}'; exit; }
$correos = array_map('strtolower', array_map('trim', (array) $cfg['correos']));
$admin = array_map('strtolower', array_map('trim', (array) ($cfg['admin'] ?? [$cfg['correos'][0]])));
$NOTAS = $fuera . '/atril-notas';
// las personas dadas de alta en Organizar también pueden anotar (en las obras que pueden abrir; ver archivo.php)
$IXN = json_decode((string) @file_get_contents($fuera . '/atril-indice.json'), true); if (!is_array($IXN)) $IXN = [];
foreach ((array) ($IXN['personas'] ?? []) as $pp) { $c = strtolower(trim((string) ($pp['correo'] ?? ''))); if ($c !== '' && !in_array($c, $correos, true)) $correos[] = $c; }
date_default_timezone_set('Europe/Madrid');

// --- la sesión de lista.php (misma cookie, misma firma)
function secreto_n($fuera) { $s = @file_get_contents($fuera . '/.atril-secreto'); return ($s !== false && strlen($s) >= 32) ? $s : null; }
function deb64_n($s) { return base64_decode(strtr($s, '-_', '+/')); }
function sesion_n($correos, $fuera) {
  $c = $_COOKIE['atril_sesion'] ?? '';
  $p = explode('.', $c);
  $sec = secreto_n($fuera);
  if (count($p) !== 3 || !$sec) return false;
  [$correo, $hasta, $f] = $p;
  if (!hash_equals(hash_hmac('sha256', $correo . '.' . $hasta, $sec), $f)) return false;
  if ((int) $hasta < time()) return false;
  $e = strtolower(deb64_n($correo));
  return in_array($e, $correos, true) ? $e : false;
}

$e = sesion_n($correos, $fuera);
if (!$e) { http_response_code(401); echo '{"entrar":true}'; exit; }
$esAdmin = in_array($e, $admin, true);

function obra_valida($id) {
  global $IXN, $e, $esAdmin;
  if (!(is_string($id) && preg_match('/^[a-z0-9][a-z0-9-]{0,119}$/', $id) && is_file(__DIR__ . '/obras/' . $id . '/obra.json'))) return false;
  if ($esAdmin) return true;
  $org = is_array($IXN['obras'][$id] ?? null) ? $IXN['obras'][$id] : [];
  $acc = (string) ($org['acceso'] ?? (empty($IXN['acceso_v']) ? 'enlace' : 'yo'));
  return $acc === 'enlace' || ($acc === 'invitados' && in_array($e, array_map('strtolower', (array) ($org['invitados'] ?? [])), true));
}
function archivo_capa($dir, $capa, $correo) { return $dir . '/' . ($capa === 'director' ? 'director' : 'p-' . substr(hash('sha256', 'atril-notas|' . $correo), 0, 24)) . '.json'; }
function leer_capa($f) {
  $d = json_decode((string) @file_get_contents($f), true);
  return is_array($d) && is_array($d['items'] ?? null) ? ['items' => $d['items'], 'actualizado' => (string) ($d['actualizado'] ?? '')] : ['items' => [], 'actualizado' => ''];
}

// --- limpiar lo que llega: solo los campos conocidos, números acotados, textos cortos
function num($v, $min, $max, $dec = 4) { $v = is_numeric($v) ? (float) $v : 0.0; return round(max($min, min($max, $v)), $dec); }
function color_ok($c) { return is_string($c) && preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? $c : '#c0283f'; }
function limpiar($it) {
  if (!is_array($it)) return null;
  $t = $it['t'] ?? '';
  $base = [
    'id' => preg_replace('/[^a-z0-9]/i', '', substr((string) ($it['id'] ?? ''), 0, 24)) ?: bin2hex(random_bytes(6)),
    't' => $t,
    'k' => (int) num($it['k'] ?? 0, 0, 100000, 0),       // compás (orden en la partitura)
    'n' => substr((string) ($it['n'] ?? ''), 0, 12),      // su número, para comprobar
    's' => (int) num($it['s'] ?? 1, 1, 200, 0),           // pentagrama dentro del compás
    'c' => color_ok($it['c'] ?? ''),
  ];
  if (isset($it['o']) && is_numeric($it['o']) && (float) $it['o'] < 1) $base['o'] = num($it['o'], .1, 1, 2);   // opacidad
  if ($t === 'l') {   // trazo: puntos [x, y, presión] relativos al compás (x) y al pentagrama (y)
    $p = [];
    foreach (array_slice((array) ($it['p'] ?? []), 0, 4000) as $q) {
      if (!is_array($q) || count($q) < 2) continue;
      $p[] = [num($q[0], -50, 50), num($q[1], -50, 50), num($q[2] ?? .5, 0, 1, 2)];
    }
    if (count($p) < 1) return null;
    return $base + ['w' => num($it['w'] ?? .03, .005, .5), 'p' => $p];
  }
  if ($t === 'x') {   // texto
    $txt = trim(mb_substr((string) ($it['x'] ?? ''), 0, 300));
    if ($txt === '') return null;
    return $base + ['px' => num($it['px'] ?? 0, -50, 50), 'py' => num($it['py'] ?? 0, -50, 50), 'x' => $txt, 'z' => num($it['z'] ?? 1, .3, 4, 2)];
  }
  return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  $id = (string) ($_GET['obra'] ?? '');
  if (!obra_valida($id)) { http_response_code(404); echo '{"error":"Obra no válida"}'; exit; }
  $dir = $NOTAS . '/' . $id;
  echo json_encode(['admin' => $esAdmin, 'mia' => leer_capa(archivo_capa($dir, 'mia', $e)), 'director' => leer_capa(archivo_capa($dir, 'director', $e))],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $raw = file_get_contents('php://input');
  if (strlen($raw) > 2 * 1024 * 1024) { http_response_code(413); echo '{"error":"Demasiadas anotaciones de una vez"}'; exit; }
  $d = json_decode($raw, true) ?: [];
  $id = (string) ($d['obra'] ?? ''); $capa = (string) ($d['capa'] ?? '');
  if (!obra_valida($id)) { http_response_code(404); echo '{"error":"Obra no válida"}'; exit; }
  if ($capa !== 'mia' && $capa !== 'director') { http_response_code(400); echo '{"error":"Capa no válida"}'; exit; }
  if ($capa === 'director' && !$esAdmin) { http_response_code(403); echo '{"error":"Solo la cuenta administradora escribe en la capa del director"}'; exit; }
  $items = [];
  foreach (array_slice((array) ($d['items'] ?? []), 0, 3000) as $it) { $l = limpiar($it); if ($l) $items[] = $l; }
  $dir = $NOTAS . '/' . $id;
  if (!is_dir($dir) && !@mkdir($dir, 0700, true)) { http_response_code(500); echo '{"error":"No puedo crear la carpeta de anotaciones"}'; exit; }
  $f = archivo_capa($dir, $capa, $e);
  if (is_file($f)) {   // copia de la versión anterior (se guardan las 5 últimas)
    $ant = $dir . '/.anteriores';
    if (is_dir($ant) || @mkdir($ant, 0700)) {
      @copy($f, $ant . '/' . basename($f, '.json') . '--' . date('Ymd-His') . '.json');
      $viejas = glob($ant . '/' . basename($f, '.json') . '--*.json') ?: [];
      sort($viejas);
      foreach (array_slice($viejas, 0, max(0, count($viejas) - 5)) as $v) @unlink($v);
    }
  }
  $datos = ['v' => 1, 'obra' => $id, 'capa' => $capa, 'actualizado' => date('c'), 'items' => $items];
  $tmp = $f . '.tmp';
  if (@file_put_contents($tmp, json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) === false || !@rename($tmp, $f)) {
    http_response_code(500); echo '{"error":"No puedo guardar las anotaciones"}'; exit;
  }
  echo json_encode(['ok' => true, 'actualizado' => $datos['actualizado'], 'n' => count($items)]);
  exit;
}

http_response_code(405); echo '{"error":"Método no permitido"}';
