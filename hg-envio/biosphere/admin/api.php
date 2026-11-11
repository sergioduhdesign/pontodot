<?php
/* BIOSPHERE · API do painel (textos, imagens/vídeo, SEO, blog, contatos). Só com login. */
@ini_set('memory_limit', '384M'); @set_time_limit(120);
define('RAIZ_ADM', dirname(__DIR__)); $cd = RAIZ_ADM . '/conteudo'; $up = RAIZ_ADM . '/uploads';
require RAIZ_ADM . '/schema.php';
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_name('bsadm'); session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']); session_start();
function out($a, $c = 200) { http_response_code($c); echo json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
function cfg() { $f = RAIZ_ADM . '/_config.php'; return is_file($f) ? (include $f) : []; }
function auth() { if (empty($_SESSION['ok'])) out(['ok' => false, 'erro' => 'Faça login.', 'login' => true], 401); }
function lj($f, $p = []) { return is_file($f) ? (json_decode(file_get_contents($f), true) ?: $p) : $p; }
function gj($f, $d) { $tmp = $f . '.tmp'; if (file_put_contents($tmp, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX) === false || !rename($tmp, $f)) out(['ok' => false, 'erro' => 'Não consegui gravar.'], 500); }
function tx($s, $max) { $s = str_replace("\r", '', (string)$s); $s = preg_replace('/[^\P{C}\n\t]/u', '', $s); return mb_substr(trim($s), 0, $max); }
$multipart = stripos($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/') === 0;
$in = $multipart ? $_POST : (json_decode(file_get_contents('php://input') ?: '[]', true) ?: []);
$a = $_GET['a'] ?? ($in['a'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_SERVER['HTTP_X_BS_ADMIN'] ?? '') !== '1') out(['ok' => false, 'erro' => 'Requisição inválida.'], 400);

if ($a === 'login') {
  $f = $cd . '/.tent.json'; $t = lj($f); $u = strtolower(trim((string)($in['usuario'] ?? ''))); $k = md5(($_SERVER['REMOTE_ADDR'] ?? '') . 'bs'); $now = time();
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
  $c['usuarios'][$u] = password_hash($n, PASSWORD_DEFAULT); file_put_contents(RAIZ_ADM . '/_config.php', "<?php\nreturn " . var_export($c, true) . ";\n", LOCK_EX); out(['ok' => true]);
}

function backup($f, $nome) { global $cd; if (!is_file($f)) return; $d = $cd . '/historico'; if (!is_dir($d)) @mkdir($d, 0755, true); @copy($f, $d . '/' . $nome . '-' . date('Ymd-His') . '.json'); $h = glob($d . '/' . $nome . '-*.json') ?: []; sort($h); foreach (array_slice($h, 0, max(0, count($h) - 15)) as $x) @unlink($x); }
function schemaJson() { global $SCHEMA; $o = []; foreach ($SCHEMA as $g => $d) { $c = []; foreach ($d['campos'] as $k => $f) $c[] = ['k' => $k, 'label' => $f['label'], 'type' => $f['type'], 'def' => $f['def']]; $o[] = ['g' => $g, 'titulo' => $d['titulo'], 'campos' => $c]; } return $o; }
function biblioteca() { global $up; $o = []; foreach (glob($up . '/*') ?: [] as $f) { $b = basename($f); if ($b[0] === '.' || preg_match('/_m\.jpg$/', $b)) continue; $o[] = ['f' => 'uploads/' . $b, 'size' => filesize($f), 't' => filemtime($f)]; } usort($o, function ($x, $y) { return $y['t'] - $x['t']; }); return $o; }

if ($a === 'dados') {
  out(['ok' => true, 'schema' => schemaJson(), 'valores' => (object)lj($cd . '/site.json'), 'seo' => (object)lj($cd . '/seo.json'), 'seo_paginas' => $SEO_PAGINAS, 'posts' => lj($cd . '/posts.json', []), 'leads' => lj($cd . '/leads.json', []), 'biblioteca' => biblioteca(), 'gd' => function_exists('imagecreatefromstring'), 'usuario' => $_SESSION['u'] ?? '', 'limite_mb' => min((int)ini_get('upload_max_filesize'), (int)ini_get('post_max_size'))]);
}

if ($a === 'salvar_textos') {
  global $SCHEMA; $cur = lj($cd . '/site.json'); $novo = (array)($in['valores'] ?? []); $n = 0;
  foreach ($novo as $key => $val) {
    if (!preg_match('/^([a-z0-9_]+)\.([a-z0-9_]+)$/', (string)$key, $m) || !isset($SCHEMA[$m[1]]['campos'][$m[2]])) continue;
    $tp = $SCHEMA[$m[1]]['campos'][$m[2]]['type']; $max = $tp === 'area' ? 4000 : 600; $v = tx($val, $max);
    if ($tp === 'img' || $tp === 'video') { if ($v !== '' && !preg_match('#^(https?://\S+|(img|uploads|assets)/[\w./-]+)$#', $v) || strpos($v, '..') !== false) continue; }
    if ($tp === 'link' && $v !== '' && !preg_match('#^(https?://\S+|mailto:\S+|tel:\S+|/\S*|\#\S*|[\w./#-]+)$#', $v)) continue;
    $cur[$key] = $v; $n++;
  }
  backup($cd . '/site.json', 'site'); gj($cd . '/site.json', $cur); out(['ok' => true, 'salvos' => $n]);
}
if ($a === 'restaurar_campo') { $cur = lj($cd . '/site.json'); $k = (string)($in['k'] ?? ''); if (isset($cur[$k])) { backup($cd . '/site.json', 'site'); unset($cur[$k]); gj($cd . '/site.json', $cur); } out(['ok' => true]); }

if ($a === 'salvar_seo') {
  global $SEO_PAGINAS; $s = ['global' => [], 'paginas' => []]; $g = (array)($in['global'] ?? []);
  foreach (['nome_site' => 120, 'canonical_base' => 200, 'og_image' => 300, 'robots' => 120, 'google_verificacao' => 120, 'bing_verificacao' => 120, 'meta_verificacao' => 120, 'ga4' => 30, 'meta_pixel' => 30, 'instagram_url' => 200, 'bloquear_buscadores' => 1, 'head_extra' => 6000] as $k => $m) $s['global'][$k] = $k === 'head_extra' ? mb_substr((string)($g[$k] ?? ''), 0, $m) : tx($g[$k] ?? '', $m);
  foreach ((array)($in['paginas'] ?? []) as $slug => $p) { if (!isset($SEO_PAGINAS[$slug]) || !is_array($p)) continue; $s['paginas'][$slug] = ['title' => tx($p['title'] ?? '', 120), 'description' => tx($p['description'] ?? '', 320), 'og_image' => tx($p['og_image'] ?? '', 300), 'robots' => tx($p['robots'] ?? '', 120), 'canonical' => tx($p['canonical'] ?? '', 300)]; }
  backup($cd . '/seo.json', 'seo'); gj($cd . '/seo.json', $s); out(['ok' => true]);
}

/* ---------- imagens, vídeo ---------- */
function gdSalva($im, $max, $path, $png = false) {
  $w = imagesx($im); $h = imagesy($im); $s = min(1, $max / max($w, $h)); $nw = max(1, (int)round($w * $s)); $nh = max(1, (int)round($h * $s));
  $o = imagecreatetruecolor($nw, $nh);
  if ($png) { imagealphablending($o, false); imagesavealpha($o, true); imagefill($o, 0, 0, imagecolorallocatealpha($o, 0, 0, 0, 127)); } else imagefill($o, 0, 0, imagecolorallocate($o, 255, 255, 255));
  imagecopyresampled($o, $im, 0, 0, 0, 0, $nw, $nh, $w, $h); imageinterlace($o, true);
  $ok = $png ? imagepng($o, $path, 7) : imagejpeg($o, $path, 83); imagedestroy($o); return $ok;
}
function baixa($url, &$erro) {
  for ($hop = 0; $hop < 5; $hop++) {
    $p = parse_url($url); $sch = strtolower($p['scheme'] ?? ''); $host = $p['host'] ?? '';
    if (!in_array($sch, ['http', 'https'], true) || $host === '') { $erro = 'Endereço inválido.'; return null; }
    $port = $p['port'] ?? ($sch === 'https' ? 443 : 80); if (!in_array($port, [80, 443], true)) { $erro = 'Porta não permitida.'; return null; }
    $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (@gethostbynamel($host) ?: []); if (!$ips) { $erro = 'Endereço não encontrado.'; return null; }
    foreach ($ips as $ip) if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) { $erro = 'Endereço não permitido.'; return null; }
    $ch = curl_init($url); $buf = ''; $hdr = '';
    curl_setopt_array($ch, [CURLOPT_RESOLVE => [$host . ':' . $port . ':' . $ips[0]], CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; Biosphere-painel)', CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
      CURLOPT_HEADERFUNCTION => function ($c, $l) use (&$hdr) { $hdr .= $l; return strlen($l); }, CURLOPT_WRITEFUNCTION => function ($c, $d) use (&$buf) { $buf .= $d; return strlen($buf) > 14000000 ? 0 : strlen($d); }]);
    curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code >= 300 && $code < 400 && preg_match('/^location:\s*(\S+)/mi', $hdr, $m)) { $l = trim($m[1]); if (!preg_match('#^https?://#i', $l)) $l = $sch . '://' . $host . ($l[0] === '/' ? '' : '/') . $l; $url = $l; continue; }
    if ($code !== 200 || $buf === '') { $erro = 'Não foi possível baixar (' . $code . '). Confira se o link está público.'; return null; } return $buf;
  } $erro = 'Muitos redirecionamentos.'; return null;
}
function normaliza($u) { $u = trim($u); if (preg_match('#drive\.google\.com/file/d/([\w-]+)#', $u, $m) || preg_match('#drive\.google\.com/(?:open|uc)\?[^#]*id=([\w-]+)#', $u, $m)) return 'https://drive.google.com/uc?export=download&id=' . $m[1]; if (preg_match('#^https?://(www\.)?dropbox\.com/#i', $u)) { $u = preg_replace('/([?&])dl=0/', '$1dl=1', $u); if (strpos($u, 'dl=1') === false) $u .= (strpos($u, '?') === false ? '?' : '&') . 'dl=1'; } return $u; }
function salvaImagemBin($b, &$erro) {
  global $up; if (!function_exists('imagecreatefromstring')) { $erro = 'O servidor não tem suporte a imagens (GD).'; return null; }
  $info = @getimagesizefromstring($b); if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true)) { $erro = 'Arquivo não é imagem (JPG, PNG, WEBP ou GIF).'; return null; }
  if ($info[0] * $info[1] > 60000000) { $erro = 'Imagem grande demais.'; return null; }
  $im = @imagecreatefromstring($b); if (!$im) { $erro = 'Não consegui ler a imagem.'; return null; }
  if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) { $ex = @exif_read_data('data://image/jpeg;base64,' . base64_encode(substr($b, 0, 262144))); $or = (int)($ex['Orientation'] ?? 1); if ($or === 3) $im = imagerotate($im, 180, 0); elseif ($or === 6) $im = imagerotate($im, -90, 0); elseif ($or === 8) $im = imagerotate($im, 90, 0); }
  if (!is_dir($up)) @mkdir($up, 0755, true); $id = substr(sha1($b), 0, 14);
  if ($info[2] === IMAGETYPE_PNG || $info[2] === IMAGETYPE_WEBP && false) { $fn = 'uploads/' . $id . '.png'; if (!gdSalva($im, 1800, RAIZ_ADM . '/' . $fn, true)) { $erro = 'Não consegui gravar.'; return null; } imagedestroy($im); return $fn; }
  $fn = 'uploads/' . $id . '.jpg'; if (!gdSalva($im, 1800, RAIZ_ADM . '/' . $fn) || !gdSalva($im, 800, RAIZ_ADM . '/uploads/' . $id . '_m.jpg')) { $erro = 'Não consegui gravar.'; return null; } imagedestroy($im); return $fn;
}
if ($a === 'upload') {
  $f = $_FILES['arq'] ?? null; $erro = '';
  if (!$f || $f['error'] !== UPLOAD_ERR_OK) { $m = [UPLOAD_ERR_INI_SIZE => 'Arquivo maior que o limite do servidor. Para vídeo grande, use link do YouTube ou Vimeo.', UPLOAD_ERR_FORM_SIZE => 'Arquivo grande demais.']; out(['ok' => false, 'erro' => $m[$f['error'] ?? 0] ?? 'Falha no envio.'], 422); }
  $nome = strtolower($f['name']); if (preg_match('/\.(mp4|webm|mov|m4v)$/', $nome, $m)) {
    if ($f['size'] > 200 * 1048576) out(['ok' => false, 'erro' => 'Vídeo acima de 200 MB.'], 422);
    $fi = new finfo(FILEINFO_MIME_TYPE); $mt = $fi->file($f['tmp_name']); if (!preg_match('#^video/#', (string)$mt)) out(['ok' => false, 'erro' => 'Arquivo não é um vídeo.'], 422);
    if (!is_dir($up)) @mkdir($up, 0755, true); $fn = 'uploads/' . substr(sha1_file($f['tmp_name']), 0, 14) . '.' . ($m[1] === 'm4v' ? 'mp4' : $m[1]);
    if (!move_uploaded_file($f['tmp_name'], RAIZ_ADM . '/' . $fn)) out(['ok' => false, 'erro' => 'Não consegui gravar o vídeo.'], 500); out(['ok' => true, 'src' => $fn, 'tipo' => 'video']);
  }
  $b = file_get_contents($f['tmp_name']); $r = salvaImagemBin($b, $erro); $r ? out(['ok' => true, 'src' => $r, 'tipo' => 'img']) : out(['ok' => false, 'erro' => $erro], 422);
}
if ($a === 'upload_url') { $erro = ''; $b = baixa(normaliza((string)($in['url'] ?? '')), $erro); if ($b === null) out(['ok' => false, 'erro' => $erro], 422); $r = salvaImagemBin($b, $erro); $r ? out(['ok' => true, 'src' => $r, 'tipo' => 'img']) : out(['ok' => false, 'erro' => $erro], 422); }
if ($a === 'apagar_upload') { $f = (string)($in['f'] ?? ''); if (!preg_match('#^uploads/([0-9a-f]{14})\.(jpg|png|mp4|webm|mov)$#', $f, $m)) out(['ok' => false, 'erro' => 'Arquivo inválido.'], 422); @unlink(RAIZ_ADM . '/' . $f); @unlink(RAIZ_ADM . '/uploads/' . $m[1] . '_m.jpg'); out(['ok' => true]); }

/* ---------- blog ---------- */
function slugify($s) { $s = mb_strtolower(trim($s)); $s = strtr($s, ['á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'ê' => 'e', 'è' => 'e', 'í' => 'i', 'ï' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ú' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n']); $s = trim(preg_replace('/[^a-z0-9]+/', '-', $s), '-'); return mb_substr($s, 0, 80) ?: 'post'; }
if ($a === 'post_salvar') {
  $p = (array)($in['post'] ?? []); $l = lj($cd . '/posts.json', []); $tit = tx($p['titulo'] ?? '', 160); if ($tit === '') out(['ok' => false, 'erro' => 'Informe o título.'], 422);
  $id = preg_match('/^[a-f0-9]{8}$/', $p['id'] ?? '') ? $p['id'] : substr(md5(uniqid('', true)), 0, 8); $slug = slugify(tx($p['slug'] ?? '', 80) ?: $tit);
  foreach ($l as $x) if ($x['slug'] === $slug && $x['id'] !== $id) { $slug .= '-' . substr($id, 0, 4); break; }
  $d = preg_match('/^\d{4}-\d{2}-\d{2}$/', $p['data'] ?? '') ? $p['data'] : date('Y-m-d'); $capa = tx($p['capa'] ?? '', 300); if ($capa !== '' && !preg_match('#^(https?://\S+|(img|uploads)/[\w./-]+)$#', $capa)) $capa = '';
  $novo = ['id' => $id, 'slug' => $slug, 'titulo' => $tit, 'resumo' => tx($p['resumo'] ?? '', 400), 'capa' => $capa, 'data' => $d, 'status' => ($p['status'] ?? '') === 'publicado' ? 'publicado' : 'rascunho', 'conteudo' => tx($p['conteudo'] ?? '', 60000), 'seo_titulo' => tx($p['seo_titulo'] ?? '', 120), 'seo_descricao' => tx($p['seo_descricao'] ?? '', 320), 'seo_imagem' => tx($p['seo_imagem'] ?? '', 300)];
  $ok = false; foreach ($l as $i => $x) if ($x['id'] === $id) { $l[$i] = $novo; $ok = true; } if (!$ok) array_unshift($l, $novo);
  backup($cd . '/posts.json', 'posts'); gj($cd . '/posts.json', array_values($l)); out(['ok' => true, 'post' => $novo, 'posts' => array_values($l)]);
}
if ($a === 'post_excluir') { $id = (string)($in['id'] ?? ''); $l = array_values(array_filter(lj($cd . '/posts.json', []), function ($x) use ($id) { return $x['id'] !== $id; })); backup($cd . '/posts.json', 'posts'); gj($cd . '/posts.json', $l); out(['ok' => true, 'posts' => $l]); }
if ($a === 'lead_excluir') { $id = (string)($in['id'] ?? ''); $l = array_values(array_filter(lj($cd . '/leads.json', []), function ($x) use ($id) { return ($x['id'] ?? '') !== $id; })); gj($cd . '/leads.json', $l); out(['ok' => true, 'leads' => $l]); }
out(['ok' => false, 'erro' => 'Ação desconhecida.'], 400);
