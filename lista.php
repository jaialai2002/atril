<?php
// Atril · lista de obras privada: solo se entrega obras.json a quien ha entrado con una cuenta de Google autorizada.
//
//   GET  lista.php                       -> obras.json si la sesión es válida; si no, 401 {"entrar": true, "cliente": "<ID de cliente>"}
//   POST lista.php {"credential": "…"}  -> comprueba con Google el justificante del botón «Iniciar sesión con Google» y abre sesión (30 días)
//   POST lista.php {"salir": true}       -> cierra la sesión
//   GET  lista.php?fondo                 -> ajustes del fondo animado de la portada (públicos; {} si no hay)
//   POST lista.php {"fondo": {…}}        -> guarda esos ajustes (solo cuentas de 'admin', comprobado aquí con la sesión)
//   POST lista.php {"indice": {…}}       -> guarda la organización del índice: categorías, orden, ocultas (solo 'admin')
//
// Configuración FUERA de la web, en /home/sigan1/atril-config.php (un nivel por encima de esta carpeta):
//   <?php return ['cliente' => '….apps.googleusercontent.com', 'correos' => ['tu-correo@gmail.com'], 'admin' => ['tu-correo@gmail.com']];
// 'correos' pueden ver la lista; 'admin' pueden además cambiar el fondo (si falta 'admin', vale el primero de 'correos').
// El fondo guardado va en /home/sigan1/atril-fondo.json (también fuera de la web).
// La organización del índice va en /home/sigan1/atril-indice.json: {categorias:[…], obras:{id:{cat, orden, oculta}}, vistas:{id: fecha}}
// (vistas = cuándo apareció cada obra por primera vez en la lista: sirve para las marcas NUEVA / ACTUALIZADA). El Taller no la toca.
// La clave para firmar la cookie se crea sola la primera vez en /home/sigan1/.atril-secreto (solo la lee este programa).
// Este archivo se sube a mano una vez; NO se actualiza solo desde GitHub.

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$fuera = dirname(__DIR__);
$cfg = @include $fuera . '/atril-config.php';
if (!is_array($cfg) || empty($cfg['cliente']) || empty($cfg['correos'])) { http_response_code(500); echo '{"error":"Falta o está incompleto atril-config.php"}'; exit; }
$correos = array_map('strtolower', array_map('trim', (array) $cfg['correos']));
$admin = array_map('strtolower', array_map('trim', (array) ($cfg['admin'] ?? [$cfg['correos'][0]])));
$FONDO = $fuera . '/atril-fondo.json';
$INDICE = $fuera . '/atril-indice.json';
function leer_indice($f) {
  $i = json_decode((string) @file_get_contents($f), true);
  if (!is_array($i)) $i = [];
  return ['categorias' => array_values(array_filter((array) ($i['categorias'] ?? []), 'is_string')),
          'obras' => is_array($i['obras'] ?? null) ? $i['obras'] : [], 'vistas' => is_array($i['vistas'] ?? null) ? $i['vistas'] : []];
}
function guardar_indice($f, $i) { return @file_put_contents($f, json_encode($i, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX) !== false; }
// ajustes permitidos del fondo: [mínimo, máximo] (números) o 'b' (sí/no)
$CAMPOS = ['baseHue' => [0, 360], 'hueRange' => [0, 90], 'sat' => [0, 100], 'light' => [4, 30], 'cellSize' => [40, 160],
  'variance' => [0, 0.5], 'speed' => [0, 0.05], 'mouseRadius' => [0, 300], 'lineA' => [0, 0.5], 'dots' => 'b',
  'dotR' => [0.5, 4], 'dotA' => [0, 1], 'wire' => 'b'];
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['fondo'])) {
  $j = @file_get_contents($FONDO);
  echo ($j !== false && json_decode($j) !== null) ? $j : '{}';
  exit;
}
$DIAS = 30;

function secreto($fuera) {
  $f = $fuera . '/.atril-secreto';
  $s = @file_get_contents($f);
  if ($s === false || strlen($s) < 32) {
    $s = bin2hex(random_bytes(32));
    if (@file_put_contents($f, $s, LOCK_EX) === false) { http_response_code(500); echo '{"error":"No puedo guardar la clave de sesión"}'; exit; }
    @chmod($f, 0600);
  }
  return $s;
}
function firma($datos, $fuera) { return hash_hmac('sha256', $datos, secreto($fuera)); }
function b64($s) { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function deb64($s) { return base64_decode(strtr($s, '-_', '+/')); }

function poner_cookie($valor, $hasta) {
  setcookie('atril_sesion', $valor, ['expires' => $hasta, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
}
// correo de la sesión si es válida (y sigue en la configuración); si no, false
function sesion_valida($correos, $fuera) {
  $c = $_COOKIE['atril_sesion'] ?? '';
  $p = explode('.', $c);
  if (count($p) !== 3) return false;
  [$correo, $hasta, $f] = $p;
  if (!hash_equals(firma($correo . '.' . $hasta, $fuera), $f)) return false;
  if ((int) $hasta < time()) return false;
  $e = strtolower(deb64($correo));
  return in_array($e, $correos, true) ? $e : false;   // si quitas un correo de la configuración, su sesión deja de valer
}
// la lista de obras con su organización (categoría, orden, oculta, nueva/actualizada) y la marca 'admin'
// (las ocultas solo se entregan a la cuenta admin, para el modo Organizar)
function entregar_lista($correo, $admin) {
  global $INDICE;
  $l = json_decode((string) @file_get_contents(__DIR__ . '/obras.json'), true);
  if (!is_array($l)) $l = ['obras' => []];
  $esAdmin = in_array($correo, $admin, true);
  $ix = leer_indice($INDICE); $ahora = time(); $cambio = false; $SEMANA = 7 * 86400;
  $obras = [];
  foreach ((array) ($l['obras'] ?? []) as $o) {
    if (!is_array($o) || !isset($o['id'])) continue;
    $id = (string) $o['id'];
    if (!isset($ix['vistas'][$id])) { $ix['vistas'][$id] = $ahora; $cambio = true; }   // primera vez que aparece
    $org = is_array($ix['obras'][$id] ?? null) ? $ix['obras'][$id] : [];
    $o['cat'] = in_array($org['cat'] ?? '', $ix['categorias'], true) ? $org['cat'] : '';
    $o['orden'] = isset($org['orden']) ? (int) $org['orden'] : null;
    $o['oculta'] = !empty($org['oculta']);
    $vista = (int) $ix['vistas'][$id]; $fecha = isset($o['fecha']) ? strtotime((string) $o['fecha']) : false;
    $o['nueva'] = $ahora - $vista < $SEMANA;
    $o['actualizada'] = !$o['nueva'] && $fecha && $fecha > $vista + 86400 && $ahora - $fecha < $SEMANA;
    if ($o['oculta'] && !$esAdmin) continue;
    $obras[] = $o;
  }
  if ($cambio) guardar_indice($INDICE, $ix);
  $l['obras'] = $obras; $l['categorias'] = $ix['categorias']; $l['admin'] = $esAdmin;
  echo json_encode($l, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}
function pedir($url) {
  if (function_exists('curl_init')) {
    $h = curl_init($url);
    curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => true]);
    $r = curl_exec($h); $code = curl_getinfo($h, CURLINFO_HTTP_CODE); curl_close($h);
    return $r === false ? false : ($code === 200 ? $r : '');   // false: sin conexión; '': Google lo rechaza
  }
  $r = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true]]));
  if ($r === false) return false;
  return (isset($http_response_header[0]) && strpos($http_response_header[0], ' 200') !== false) ? $r : '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $d = json_decode(file_get_contents('php://input'), true) ?: [];
  if (!empty($d['salir'])) { poner_cookie('', time() - 3600); echo '{"ok":true}'; exit; }
  if (isset($d['indice'])) {   // guardar la organización del índice: solo con sesión de una cuenta 'admin'
    $e = sesion_valida($correos, $fuera);
    if (!$e || !in_array($e, $admin, true)) { http_response_code(403); echo '{"error":"Solo la cuenta administradora puede organizar el índice"}'; exit; }
    $n = $d['indice'];
    if (!is_array($n)) { http_response_code(400); echo '{"error":"Datos no válidos"}'; exit; }
    $cats = [];
    foreach ((array) ($n['categorias'] ?? []) as $c) {
      $c = trim(preg_replace('/\s+/u', ' ', (string) $c));
      if ($c !== '' && (function_exists('mb_strlen') ? mb_strlen($c) : strlen($c)) <= 60 && !in_array($c, $cats, true) && count($cats) < 100) $cats[] = $c;
    }
    $obras = [];
    foreach ((array) ($n['obras'] ?? []) as $id => $o) {
      if (!preg_match('/^[a-z0-9-]{1,120}$/', (string) $id) || !is_array($o) || count($obras) >= 2000) continue;
      $obras[$id] = ['cat' => in_array($o['cat'] ?? '', $cats, true) ? $o['cat'] : '', 'orden' => (int) ($o['orden'] ?? 0), 'oculta' => !empty($o['oculta'])];
    }
    $ix = leer_indice($INDICE); $ix['categorias'] = $cats; $ix['obras'] = $obras;
    if (!guardar_indice($INDICE, $ix)) { http_response_code(500); echo '{"error":"No puedo guardar atril-indice.json"}'; exit; }
    entregar_lista($e, $admin);
  }
  if (isset($d['fondo'])) {   // guardar el fondo: solo con sesión de una cuenta 'admin'
    $e = sesion_valida($correos, $fuera);
    if (!$e || !in_array($e, $admin, true)) { http_response_code(403); echo '{"error":"Solo la cuenta administradora puede guardar el fondo"}'; exit; }
    if (!is_array($d['fondo'])) { http_response_code(400); echo '{"error":"Ajustes no válidos"}'; exit; }
    $limpio = [];
    foreach ($CAMPOS as $k => $r) {
      if (!array_key_exists($k, $d['fondo'])) continue;
      $v = $d['fondo'][$k];
      if ($r === 'b') $limpio[$k] = (bool) $v;
      elseif (is_numeric($v)) $limpio[$k] = max($r[0], min($r[1], $v + 0));
    }
    if (@file_put_contents($FONDO, json_encode($limpio), LOCK_EX) === false) { http_response_code(500); echo '{"error":"No puedo guardar atril-fondo.json"}'; exit; }
    @chmod($FONDO, 0644);
    echo json_encode(['ok' => true, 'fondo' => $limpio]);
    exit;
  }
  $t = (string) ($d['credential'] ?? '');
  if ($t === '' || strlen($t) > 4096) { http_response_code(400); echo '{"error":"Falta el justificante"}'; exit; }
  // Google comprueba la firma del justificante y nos devuelve su contenido
  $r = pedir('https://oauth2.googleapis.com/tokeninfo?id_token=' . rawurlencode($t));
  $i = $r ? json_decode($r, true) : null;
  // cada fallo con su motivo (el correo que se muestra es el de quien intenta entrar)
  $no = function ($m) { http_response_code(403); echo json_encode(['error' => $m], JSON_UNESCAPED_UNICODE); exit; };
  if (!is_array($i)) $no($r === false ? 'El servidor no ha podido comprobar el inicio de sesión con Google (¿conexión del servidor?)' : 'Google no ha aceptado el justificante; vuelve a intentarlo');
  if (($i['aud'] ?? '') !== $cfg['cliente']) $no('El ID de cliente de atril-config.php no coincide con el de Google Cloud');
  if (!in_array($i['iss'] ?? '', ['accounts.google.com', 'https://accounts.google.com'], true) || (int) ($i['exp'] ?? 0) <= time()) $no('Justificante no válido o caducado; vuelve a intentarlo');
  if (!(($i['email_verified'] ?? '') === 'true' || ($i['email_verified'] ?? '') === true)) $no('Esa cuenta de Google no tiene el correo verificado');
  if (!in_array(strtolower($i['email'] ?? ''), $correos, true)) $no('La cuenta ' . ($i['email'] ?? '?') . ' no está en la lista de correos de atril-config.php');
  $hasta = time() + $DIAS * 86400;
  $correo = b64(strtolower($i['email']));
  poner_cookie($correo . '.' . $hasta . '.' . firma($correo . '.' . $hasta, $fuera), $hasta);
  entregar_lista(strtolower($i['email']), $admin);
}

if ($e = sesion_valida($correos, $fuera)) entregar_lista($e, $admin);
http_response_code(401);
echo json_encode(['entrar' => true, 'cliente' => $cfg['cliente']]);
