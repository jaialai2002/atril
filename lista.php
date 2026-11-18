<?php
// Atril · lista de obras privada: solo se entrega obras.json a quien ha entrado con una cuenta de Google autorizada.
//
//   GET  lista.php                       -> obras.json si la sesión es válida; si no, 401 {"entrar": true, "cliente": "<ID de cliente>"}
//   POST lista.php {"credential": "…"}  -> comprueba con Google el justificante del botón «Iniciar sesión con Google» y abre sesión (30 días)
//   POST lista.php {"salir": true}       -> cierra la sesión
//
// Configuración FUERA de la web, en /home/sigan1/atril-config.php (un nivel por encima de esta carpeta):
//   <?php return ['cliente' => '….apps.googleusercontent.com', 'correos' => ['tu-correo@gmail.com']];
// La clave para firmar la cookie se crea sola la primera vez en /home/sigan1/.atril-secreto (solo la lee este programa).
// Este archivo se sube a mano una vez; NO se actualiza solo desde GitHub.

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$fuera = dirname(__DIR__);
$cfg = @include $fuera . '/atril-config.php';
if (!is_array($cfg) || empty($cfg['cliente']) || empty($cfg['correos'])) { http_response_code(500); echo '{"error":"Falta o está incompleto atril-config.php"}'; exit; }
$correos = array_map('strtolower', array_map('trim', (array) $cfg['correos']));
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
function sesion_valida($correos, $fuera) {
  $c = $_COOKIE['atril_sesion'] ?? '';
  $p = explode('.', $c);
  if (count($p) !== 3) return false;
  [$correo, $hasta, $f] = $p;
  if (!hash_equals(firma($correo . '.' . $hasta, $fuera), $f)) return false;
  if ((int) $hasta < time()) return false;
  return in_array(strtolower(deb64($correo)), $correos, true);   // si quitas un correo de la configuración, su sesión deja de valer
}
function entregar_lista() {
  $f = __DIR__ . '/obras.json';
  $j = @file_get_contents($f);
  echo $j !== false ? $j : '{"obras":[]}';
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
  entregar_lista();
}

if (sesion_valida($correos, $fuera)) entregar_lista();
http_response_code(401);
echo json_encode(['entrar' => true, 'cliente' => $cfg['cliente']]);
