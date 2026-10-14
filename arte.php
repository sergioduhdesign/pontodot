<?php
// =====================================================================
// SD Workflow — A ARTE SAI PELO SEU DOMÍNIO
//
// As aprovações passaram a morar no Drive. Sem este arquivo, o endereço
// da imagem na tela do cliente seria drive.google.com — e botão direito
// > "abrir imagem" entregaria onde o material da casa fica guardado.
//
// Aqui ela sai como  sd.sergioduh.com/arte.php?i=...  e o Drive some
// de vista. O endereço é ASSINADO: sem a assinatura não abre, e ninguém
// descobre a arte do cliente vizinho trocando um número na URL.
//
//   /arte.php?i=<id no drive>&k=<assinatura>&w=1800
//
// Guarda cópia em disco: a segunda vez que alguém olha a mesma arte
// sai do HostGator na hora, sem ir ao Google de novo.
// =====================================================================

require_once __DIR__ . '/../sd-private/sd-config.php';

const ARTE_CACHE   = __DIR__ . '/.cache-artes';
const ARTE_VALIDADE = 2592000;   // 30 dias de cópia local
const ARTE_LARGURAS = [300, 600, 1200, 1800, 2400];

function arte_assina($id) {
    return substr(hash_hmac('sha256', 'arte:' . $id, SD_SECRET_KEY), 0, 24);
}
function arte_morre($code, $msg) {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $msg; exit;
}

$id = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($_GET['i'] ?? ''));
$k  = preg_replace('/[^a-f0-9]/', '', (string)($_GET['k'] ?? ''));
$w  = (int)($_GET['w'] ?? 1800);
if (!in_array($w, ARTE_LARGURAS, true)) $w = 1800;

if (!$id) arte_morre(400, 'sem arte');
if (!hash_equals(arte_assina($id), $k)) arte_morre(403, 'endereço inválido');

// --------------------------------------------------------------- cache
if (!is_dir(ARTE_CACHE)) { @mkdir(ARTE_CACHE, 0755, true); @file_put_contents(ARTE_CACHE . '/.htaccess', "Deny from all\n"); }
$arquivo = ARTE_CACHE . '/' . $id . '-' . $w;
$tipo    = $arquivo . '.tipo';

$temCache = is_file($arquivo) && filesize($arquivo) > 0
         && (time() - filemtime($arquivo)) < ARTE_VALIDADE;

if (!$temCache) {
    // o endereço de imagem do Drive devolve os bytes direto, sem página
    $ch = curl_init('https://drive.google.com/thumbnail?id=' . rawurlencode($id) . '&sz=w' . $w);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 25, CURLOPT_USERAGENT => 'SD-Workflow/1.0',
    ]);
    $bytes = curl_exec($ch);
    $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $mime  = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: 'image/jpeg';
    curl_close($ch);

    // se o Google devolveu HTML, não é imagem: é recado de erro dele
    if ($code !== 200 || !$bytes || stripos($mime, 'image/') !== 0) {
        if (is_file($arquivo)) { $temCache = true; }        // serve a cópia velha a sair sem nada
        else arte_morre(502, 'não consegui buscar a arte');
    } else {
        @file_put_contents($arquivo, $bytes);
        @file_put_contents($tipo, $mime);
    }
}

$mime = is_file($tipo) ? trim((string)file_get_contents($tipo)) : 'image/jpeg';
if (stripos($mime, 'image/') !== 0) $mime = 'image/jpeg';

$etag = '"' . substr(md5($id . $w . (string)@filemtime($arquivo)), 0, 16) . '"';
if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) { http_response_code(304); exit; }

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($arquivo));
header('Cache-Control: private, max-age=604800');
header('ETag: ' . $etag);
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline');
readfile($arquivo);
