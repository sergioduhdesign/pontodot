<?php
require_once __DIR__ . '/sd-base.php';
sd_rate_limit('cog', 60, 60);
// Imagem de prévia (WhatsApp/iMessage) para lotes de COPY: um "print" do texto.
require_once __DIR__ . '/../sd-private/sd-config.php';

$token = $_GET['t'] ?? '';
$cliente = 'Pontodot'; $titulo = 'Copy'; $n = 0; $pTitulo = ''; $pTexto = ''; $pTipo = '';
if (preg_match('/^[0-9a-fA-F-]{36}$/', $token)) {
    $ch = curl_init();
    curl_setopt_array($ch, [CURLOPT_URL => SD_SUPABASE_URL . '/rest/v1/approval_sets?share_token=eq.' . urlencode($token)
        . '&select=title,status,clients(name),approval_items(position,kind,title,caption)&approval_items.order=position.asc',
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_HTTPHEADER => ['apikey: ' . SD_SECRET_KEY]]);
    $rows = json_decode((string)curl_exec($ch), true); curl_close($ch);
    if (is_array($rows) && count($rows) && !empty($rows[0]['status']) && $rows[0]['status'] !== 'rascunho') {
        $s = $rows[0]; $cliente = $s['clients']['name'] ?? $cliente; $titulo = $s['title'] ?? $titulo;
        $it = (array)($s['approval_items'] ?? []); $n = count($it);
        if ($n) { $pTitulo = (string)($it[0]['title'] ?? ''); $pTipo = strtoupper((string)($it[0]['kind'] ?? ''));
            $t = (string)($it[0]['caption'] ?? ''); $t = preg_replace('/\n*\[direção visual\].*$/isu', '', $t); $pTexto = trim(preg_replace('/\s*\n+\s*/u', '  ', $t)); }
    }
}

$W = 1200; $H = 630;
$im = imagecreatetruecolor($W, $H);
imageantialias($im, true);
$c = fn($r, $g, $b) => imagecolorallocate($im, $r, $g, $b);
$bg = $c(0xF4, 0xF3, 0xEF); $ink = $c(0x15, 0x16, 0x1A); $gray = $c(0x6c, 0x6c, 0x69); $white = $c(255, 255, 255); $line = $c(0xDD, 0xDB, 0xD3); $acc = $c(0x7c, 0x4d, 0xff);
imagefill($im, 0, 0, $bg);
$B = __DIR__ . '/assets/fonts/LiberationSans-Bold.ttf'; $R = __DIR__ . '/assets/fonts/LiberationSans-Regular.ttf';

function quebra($txt, $font, $size, $maxW, $maxLin) {
    $out = []; $txt = str_replace(["\r"], '', $txt);
    foreach (explode("\n", $txt) as $par) {
        $cur = '';
        foreach (preg_split('/\s+/u', trim($par), -1, PREG_SPLIT_NO_EMPTY) ?: [''] as $w) {
            $try = $cur === '' ? $w : $cur . ' ' . $w; $bb = imagettfbbox($size, 0, $font, $try);
            if ($bb[2] - $bb[0] > $maxW && $cur !== '') { $out[] = $cur; $cur = $w; } else $cur = $try;
            if (count($out) >= $maxLin) break 2;
        }
        $out[] = $cur; if (count($out) >= $maxLin) break;
    }
    if (count($out) > $maxLin) $out = array_slice($out, 0, $maxLin);
    return $out;
}

// faixa lateral + selo COPY
imagefilledrectangle($im, 0, 0, 14, $H, $acc);
imagefilledrectangle($im, 60, 52, 200, 100, $ink);
imagettftext($im, 24, 0, 84, 87, $white, $B, 'COPY');
imagettftext($im, 22, 0, 224, 86, $gray, $R, mb_strtoupper($cliente) . '  ·  ' . $n . ($n === 1 ? ' TEXTO' : ' TEXTOS') . ' PARA APROVAR');
$tl = quebra($titulo, $B, 38, 1080, 2); $y = 168;
foreach ($tl as $l) { imagettftext($im, 38, 0, 60, $y, $ink, $B, $l); $y += 52; }

// "folha" com o texto da primeira peça
$top = $y + 14;
imagefilledrectangle($im, 60, $top, 1140, $H - 36, $white);
imagerectangle($im, 60, $top, 1140, $H - 36, $line);
$yy = $top + 52;
if ($pTipo !== '') { imagettftext($im, 17, 0, 90, $yy, $acc, $B, $pTipo); $yy += 38; }
if ($pTitulo !== '') { foreach (quebra($pTitulo, $B, 26, 1000, 1) as $l) { imagettftext($im, 26, 0, 90, $yy, $ink, $B, $l); $yy += 40; } }
$lin = max(1, (int)floor((($H - 36) - $yy - 14) / 34));
$corpo = $pTexto !== '' ? quebra($pTexto, $R, 21, 1000, $lin) : ['(sem texto de legenda)'];
foreach ($corpo as $i => $l) {
    if ($i === count($corpo) - 1 && mb_strlen(implode(' ', $corpo)) < mb_strlen(preg_replace('/\s+/u', ' ', $pTexto)) - 2) $l = rtrim($l, " .,;") . '…';
    imagettftext($im, 21, 0, 90, $yy, $gray, $R, $l); $yy += 34;
}

header('Content-Type: image/png'); header('Cache-Control: public, max-age=300');
imagepng($im); imagedestroy($im);
