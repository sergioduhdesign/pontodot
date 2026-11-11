<?php
/* GEO2030 · API do painel de conteúdo (planilha → site). Acesso só com senha. */
@ini_set('memory_limit', '256M'); @set_time_limit(60);
$dir = __DIR__; $cd = $dir . '/conteudo'; $up = $dir . '/uploads';
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_name('geoadm'); session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']); session_start();
function out($a, $c = 200) { http_response_code($c); echo json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
function cfg() { $f = __DIR__ . '/_config.php'; return is_file($f) ? (include $f) : []; }
function auth() { if (empty($_SESSION['ok'])) out(['ok' => false, 'erro' => 'Faça login.', 'login' => true], 401); }
$in = json_decode(file_get_contents('php://input') ?: '[]', true) ?: [];
$a = $_GET['a'] ?? ($in['a'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_SERVER['HTTP_X_GEO_ADMIN'] ?? '') !== '1') out(['ok' => false, 'erro' => 'Requisição inválida.'], 400);

/* ---------- acesso ---------- */
if ($a === 'login') {
  $f = $cd . '/.tent.json'; $t = is_file($f) ? (json_decode(file_get_contents($f), true) ?: []) : [];
  $u = strtolower(trim((string)($in['usuario'] ?? ''))); $k = md5(($_SERVER['REMOTE_ADDR'] ?? '') . 'geo'); $now = time();
  $t[$k] = array_values(array_filter($t[$k] ?? [], function ($x) use ($now) { return $x > $now - 900; }));
  if (count($t[$k]) >= 6) out(['ok' => false, 'erro' => 'Muitas tentativas. Aguarde 15 minutos.'], 429);
  $h = (cfg()['usuarios'] ?? [])[$u] ?? '';
  if ($h && password_verify((string)($in['senha'] ?? ''), $h)) { session_regenerate_id(true); $_SESSION['ok'] = 1; $_SESSION['u'] = $u; $t[$k] = []; @file_put_contents($f, json_encode($t)); out(['ok' => true, 'usuario' => $u]); }
  $t[$k][] = $now; @file_put_contents($f, json_encode($t)); usleep(700000); out(['ok' => false, 'erro' => 'Usuário ou senha incorretos.'], 401);
}
if ($a === 'sair') { $_SESSION = []; session_destroy(); out(['ok' => true]); }
if ($a === 'sessao') out(['ok' => !empty($_SESSION['ok']), 'usuario' => $_SESSION['u'] ?? '']);
auth();

if ($a === 'senha') {
  $n = (string)($in['nova'] ?? ''); if (mb_strlen($n) < 8) out(['ok' => false, 'erro' => 'Use pelo menos 8 caracteres.'], 422);
  $c = cfg(); $u = $_SESSION['u'] ?? ''; if (!password_verify((string)($in['atual'] ?? ''), $c['usuarios'][$u] ?? '')) out(['ok' => false, 'erro' => 'Senha atual incorreta.'], 422);
  $c['usuarios'][$u] = password_hash($n, PASSWORD_DEFAULT);
  file_put_contents(__DIR__ . '/_config.php', "<?php\nreturn " . var_export($c, true) . ";\n", LOCK_EX); out(['ok' => true]);
}
if ($a === 'atual') { $t = @file_get_contents($cd . '/compromissos.json'); out(['ok' => true, 'conteudo' => $t ? json_decode($t, true) : null, 'imagens_ok' => function_exists('imagecreatefromstring')]); }

/* ---------- imagens: baixa pelo endereço, valida e otimiza ---------- */
function ehLocal($u) { return (bool)preg_match('#^(img|uploads)/[\w./-]+$#', $u) && strpos($u, '..') === false; }
function normaliza($u) {
  $u = trim($u);
  if (preg_match('#drive\.google\.com/file/d/([\w-]+)#', $u, $m) || preg_match('#drive\.google\.com/(?:open|uc)\?[^#]*id=([\w-]+)#', $u, $m)) return 'https://drive.google.com/uc?export=download&id=' . $m[1];
  if (preg_match('#^https?://(www\.)?dropbox\.com/#i', $u)) { $u = preg_replace('/([?&])dl=0/', '$1dl=1', $u); if (strpos($u, 'dl=1') === false) $u .= (strpos($u, '?') === false ? '?' : '&') . 'dl=1'; }
  return $u;
}
function baixa($url, &$erro) {
  for ($hop = 0; $hop < 5; $hop++) {
    $p = parse_url($url); $sch = strtolower($p['scheme'] ?? ''); $host = $p['host'] ?? '';
    if (!in_array($sch, ['http', 'https'], true) || $host === '') { $erro = 'Endereço inválido.'; return null; }
    $port = $p['port'] ?? ($sch === 'https' ? 443 : 80); if (!in_array($port, [80, 443], true)) { $erro = 'Porta não permitida.'; return null; }
    $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (@gethostbynamel($host) ?: []);
    if (!$ips) { $erro = 'Endereço não encontrado.'; return null; }
    foreach ($ips as $ip) if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) { $erro = 'Endereço não permitido.'; return null; }
    $ch = curl_init($url); $buf = ''; $hdr = '';
    curl_setopt_array($ch, [CURLOPT_RESOLVE => [$host . ':' . $port . ':' . $ips[0]], CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 8,
      CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; GEO2030-painel)', CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
      CURLOPT_HEADERFUNCTION => function ($c, $l) use (&$hdr) { $hdr .= $l; return strlen($l); },
      CURLOPT_WRITEFUNCTION => function ($c, $d) use (&$buf) { $buf .= $d; return strlen($buf) > 14000000 ? 0 : strlen($d); }]);
    curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code >= 300 && $code < 400 && preg_match('/^location:\s*(\S+)/mi', $hdr, $m)) {
      $l = trim($m[1]); if (!preg_match('#^https?://#i', $l)) $l = $sch . '://' . $host . ($l[0] === '/' ? '' : '/') . $l; $url = $l; continue;
    }
    if ($code !== 200 || $buf === '') { $erro = $code === 0 ? 'Não foi possível baixar (tempo esgotado ou arquivo grande demais).' : 'O endereço respondeu ' . $code . '. Confira se o link está público.'; return null; }
    return $buf;
  }
  $erro = 'Muitos redirecionamentos.'; return null;
}
function gdSalva($im, $max, $path) {
  $w = imagesx($im); $h = imagesy($im); $s = min(1, $max / max($w, $h));
  $nw = max(1, (int)round($w * $s)); $nh = max(1, (int)round($h * $s));
  $o = imagecreatetruecolor($nw, $nh); imagefill($o, 0, 0, imagecolorallocate($o, 255, 255, 255)); imagecopyresampled($o, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
  imageinterlace($o, true); $ok = imagejpeg($o, $path, 82); imagedestroy($o); return $ok;
}
function processaImagem($url, &$erro) {
  global $up, $cd;
  $url = normaliza($url);
  if (ehLocal($url)) {
    if (!is_file(__DIR__ . '/' . $url)) { $erro = 'Arquivo não encontrado no site.'; return null; }
    if (preg_match('#^(uploads/[0-9a-f]{14})_m\.jpg$#', $url, $m) && is_file(__DIR__ . '/' . $m[1] . '.jpg')) return ['src' => $url, 'big' => $m[1] . '.jpg'];
    if (preg_match('#^(uploads/[0-9a-f]{14})\.jpg$#', $url, $m) && is_file(__DIR__ . '/' . $m[1] . '_m.jpg')) return ['src' => $m[1] . '_m.jpg', 'big' => $url];
    return ['src' => $url, 'big' => $url];
  }
  $mapf = $cd . '/imgmap.json'; $map = is_file($mapf) ? (json_decode(file_get_contents($mapf), true) ?: []) : [];
  if (!empty($map[$url]) && is_file(__DIR__ . '/' . $map[$url]['src'])) return $map[$url];
  if (!function_exists('imagecreatefromstring')) { $erro = 'O servidor não tem suporte a imagens (GD).'; return null; }
  $b = baixa($url, $erro); if ($b === null) return null;
  $info = @getimagesizefromstring($b); if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true)) { $erro = 'O link não é uma imagem (JPG, PNG, WEBP ou GIF).'; return null; }
  if ($info[0] * $info[1] > 60000000) { $erro = 'Imagem grande demais.'; return null; }
  $im = @imagecreatefromstring($b); if (!$im) { $erro = 'Não consegui ler a imagem.'; return null; }
  if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) { $ex = @exif_read_data('data://image/jpeg;base64,' . base64_encode(substr($b, 0, 262144))); $or = (int)($ex['Orientation'] ?? 1); if ($or === 3) $im = imagerotate($im, 180, 0); elseif ($or === 6) $im = imagerotate($im, -90, 0); elseif ($or === 8) $im = imagerotate($im, 90, 0); }
  if (!is_dir($up)) { @mkdir($up, 0755, true); file_put_contents($up . '/.htaccess', "<FilesMatch \"\\.(php|phtml|phar)$\">\nRequire all denied\n</FilesMatch>\nOptions -Indexes\n"); }
  $id = substr(sha1($b), 0, 14); $big = 'uploads/' . $id . '.jpg'; $med = 'uploads/' . $id . '_m.jpg';
  if (!gdSalva($im, 1800, __DIR__ . '/' . $big) || !gdSalva($im, 960, __DIR__ . '/' . $med)) { imagedestroy($im); $erro = 'Não consegui gravar a imagem no site.'; return null; }
  imagedestroy($im);
  $r = ['src' => $med, 'big' => $big]; $map[$url] = $r; @file_put_contents($mapf, json_encode($map, JSON_UNESCAPED_SLASHES), LOCK_EX); return $r;
}
if ($a === 'imagem') { $e = ''; $r = processaImagem((string)($in['url'] ?? ''), $e); $r ? out(['ok' => true] + $r) : out(['ok' => false, 'erro' => $e ?: 'Falhou.']); }

/* ---------- validação e gravação do conteúdo ---------- */
function tx($s, $max) { $s = (string)$s; $s = preg_replace('/[^\P{C}\n]/u', '', str_replace("\r", '', $s)); return mb_substr(trim($s), 0, $max); }
function listaFotos($l, $max) {
  $o = []; foreach ((array)$l as $f) { if (!is_array($f)) continue; $s = (string)($f['src'] ?? ''); $b = (string)($f['big'] ?? $s); if (!ehLocal($s)) continue; if (!ehLocal($b)) $b = $s; $o[] = ['src' => $s, 'big' => $b, 'cap' => tx($f['cap'] ?? '', 240)]; if (count($o) >= $max) break; }
  return $o;
}
function limpaConteudo($in) {
  $out = [];
  foreach ((array)($in['compromissos'] ?? []) as $n => $c) {
    $n = (int)$n; if ($n < 1 || $n > 12 || !is_array($c)) continue;
    $p = $c['pct'] ?? ''; $p = ($p === '' || $p === null) ? 0 : max(0, min(100, (float)$p));
    $r = ['name' => tx($c['name'] ?? '', 80), 'status' => tx($c['status'] ?? '', 60), 'pct' => round($p, 1), 'ver' => tx($c['ver'] ?? '', 120), 'pulse' => tx($c['pulse'] ?? '', 60), 'terr' => tx($c['terr'] ?? '', 120),
      'why' => tx($c['why'] ?? '', 600), 'resp' => tx($c['resp'] ?? '', 900), 'deadline' => tx($c['deadline'] ?? '', 120), 'now' => tx($c['now'] ?? '', 1400), 'next' => tx($c['next'] ?? '', 700), 'method' => tx($c['method'] ?? '', 1400)];
    $r['who'] = []; foreach ((array)($c['who'] ?? []) as $w) { $w = tx($w, 160); if ($w !== '') $r['who'][] = $w; if (count($r['who']) >= 14) break; }
    $r['tl'] = []; foreach ((array)($c['tl'] ?? []) as $t) { if (!is_array($t)) continue; $x = tx($t['t'] ?? '', 800); if ($x === '') continue; $r['tl'][] = ['d' => tx($t['d'] ?? '', 40), 't' => $x, 'soft' => !empty($t['soft']) ? 1 : 0]; if (count($r['tl']) >= 40) break; }
    $r['ev'] = []; foreach ((array)($c['ev'] ?? []) as $t) { if (!is_array($t)) continue; $b = tx($t['b'] ?? '', 160); $x = tx($t['t'] ?? '', 500); if ($b === '' && $x === '') continue; $r['ev'][] = ['b' => $b, 't' => $x, 'l' => tx($t['l'] ?? '', 80)]; if (count($r['ev']) >= 16) break; }
    $r['fotos'] = listaFotos($c['fotos'] ?? [], 12); $r['gal'] = listaFotos($c['gal'] ?? [], 40);
    $out[(string)$n] = $r;
  }
  return $out;
}
if ($a === 'salvar') {
  $novo = limpaConteudo($in); if (!$novo) out(['ok' => false, 'erro' => 'Nada para publicar.'], 422);
  if (!is_dir($cd . '/historico')) @mkdir($cd . '/historico', 0755, true);
  $f = $cd . '/compromissos.json';
  if (is_file($f)) { @copy($f, $cd . '/historico/' . date('Ymd-His') . '.json'); $h = glob($cd . '/historico/*.json') ?: []; sort($h); foreach (array_slice($h, 0, max(0, count($h) - 15)) as $x) @unlink($x); }
  $doc = ['versao' => time(), 'atualizado' => gmdate('c'), 'compromissos' => $novo];
  $tmp = $f . '.tmp'; if (file_put_contents($tmp, json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX) === false || !rename($tmp, $f)) out(['ok' => false, 'erro' => 'Não consegui gravar o conteúdo.'], 500);
  out(['ok' => true, 'compromissos' => count($novo)]);
}
if ($a === 'historico') { $h = glob($cd . '/historico/*.json') ?: []; rsort($h); out(['ok' => true, 'itens' => array_map(function ($x) { return basename($x, '.json'); }, array_slice($h, 0, 15))]); }
if ($a === 'restaurar') {
  $id = preg_replace('/[^0-9-]/', '', (string)($in['id'] ?? '')); $s = $cd . '/historico/' . $id . '.json'; if (!is_file($s)) out(['ok' => false, 'erro' => 'Versão não encontrada.'], 404);
  @copy($cd . '/compromissos.json', $cd . '/historico/' . date('Ymd-His') . '.json'); copy($s, $cd . '/compromissos.json'); out(['ok' => true]);
}
out(['ok' => false, 'erro' => 'Ação desconhecida.'], 400);
