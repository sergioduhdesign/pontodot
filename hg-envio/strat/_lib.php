<?php
// Stratesign 2026 — funções comuns do site e do painel. Não responde sozinho.
if (!defined('STRAT')) { http_response_code(404); exit; }
define('DADOS', __DIR__ . '/_dados');
define('SITE', 'https://stratesign.com.br');
function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function dados_dir() {
    if (!is_dir(DADOS)) @mkdir(DADOS, 0755, true);
    if (!is_file(DADOS . '/.htaccess')) @file_put_contents(DADOS . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n");
    return DADOS;
}
function le_json($arq, $padrao) {
    $f = dados_dir() . '/' . $arq; if (!is_file($f)) return $padrao;
    $j = json_decode((string)file_get_contents($f), true); return is_array($j) ? $j : $padrao;
}
function grava_json($arq, $v) {
    $f = dados_dir() . '/' . $arq; $t = $f . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (file_put_contents($t, json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX) === false) return false;
    return rename($t, $f);
}
function artigos($so_publicados = true) {
    $a = le_json('artigos.json', []); $hoje = date('Y-m-d');
    if ($so_publicados) $a = array_values(array_filter($a, function ($x) use ($hoje) { return !empty($x['publicado']) && ($x['data'] ?? '') <= $hoje; }));
    usort($a, function ($x, $y) { return strcmp(($y['data'] ?? '') . ($y['id'] ?? ''), ($x['data'] ?? '') . ($x['id'] ?? '')); });
    return $a;
}
function artigo_por_slug($slug) { foreach (artigos(true) as $a) if (($a['slug'] ?? '') === $slug) return $a; return null; }
function faz_slug($s) {
    $s = mb_strtolower(trim((string)$s), 'UTF-8');
    $de = ['á','à','â','ã','ä','é','è','ê','ë','í','ì','î','ï','ó','ò','ô','õ','ö','ú','ù','û','ü','ç','ñ'];
    $pa = ['a','a','a','a','a','e','e','e','e','i','i','i','i','o','o','o','o','o','u','u','u','u','c','n'];
    $s = str_replace($de, $pa, $s); $s = preg_replace('/[^a-z0-9]+/', '-', $s); $s = trim($s, '-');
    return $s !== '' ? mb_substr($s, 0, 80) : 'artigo';
}
function data_br($d) {
    $m = ['', 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string)$d, $p)) return '';
    return (int)$p[3] . ' de ' . $m[(int)$p[2]] . ' de ' . $p[1];
}
// texto do artigo: parágrafos separados por linha em branco; "## " subtítulo; "> " citação; **negrito**; *itálico*; [texto](https://…); ![legenda](/img/artigos/arquivo.jpg)
function linha_html($t) {
    $t = e($t);
    $t = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $t);
    $t = preg_replace('/(?<![*\w])\*(?!\s)(.+?)(?<!\s)\*(?![*\w])/s', '<em>$1</em>', $t);
    $t = preg_replace_callback('/\[([^\]]+)\]\((https?:\/\/[^\s)]+|\/[^\s)]*)\)/', function ($m) {
        $ext = strpos($m[2], 'http') === 0 && strpos($m[2], SITE) !== 0;
        return '<a href="' . $m[2] . '"' . ($ext ? ' target="_blank" rel="noopener"' : '') . '>' . $m[1] . '</a>';
    }, $t);
    return nl2br($t, false);
}
function corpo_html($txt) {
    $txt = str_replace(["\r\n", "\r"], "\n", (string)$txt); $o = '';
    foreach (preg_split('/\n\s*\n/', trim($txt)) as $b) {
        $b = trim($b); if ($b === '') continue;
        if (preg_match('/^!\[([^\]]*)\]\((\/img\/artigos\/[A-Za-z0-9._-]+)\)$/', $b, $m)) { $o .= '<figure><img src="' . e($m[2]) . '" alt="' . e($m[1]) . '" loading="lazy">' . ($m[1] !== '' ? '<figcaption>' . e($m[1]) . '</figcaption>' : '') . "</figure>\n"; continue; }
        if (strpos($b, '## ') === 0) { $o .= '<h2>' . linha_html(substr($b, 3)) . "</h2>\n"; continue; }
        if (strpos($b, '> ') === 0) { $o .= '<blockquote><p>' . linha_html(preg_replace('/^> ?/m', '', $b)) . "</p></blockquote>\n"; continue; }
        $o .= '<p>' . linha_html($b) . "</p>\n";
    }
    return $o;
}
function resumo_de($a) { $r = trim((string)($a['resumo'] ?? '')); if ($r !== '') return $r; $t = trim(preg_replace('/\s+/', ' ', preg_replace('/[#>*\[\]!]|\(.*?\)/', '', (string)($a['corpo'] ?? '')))); return mb_substr($t, 0, 180) . (mb_strlen($t) > 180 ? '…' : ''); }
function cabeca($titulo, $desc, $caminho, $extra = '') {
    $url = SITE . $caminho; ?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<base href="/">
<title><?= e($titulo) ?></title>
<meta name="description" content="<?= e($desc) ?>">
<link rel="canonical" href="<?= e($url) ?>">
<meta name="theme-color" content="#ffffff">
<link rel="icon" href="/favicon.ico" sizes="32x32">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<link rel="manifest" href="/site.webmanifest">
<link rel="alternate" type="application/rss+xml" title="Stratesign — Artigos" href="/feed.xml">
<meta property="og:site_name" content="Stratesign">
<meta property="og:locale" content="pt_BR">
<meta property="og:title" content="<?= e($titulo) ?>">
<meta property="og:description" content="<?= e($desc) ?>">
<meta property="og:url" content="<?= e($url) ?>">
<meta name="twitter:card" content="summary_large_image">
<?= $extra ?>
<link rel="preload" href="fonts/montserrat-latin-700-normal.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="assets/site.css?v=15">
<link rel="stylesheet" href="assets/paginas.css?v=15">
</head>
<body class="pg">
<header class="pg-topo"><a href="/" aria-label="Stratesign, início"><img src="assets/logo.svg" alt="Stratesign" width="30" height="64"></a><nav><a href="/#trabalhos">Trabalhos</a><a href="/artigos/">Artigos</a><a href="/#conversa">Vamos conversar?</a></nav></header>
<?php }
function rodape() { ?>
<footer class="pg-pe"><a href="/">Stratesign — A agência do imobiliário brasileiro.</a><span><a href="https://www.instagram.com/stratesign/" target="_blank" rel="noopener">Instagram</a> · <a href="https://wa.me/5511967067100" target="_blank" rel="noopener">WhatsApp</a> · <a href="/privacidade">Privacidade</a> · <a href="/feed.xml">Feed</a></span><span>© <?= date('Y') ?> Stratesign</span></footer>
</body>
</html>
<?php }
