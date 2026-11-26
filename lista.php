<?php
// Atril · lista de obras privada: solo se entrega obras.json a quien ha entrado con una cuenta de Google autorizada.
//
//   GET  lista.php                       -> obras.json si la sesión es válida; si no, 401 {"entrar": true, "cliente": "<ID de cliente>"}
//   POST lista.php clave_app=…&ir=<id> -> entrada desde la app Repertorio (formulario, no JSON): sesión y vuelta a la portada u obra
//   POST lista.php {"credential": "…"}  -> comprueba con Google el justificante del botón «Iniciar sesión con Google» y abre sesión (30 días)
//   POST lista.php {"salir": true}       -> cierra la sesión
//   GET  lista.php?fondo                 -> ajustes del fondo animado de la portada (públicos; {} si no hay)
//   POST lista.php {"fondo": {…}}        -> guarda esos ajustes (solo cuentas de 'admin', comprobado aquí con la sesión)
//   POST lista.php {"indice": {…}}       -> guarda la organización del índice: categorías, orden, ocultas, voces, autor, idioma (solo 'admin')
//   POST lista.php {"borrar": "<id>"}    -> quita una obra del Atril (solo 'admin'): su carpeta pasa a la papelera de fuera de la web
//                                        (se vacía a los 30 días), sale de obras.json y se apunta en .htborradas.json para el Taller
//   POST lista.php {"visita": "<obra>"}  -> cuenta una apertura de esa obra (lo envía la página; sin IP ni datos personales)
//   GET  lista.php?estadisticas          -> visitas y consumo aproximado por obra (solo 'admin')
//
// Configuración FUERA de la web, en /home/sigan1/atril-config.php (un nivel por encima de esta carpeta):
//   <?php return ['cliente' => '….apps.googleusercontent.com', 'correos' => ['tu-correo@gmail.com'], 'admin' => ['tu-correo@gmail.com']];
// 'correos' pueden ver la lista; 'admin' pueden además cambiar el fondo (si falta 'admin', vale el primero de 'correos').
// Opcional, para la app Repertorio: 'apps' => ['clave (8+ caracteres)' => 'correo de la lista'] (ver «entrada desde la app»).
// El fondo guardado va en /home/sigan1/atril-fondo.json (también fuera de la web).
// La organización del índice va en /home/sigan1/atril-indice.json: {categorias:[…], obras:{id:{cat, orden, oculta, voces, autor, idioma}}, vistas:{id: fecha}}
// (voces = cómo se describen sus voces en el índice, escrito a mano en Organizar; vacío = la que calcula la página;
//  autor = el que se ve en el índice si el de la partitura falta o está mal; idioma = el de la letra original, vacío = el que diga la partitura)
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
$ESTAD = $fuera . '/atril-estadisticas.json';   // {obras:{id:{total, ultima, dias:{AAAA-MM-DD: n}}}}: solo contadores
date_default_timezone_set('Europe/Madrid');
// obras borradas desde el editor del índice: la carpeta va a la papelera (fuera de la web, se vacía a los 30 días) y el id
// se apunta en .htborradas.json, dentro de la web pero que Apache no sirve (empieza por «.ht»), para que el Taller lo lea por
// FTP y pregunte si las borra también del «Atril web» del Mac. Si el Taller vuelve a publicar una, la quita de la lista.
$PAPELERA = $fuera . '/atril-papelera';
$BORRADAS = __DIR__ . '/.htborradas.json';
function leer_borradas($f) { $b = json_decode((string) @file_get_contents($f), true); return is_array($b) && is_array($b['borradas'] ?? null) ? $b : ['v' => 1, 'borradas' => []]; }
function borrar_arbol($d) {
  if (is_link($d) || !is_dir($d)) return @unlink($d);
  foreach (scandir($d) ?: [] as $n) if ($n !== '.' && $n !== '..') borrar_arbol($d . '/' . $n);
  return @rmdir($d);
}
function vaciar_papelera($dir) {   // lo que lleva más de 30 días
  if (!is_dir($dir)) return;
  foreach (scandir($dir) ?: [] as $n) {
    if ($n === '.' || $n === '..') continue;
    if (filemtime($dir . '/' . $n) < time() - 30 * 86400) borrar_arbol($dir . '/' . $n);
  }
}
// contar una apertura: público (la página lo envía al abrir una obra), pero solo para obras que existen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && strpos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'json') !== false) {
  $dv = json_decode((string) file_get_contents('php://input'), true);
  if (is_array($dv) && isset($dv['visita'])) {
    $id = (string) $dv['visita'];
    if (!preg_match('/^[a-z0-9-]{1,120}$/', $id) || !is_file(__DIR__ . '/obras/' . $id . '/obra.json')) { http_response_code(400); echo '{"error":"Obra desconocida"}'; exit; }
    $fh = @fopen($ESTAD, 'c+');
    if ($fh && flock($fh, LOCK_EX)) {
      $e = json_decode((string) stream_get_contents($fh), true); if (!is_array($e)) $e = ['obras' => []];
      $o = $e['obras'][$id] ?? ['total' => 0, 'dias' => []];
      $hoy = date('Y-m-d'); $o['total'] = (int) $o['total'] + 1; $o['ultima'] = time();
      $o['dias'][$hoy] = (int) ($o['dias'][$hoy] ?? 0) + 1;
      $limite = date('Y-m-d', strtotime('-120 days')); foreach (array_keys($o['dias']) as $d) if ($d < $limite) unset($o['dias'][$d]);   // se guardan 4 meses de días
      $e['obras'][$id] = $o;
      ftruncate($fh, 0); rewind($fh); fwrite($fh, json_encode($e)); fflush($fh); flock($fh, LOCK_UN);
    }
    if ($fh) fclose($fh);
    echo '{"ok":true}'; exit;
  }
}
function leer_indice($f) {
  $i = json_decode((string) @file_get_contents($f), true);
  if (!is_array($i)) $i = [];
  return ['categorias' => array_values(array_filter((array) ($i['categorias'] ?? []), 'is_string')),
          'obras' => is_array($i['obras'] ?? null) ? $i['obras'] : [], 'vistas' => is_array($i['vistas'] ?? null) ? $i['vistas'] : [],
          'conciertos' => array_values(array_filter((array) ($i['conciertos'] ?? []), 'is_array')),
          'personas' => array_values(array_filter((array) ($i['personas'] ?? []), 'is_array')), 'acceso_v' => (int) ($i['acceso_v'] ?? 0)];
}
// acceso de cada obra (ver archivo.php): 'enlace' | 'yo' | 'invitados'. Sin decidir: 'yo' (obra recién subida); antes de
// existir la portería (acceso_v = 0) todo era 'enlace', y la primera vez se apuntan así las obras que ya estaban.
function acceso_de($ix, $id) {
  $a = (string) ($ix['obras'][$id]['acceso'] ?? (empty($ix['acceso_v']) ? 'enlace' : 'yo'));
  return in_array($a, ['enlace', 'yo', 'invitados'], true) ? $a : 'yo';
}
function migrar_acceso($INDICE) {
  $ix = leer_indice($INDICE);
  if (!empty($ix['acceso_v'])) return;
  foreach (scandir(__DIR__ . '/obras') ?: [] as $id)
    if (preg_match('/^[a-z0-9-]{1,120}$/', $id) && is_dir(__DIR__ . '/obras/' . $id)) {
      if (!is_array($ix['obras'][$id] ?? null)) $ix['obras'][$id] = [];
      if (!isset($ix['obras'][$id]['acceso'])) $ix['obras'][$id]['acceso'] = 'enlace';
    }
  $ix['acceso_v'] = 1;
  guardar_indice($INDICE, $ix);
}
function correo_ok($c) { $c = strtolower(trim((string) $c)); return filter_var($c, FILTER_VALIDATE_EMAIL) ? $c : ''; }
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
migrar_acceso($INDICE);
// pueden entrar: 'correos' de atril-config.php y las personas dadas de alta en Organizar (solo ven las obras a las que se
// las invita; 'admin' lo ve todo)
foreach (leer_indice($INDICE)['personas'] as $pp) if (($c = correo_ok($pp['correo'] ?? '')) !== '' && !in_array($c, $correos, true)) $correos[] = $c;

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
// las pistas de una obra, para que la página describa sus voces («SATB», «Dúo SB», «3 voces»…): nombre, voz (del Taller,
// true/false, o null si la obra es anterior) y, de su <score-part> en la partitura, <instrument-sound> e <instrument-name>.
// De la partitura solo se lee el principio (la lista de partes va arriba).
function pistas_de($id) {
  if (!preg_match('/^[a-z0-9-]{1,120}$/', (string) $id)) return [];
  $dir = __DIR__ . '/obras/' . $id;
  $c = json_decode((string) @file_get_contents($dir . '/obra.json'), true);
  if (!is_array($c) || !is_array($c['pistas'] ?? null)) return [];
  $info = [];
  $h = @fopen($dir . '/' . basename((string) ($c['partitura'] ?? 'partitura.musicxml')), 'r');
  if ($h) {
    $x = (string) fread($h, 393216); fclose($h);
    if (preg_match('~<part-list>(.*?)</part-list>~s', $x, $m) && preg_match_all('~<score-part id="([^"]+)">(.*?)</score-part>~s', $m[1], $sp, PREG_SET_ORDER))
      foreach ($sp as $q) {
        $g = function ($re) use ($q) { return preg_match($re, $q[2], $r) ? html_entity_decode(trim($r[1]), ENT_QUOTES | ENT_XML1, 'UTF-8') : ''; };
        $info[$q[1]] = ['s' => $g('~<instrument-sound>([^<]*)<~'), 'i' => $g('~<instrument-name>([^<]*)<~')];
      }
  }
  $out = [];
  foreach ($c['pistas'] as $p) {
    if (!is_array($p)) continue;
    $q = $info[(string) ($p['parte'] ?? '')] ?? ['s' => '', 'i' => ''];
    $out[] = ['n' => (string) ($p['nombre'] ?? ''), 'v' => isset($p['voz']) ? (bool) $p['voz'] : null, 's' => $q['s'], 'i' => $q['i']];
  }
  return $out;
}
// idioma de la letra original, del campo que deja TRANS en la partitura (cantamus-letra-original: {"v":1,"idioma":"cze"…})
function idioma_de($id) {
  if (!preg_match('/^[a-z0-9-]{1,120}$/', (string) $id)) return '';
  $dir = __DIR__ . '/obras/' . $id;
  $c = json_decode((string) @file_get_contents($dir . '/obra.json'), true);
  $h = @fopen($dir . '/' . basename((string) ($c['partitura'] ?? 'partitura.musicxml')), 'r');
  if (!$h) return '';
  $x = (string) fread($h, 393216); fclose($h);
  if (!preg_match('~name="cantamus-letra-original"\s*>\{"v":\d+,"idioma":"(\w+)"~', $x, $m)) return '';
  return ['cze' => 'Checo', 'ita' => 'Italiano', 'lat' => 'Latín', 'deu' => 'Alemán', 'fra' => 'Francés', 'eng' => 'Inglés'][$m[1]] ?? '';
}
// la lista de obras con su organización (categoría, orden, oculta, nueva/actualizada) y la marca 'admin'
// (las ocultas solo se entregan a la cuenta admin, para el modo Organizar)
function entregar_lista($correo, $admin) {
  global $INDICE, $BORRADAS;
  $l = json_decode((string) @file_get_contents(__DIR__ . '/obras.json'), true);
  if (!is_array($l)) $l = ['obras' => []];
  $esAdmin = in_array($correo, $admin, true);
  $ix = leer_indice($INDICE); $ahora = time(); $cambio = false; $SEMANA = 7 * 86400;
  $obras = [];
  $bor = leer_borradas($BORRADAS)['borradas'];
  foreach ((array) ($l['obras'] ?? []) as $o) {
    if (!is_array($o) || !isset($o['id'])) continue;
    $id = (string) $o['id'];
    if (isset($bor[$id])) continue;   // borrada desde el índice (aunque un Taller viejo la vuelva a poner en obras.json)
    if (!isset($ix['vistas'][$id])) { $ix['vistas'][$id] = $ahora; $cambio = true; }   // primera vez que aparece
    $org = is_array($ix['obras'][$id] ?? null) ? $ix['obras'][$id] : [];
    $o['cat'] = in_array($org['cat'] ?? '', $ix['categorias'], true) ? $org['cat'] : '';
    $o['orden'] = isset($org['orden']) ? (int) $org['orden'] : null;
    $o['oculta'] = !empty($org['oculta']);
    $o['voces'] = trim((string) ($org['voces'] ?? ''));
    $o['obra_partitura'] = (string) ($o['obra'] ?? '');                    // el título de obra.json (de la partitura)
    if (trim((string) ($org['titulo'] ?? '')) !== '') $o['obra'] = trim((string) $org['titulo']);   // el del índice, solo para mostrar
    $o['titulo_org'] = trim((string) ($org['titulo'] ?? ''));
    $o['autor_partitura'] = (string) ($o['autor'] ?? '');                  // el de obra.json (de la partitura)
    if (trim((string) ($org['autor'] ?? '')) !== '') $o['autor'] = trim((string) $org['autor']);
    $o['autor_org'] = trim((string) ($org['autor'] ?? ''));
    $o['idioma'] = trim((string) ($org['idioma'] ?? ''));
    $o['idioma_auto'] = idioma_de($id);
    $t = @filemtime(__DIR__ . '/obras/' . $id . '/obra.json');   // cuándo llegó al servidor (la última subida)
    $o['subida'] = $t ? date('c', $t) : '';
    $o['pistas'] = pistas_de($id);
    $vista = (int) $ix['vistas'][$id]; $fecha = isset($o['fecha']) ? strtotime((string) $o['fecha']) : false;
    $o['nueva'] = $ahora - $vista < $SEMANA;
    $o['actualizada'] = !$o['nueva'] && $fecha && $fecha > $vista + 86400 && $ahora - $fecha < $SEMANA;
    if ($o['oculta'] && !$esAdmin) continue;
    $o['acceso'] = acceso_de($ix, $id);
    $inv = array_map('strtolower', (array) ($org['invitados'] ?? []));
    if (!$esAdmin && !($o['acceso'] === 'invitados' && in_array($correo, $inv, true))) continue;   // invitados: solo sus obras
    if ($esAdmin) $o['invitados'] = array_values($inv);
    $obras[] = $o;
  }
  if ($cambio) guardar_indice($INDICE, $ix);
  $l['obras'] = $obras; $l['categorias'] = $ix['categorias']; $l['admin'] = $esAdmin;
  if ($esAdmin) $l['personas'] = $ix['personas'];
  if ($esAdmin) {   // conciertos (listas ordenadas de obras): de momento solo los ve la cuenta admin
    $hay = []; foreach ($obras as $o) if (isset($o['id'])) $hay[(string) $o['id']] = true;
    $l['conciertos'] = [];
    foreach ($ix['conciertos'] as $c) {
      $lista = [];
      foreach ((array) ($c['obras'] ?? []) as $x) if (isset($hay[(string) $x])) $lista[] = (string) $x;
      $l['conciertos'][] = ['id' => (string) ($c['id'] ?? ''), 'nombre' => (string) ($c['nombre'] ?? ''), 'obras' => $lista];
    }
  }
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

// entrada desde la app Repertorio: su navegador integrado no puede usar el botón de Google (Google lo prohíbe en apps).
// La app envía un formulario  clave_app=…&ir=<id>  y aquí se abre la misma sesión de 30 días que con Google para el
// correo asociado a esa clave, que tiene que seguir en 'correos'. En atril-config.php:
//   'apps' => ['una-clave-de-al-menos-8-caracteres' => 'tu-correo@gmail.com'],
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clave_app'])) {
  $k = (string) $_POST['clave_app']; $quien = null; $motivo = '';
  $apps = (array) ($cfg['apps'] ?? []);
  // freno contra quien pruebe claves: 5 fallos desde la misma conexión = 1 hora bloqueada.
  // Se guarda en /home/sigan1/atril-intentos.json solo un resumen cifrado de la conexión, nunca la IP.
  $INTENTOS = $fuera . '/atril-intentos.json';
  $quienIp = substr(hash_hmac('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? ''), secreto($fuera)), 0, 24);
  $fi = fopen($INTENTOS, 'c+');
  if ($fi) { flock($fi, LOCK_EX); $int = json_decode((string) stream_get_contents($fi), true); if (!is_array($int)) $int = []; } else $int = [];
  $ahora = time();
  foreach ($int as $h => $v) if (($v['hasta'] ?? 0) < $ahora && ($v['ultimo'] ?? 0) < $ahora - 3600) unset($int[$h]);
  $yo = $int[$quienIp] ?? ['n' => 0, 'ultimo' => 0, 'hasta' => 0];
  $guardarIntentos = function ($int) use ($fi) { if ($fi) { ftruncate($fi, 0); rewind($fi); fwrite($fi, json_encode($int)); fflush($fi); flock($fi, LOCK_UN); fclose($fi); } };
  if ($yo['hasta'] > $ahora) {
    $guardarIntentos($int);
    http_response_code(429);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><body style="font:18px/1.5 system-ui;background:#18191d;color:#e7e4dc;padding:40px">Demasiados intentos con una clave equivocada.<br>La entrada desde Repertorio está bloqueada en esta conexión durante ' . ceil(($yo['hasta'] - $ahora) / 60) . ' minutos más.</body>';
    exit;
  }
  if (!$apps) $motivo = 'atril-config.php no tiene la línea <b>\'apps\'</b> dentro de <code>return [ … ];</code> (o el servidor aún usa la versión anterior: espera dos minutos y vuelve a probar).';
  foreach ($apps as $cl => $co) {
    if (!is_string($cl) || mb_strlen($cl) < 8) { $motivo = $motivo ?: 'La clave escrita en \'apps\' tiene menos de 8 caracteres.'; continue; }
    if (hash_equals($cl, $k)) { $quien = strtolower(trim((string) $co)); break; }
  }
  if ($quien === null && $motivo === '') $motivo = 'La clave de Repertorio no coincide con la de \'apps\'. Repertorio ha enviado una de ' . mb_strlen($k) . ' caracteres' . ($k !== trim($k) ? ', con espacios al principio o al final' : '') . '; la de atril-config.php tiene ' . implode(' / ', array_map(fn($c) => mb_strlen((string) $c), array_keys($apps))) . '. Fíjate en mayúsculas, espacios y letras parecidas.';
  if ($quien !== null && !in_array($quien, $correos, true)) $motivo = 'La clave es correcta, pero su correo (' . htmlspecialchars($quien) . ') no está en \'correos\'.';
  if ($motivo === '') { unset($int[$quienIp]); $guardarIntentos($int); }
  if ($motivo !== '') {
    $yo['n'] = ($yo['ultimo'] > $ahora - 3600 ? $yo['n'] : 0) + 1; $yo['ultimo'] = $ahora;
    if ($yo['n'] >= 5) { $yo['hasta'] = $ahora + 3600; $yo['n'] = 0; }
    $int[$quienIp] = $yo; $guardarIntentos($int);
    $motivo .= $yo['hasta'] > $ahora ? '<br><br><b>Era el quinto intento fallido: la entrada desde Repertorio queda bloqueada una hora en esta conexión.</b>' : '<br><br>Intentos fallidos: ' . $yo['n'] . ' de 5 (al quinto se bloquea una hora).';
    usleep(500000);
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><body style="font:18px/1.5 system-ui;background:#18191d;color:#e7e4dc;padding:40px">No se ha podido entrar en el Atril con la clave de Repertorio.<br><br>' . $motivo . '<br><br><small style="color:#9aa1ab">Ajusta la clave en Repertorio → Ajustes → Atril o en /home/sigan1/atril-config.php.</small></body>';
    exit;
  }
  $hasta = time() + $DIAS * 86400;
  $correo = b64($quien);
  poner_cookie($correo . '.' . $hasta . '.' . firma($correo . '.' . $hasta, $fuera), $hasta);
  // marca «esto es Repertorio» en su navegador: si la sesión caduca, la página de entrada no enseña el botón de Google
  // (dentro de la app no funciona) sino cómo volver a entrar desde Repertorio. La lee index.html (no es httponly).
  setcookie('atril_app', 'repertorio', ['expires' => time() + 5 * 365 * 86400, 'path' => '/', 'secure' => true, 'samesite' => 'Lax']);
  $ir = (string) ($_POST['ir'] ?? '');
  header('Location: /' . (preg_match('/^[a-z0-9-]{1,120}$/', $ir) ? $ir : ''), true, 303);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $d = json_decode(file_get_contents('php://input'), true) ?: [];
  if (!empty($d['salir'])) { poner_cookie('', time() - 3600); echo '{"ok":true}'; exit; }
  if (isset($d['borrar'])) {   // quitar una obra del Atril: solo con sesión de una cuenta 'admin'
    $e = sesion_valida($correos, $fuera);
    if (!$e || !in_array($e, $admin, true)) { http_response_code(403); echo '{"error":"Solo la cuenta administradora puede borrar obras"}'; exit; }
    $id = (string) $d['borrar'];
    if (!preg_match('/^[a-z0-9][a-z0-9-]{0,119}$/', $id)) { http_response_code(400); echo '{"error":"Obra no válida"}'; exit; }
    $l = json_decode((string) @file_get_contents(__DIR__ . '/obras.json'), true);
    if (!is_array($l)) $l = ['obras' => []];
    $titulo = ''; $quedan = [];
    foreach ((array) ($l['obras'] ?? []) as $o) { if (is_array($o) && ($o['id'] ?? '') === $id) $titulo = (string) ($o['obra'] ?? ''); else $quedan[] = $o; }
    $dir = __DIR__ . '/obras/' . $id;
    if (is_dir($dir)) {
      if (!is_dir($PAPELERA) && !@mkdir($PAPELERA, 0700, true)) { http_response_code(500); echo '{"error":"No puedo crear la papelera"}'; exit; }
      $dest = $PAPELERA . '/' . $id . '--' . date('Ymd-His');
      if (!@rename($dir, $dest)) { http_response_code(500); echo '{"error":"No puedo mover la obra a la papelera"}'; exit; }
      @touch($dest);
    }
    $l['obras'] = $quedan; $l['actualizado'] = date('c');
    $tmp = __DIR__ . '/obras.json.tmp';
    if (@file_put_contents($tmp, json_encode($l, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n") === false || !@rename($tmp, __DIR__ . '/obras.json')) {
      http_response_code(500); echo '{"error":"La obra está en la papelera, pero no puedo reescribir obras.json"}'; exit;
    }
    $b = leer_borradas($BORRADAS);
    $b['borradas'][$id] = ['fecha' => date('c'), 'obra' => $titulo];
    @file_put_contents($BORRADAS, json_encode($b, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n", LOCK_EX);
    $ix = leer_indice($INDICE); unset($ix['obras'][$id]); guardar_indice($INDICE, $ix);
    vaciar_papelera($PAPELERA);
    entregar_lista($e, $admin);
  }
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
    $previo = leer_indice($INDICE);
    $personas = []; $hay = [];
    foreach ((array) ($n['personas'] ?? $previo['personas']) as $pp) {
      if (!is_array($pp) || count($personas) >= 500) continue;
      $c = correo_ok($pp['correo'] ?? ''); if ($c === '' || isset($hay[$c])) continue;
      $nom = trim(preg_replace('/\s+/u', ' ', strip_tags((string) ($pp['nombre'] ?? ''))));
      $personas[] = ['nombre' => function_exists('mb_substr') ? mb_substr($nom, 0, 60) : substr($nom, 0, 60), 'correo' => $c]; $hay[$c] = true;
    }
    $obras = [];
    foreach ((array) ($n['obras'] ?? []) as $id => $o) {
      if (!preg_match('/^[a-z0-9-]{1,120}$/', (string) $id) || !is_array($o) || count($obras) >= 2000) continue;
      $v = trim(preg_replace('/\s+/u', ' ', strip_tags((string) ($o['voces'] ?? ''))));
      if (function_exists('mb_substr')) $v = mb_substr($v, 0, 60); else $v = substr($v, 0, 60);
      $obras[$id] = ['cat' => in_array($o['cat'] ?? '', $cats, true) ? $o['cat'] : '', 'orden' => (int) ($o['orden'] ?? 0), 'oculta' => !empty($o['oculta'])];
      if ($v !== '') $obras[$id]['voces'] = $v;
      // acceso: si no llega (una página antigua), se queda el que había
      $ac = (string) ($o['acceso'] ?? ($previo['obras'][$id]['acceso'] ?? ''));
      if (in_array($ac, ['enlace', 'yo', 'invitados'], true)) $obras[$id]['acceso'] = $ac;
      $inv = array_key_exists('invitados', $o) ? (array) $o['invitados'] : (array) ($previo['obras'][$id]['invitados'] ?? []);
      $inv = array_values(array_unique(array_filter(array_map('correo_ok', $inv), function ($c) use ($hay) { return $c !== '' && isset($hay[$c]); })));
      if ($inv) $obras[$id]['invitados'] = array_slice($inv, 0, 500);
      foreach (['titulo' => 120, 'autor' => 80, 'idioma' => 30] as $campo => $max) {
        $t = trim(preg_replace('/\s+/u', ' ', strip_tags((string) ($o[$campo] ?? ''))));
        $t = function_exists('mb_substr') ? mb_substr($t, 0, $max) : substr($t, 0, $max);
        if ($t !== '') $obras[$id][$campo] = $t;
      }
    }
    $concs = [];
    if (isset($n['conciertos'])) {
      foreach ((array) $n['conciertos'] as $c) {
        if (!is_array($c) || count($concs) >= 50) continue;
        $nom = trim(preg_replace('/\s+/u', ' ', strip_tags((string) ($c['nombre'] ?? ''))));
        $nom = function_exists('mb_substr') ? mb_substr($nom, 0, 60) : substr($nom, 0, 60);
        $cid = (string) ($c['id'] ?? '');
        if ($nom === '' || !preg_match('/^[a-z0-9-]{1,60}$/', $cid) || isset($concs[$cid])) continue;
        $lista = [];
        foreach ((array) ($c['obras'] ?? []) as $x) { $x = (string) $x; if (preg_match('/^[a-z0-9-]{1,120}$/', $x) && !in_array($x, $lista, true) && count($lista) < 200) $lista[] = $x; }
        $concs[$cid] = ['id' => $cid, 'nombre' => $nom, 'obras' => $lista];
      }
    }
    foreach ($previo['obras'] as $id => $po)   // obras que la página no ha enviado: conservan su acceso
      if (!isset($obras[$id]) && is_array($po) && isset($po['acceso'])) $obras[$id] = array_intersect_key($po, ['acceso' => 1, 'invitados' => 1]);
    $ix = leer_indice($INDICE); $ix['categorias'] = $cats; $ix['obras'] = $obras; $ix['personas'] = $personas;
    if (isset($n['conciertos'])) $ix['conciertos'] = array_values($concs);
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

// título y autor del índice de una obra (Organizar), para la cabecera de la obra: solo lo que ya se ve al abrirla
if (isset($_GET['titulo'])) {
  $id = (string) $_GET['titulo'];
  if (!preg_match('/^[a-z0-9-]{1,120}$/', $id)) { http_response_code(400); echo '{}'; exit; }
  $org = leer_indice($INDICE)['obras'][$id] ?? [];
  $r = [];
  foreach (['titulo', 'autor'] as $c) { $t = trim((string) ($org[$c] ?? '')); if ($t !== '') $r[$c] = $t; }
  echo json_encode((object) $r, JSON_UNESCAPED_UNICODE); exit;
}

// estadísticas: solo la cuenta admin
if (isset($_GET['estadisticas'])) {
  $e = sesion_valida($correos, $fuera);
  if (!$e || !in_array($e, $admin, true)) { http_response_code(403); echo '{"error":"Solo la cuenta administradora"}'; exit; }
  $est = json_decode((string) @file_get_contents($ESTAD), true); $est = is_array($est['obras'] ?? null) ? $est['obras'] : [];
  $lista = json_decode((string) @file_get_contents(__DIR__ . '/obras.json'), true); $titulos = [];
  foreach ((array) ($lista['obras'] ?? []) as $o) if (isset($o['id'])) $titulos[(string) $o['id']] = $o;
  $ixe = leer_indice($INDICE);
  $hoy = date('Y-m-d'); $d7 = date('Y-m-d', strtotime('-6 days')); $d30 = date('Y-m-d', strtotime('-29 days'));
  $filas = [];
  foreach (array_unique(array_merge(array_keys($titulos), array_keys($est))) as $id) {
    $dir = __DIR__ . '/obras/' . $id; $peso = 0;
    if (preg_match('/^[a-z0-9-]{1,120}$/', (string) $id) && is_dir($dir)) foreach (scandir($dir) as $f) if (is_file("$dir/$f") && strpos($f, 'tarjeta.') !== 0) $peso += filesize("$dir/$f");
    $o = $est[$id] ?? ['total' => 0, 'dias' => []]; $h = 0; $s7 = 0; $s30 = 0;
    foreach ((array) $o['dias'] as $d => $n) { if ($d === $hoy) $h += $n; if ($d >= $d7) $s7 += $n; if ($d >= $d30) $s30 += $n; }
    $tix = trim((string) ($ixe['obras'][$id]['titulo'] ?? ''));
    $filas[] = ['id' => (string) $id, 'obra' => $tix !== '' ? $tix : ($titulos[$id]['obra'] ?? $id), 'autor' => $titulos[$id]['autor'] ?? '', 'existe' => is_dir($dir), 'peso' => $peso,
      'hoy' => $h, 'semana' => $s7, 'mes' => $s30, 'total' => (int) $o['total'], 'ultima' => $o['ultima'] ?? null];
  }
  echo json_encode(['obras' => $filas, 'ahora' => time()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit;
}

if ($e = sesion_valida($correos, $fuera)) entregar_lista($e, $admin);
http_response_code(401);
echo json_encode(['entrar' => true, 'cliente' => $cfg['cliente']]);
