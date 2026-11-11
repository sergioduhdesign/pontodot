<?php
/* BIOSPHERE · núcleo do site: conteúdo, SEO, markdown e componentes. */
require __DIR__ . '/schema.php';
define('RAIZ', __DIR__);
$__sn = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
define('BASE', $__sn === '/' || $__sn === '.' ? '' : rtrim($__sn, '/'));

function lerJson($f, $pad = []) { return is_file($f) ? (json_decode(file_get_contents($f), true) ?: $pad) : $pad; }
function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function conteudo() { static $c = null; if ($c === null) $c = lerJson(RAIZ . '/conteudo/site.json'); return $c; }
function seoCfg() { static $c = null; if ($c === null) $c = lerJson(RAIZ . '/conteudo/seo.json'); return $c; }
function posts($soPublicados = true) {
  $l = lerJson(RAIZ . '/conteudo/posts.json', []); $o = [];
  foreach ($l as $p) { if ($soPublicados && (($p['status'] ?? '') !== 'publicado' || ($p['data'] ?? '') > date('Y-m-d'))) continue; $o[] = $p; }
  usort($o, function ($a, $b) { return strcmp($b['data'] ?? '', $a['data'] ?? ''); }); return $o;
}
/* valor de um campo: edição do painel, ou padrão do schema */
function V($grupo, $campo) {
  global $SCHEMA; $c = conteudo(); $k = $grupo . '.' . $campo;
  if (array_key_exists($k, $c)) return (string)$c[$k];
  return (string)($SCHEMA[$grupo]['campos'][$campo]['def'] ?? '');
}
function u($p = '') { return BASE . '/' . ltrim($p, '/'); }
function url($p) { $p = trim((string)$p); if ($p === '') return ''; if (preg_match('#^(https?:|mailto:|tel:|/|\#)#i', $p)) return $p; return u($p); }
function asset($p) { $p = trim((string)$p); if ($p === '') return ''; if (preg_match('#^(https?:)?//#i', $p)) return $p; if ($p[0] === '/') return $p; $f = RAIZ . '/' . $p; return BASE . '/' . $p . (is_file($f) ? '?v=' . filemtime($f) : ''); }
function t($s) { return nl2br(e($s), false); }
function paras($s) { $o = ''; foreach (preg_split('/\n\s*\n/', trim((string)$s)) as $p) { $p = trim($p); if ($p !== '') $o .= '<p>' . nl2br(e($p), false) . '</p>'; } return $o; }
function tag($s) { return $s !== '' ? '<span class="tag">' . e($s) . '</span>' : ''; }

/* imagem responsiva: usa a versão _m (800px) quando existir */
function IMG($src, $alt = '', $cls = '', $eager = false, $sizes = '(min-width:900px) 50vw, 100vw') {
  $src = trim((string)$src); if ($src === '') return '';
  $srcset = '';
  if (!preg_match('#^(https?:)?//#i', $src) && preg_match('#^(.*)\.(jpg|jpeg)$#i', $src, $m) && is_file(RAIZ . '/' . $m[1] . '_m.' . $m[2]) && substr($m[1], -2) !== '_m') {
    $srcset = ' srcset="' . e(asset($m[1] . '_m.' . $m[2])) . ' 800w, ' . e(asset($src)) . ' 1800w" sizes="' . e($sizes) . '"';
  }
  return '<img src="' . e(asset($src)) . '"' . $srcset . ' alt="' . e($alt) . '"' . ($cls ? ' class="' . e($cls) . '"' : '') . ($eager ? ' fetchpriority="high"' : ' loading="lazy"') . ' decoding="async">';
}
function credito($c) { $a = V('global', 'aviso'); $c = trim((string)$c); if ($c === '' && $a === '') return ''; return '<small class="cred">' . e($c) . ($c !== '' && $a !== '' ? '<br>' : '') . e($a) . '</small>'; }
function fig($img, $leg, $cred, $alt = '', $sizes = '(min-width:900px) 55vw, 100vw') {
  if (trim($img) === '') return '';
  return '<figure class="fig"><a class="zoom" href="' . e(asset($img)) . '" data-lb>' . IMG($img, $alt ?: $leg, '', false, $sizes) . '</a>' . ($leg !== '' ? '<figcaption>' . e($leg) . '</figcaption>' : '') . credito($cred) . '</figure>';
}
function video($v, $cls = '') {
  $v = trim((string)$v); if ($v === '') return '';
  if (preg_match('#(?:youtube\.com/(?:watch\?v=|embed/|shorts/)|youtu\.be/)([\w-]{11})#', $v, $m)) return '<div class="vid ' . $cls . '"><iframe src="https://www.youtube-nocookie.com/embed/' . $m[1] . '" title="Vídeo" loading="lazy" allow="accelerometer; encrypted-media; picture-in-picture" allowfullscreen></iframe></div>';
  if (preg_match('#vimeo\.com/(?:video/)?(\d+)#', $v, $m)) return '<div class="vid ' . $cls . '"><iframe src="https://player.vimeo.com/video/' . $m[1] . '" title="Vídeo" loading="lazy" allowfullscreen></iframe></div>';
  if (preg_match('#\.(mp4|webm|mov)(\?|$)#i', $v)) return '<video class="vid-file ' . $cls . '" src="' . e(asset($v)) . '" playsinline controls preload="metadata"></video>';
  return '';
}

/* markdown simples para o blog */
function mdInline($s) {
  $s = e($s);
  $s = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $s);
  $s = preg_replace('/(?<![\*\w])\*(?!\s)(.+?)(?<!\s)\*(?!\*)/s', '<em>$1</em>', $s);
  $s = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', function ($m) { $h = html_entity_decode($m[2]); if (!preg_match('#^(https?://|/|mailto:|\#)#i', $h) && !preg_match('#^[\w./-]+$#', $h)) return $m[0]; $x = preg_match('#^https?://#i', $h) ? ' target="_blank" rel="noopener"' : ''; return '<a href="' . e(url($h)) . '"' . $x . '>' . $m[1] . '</a>'; }, $s);
  return $s;
}
function md($txt) {
  $txt = str_replace("\r", '', (string)$txt); $o = ''; $blocos = preg_split('/\n\s*\n/', trim($txt));
  foreach ($blocos as $b) {
    $b = trim($b); if ($b === '') continue;
    if (preg_match('/^(#{2,3})\s+(.+)$/s', $b, $m)) { $n = strlen($m[1]); $o .= "<h$n>" . mdInline($m[2]) . "</h$n>"; continue; }
    if (preg_match('/^!\[([^\]]*)\]\(([^)\s]+)\)$/', $b, $m)) { $o .= '<figure class="pimg"><img src="' . e(asset($m[2])) . '" alt="' . e($m[1]) . '" loading="lazy" decoding="async">' . ($m[1] !== '' ? '<figcaption>' . e($m[1]) . '</figcaption>' : '') . '</figure>'; continue; }
    if (preg_match('#^https?://\S+$#', $b)) { $v = video($b); if ($v !== '') { $o .= $v; continue; } }
    if (preg_match('/^>\s?/', $b)) { $o .= '<blockquote>' . mdInline(preg_replace('/^>\s?/m', '', $b)) . '</blockquote>'; continue; }
    if (preg_match('/^[-*]\s+/', $b)) { $o .= '<ul>'; foreach (preg_split('/\n/', $b) as $li) $o .= '<li>' . mdInline(preg_replace('/^[-*]\s+/', '', $li)) . '</li>'; $o .= '</ul>'; continue; }
    $o .= '<p>' . nl2br(mdInline($b), false) . '</p>';
  }
  return $o;
}

/* ---------- SEO ---------- */
function origem() {
  $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
  return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}
function siteUrl() { $g = seoCfg()['global'] ?? []; $b = trim((string)($g['canonical_base'] ?? '')); return $b !== '' ? rtrim($b, '/') : origem() . BASE; }
function seoPagina($slug) {
  global $SEO_PAGINAS; $s = seoCfg(); $p = ($s['paginas'] ?? [])[$slug] ?? []; $d = $SEO_PAGINAS[$slug] ?? ['', '', ''];
  return ['title' => trim($p['title'] ?? '') ?: $d[1], 'description' => trim($p['description'] ?? '') ?: $d[2], 'og_image' => trim($p['og_image'] ?? ''), 'robots' => trim($p['robots'] ?? ''), 'canonical' => trim($p['canonical'] ?? '')];
}
function headHtml($slug, $seo, $path, $extraLd = '') {
  $g = seoCfg()['global'] ?? []; $site = trim($g['nome_site'] ?? '') ?: 'Biosphere by C. Reymond';
  $canon = $seo['canonical'] ?: siteUrl() . ($path === '' ? '/' : '/' . $path);
  $img = $seo['og_image'] ?: ($g['og_image'] ?? '') ?: V('home', 'hero_img'); $imgAbs = preg_match('#^https?:#i', $img) ? $img : siteUrl() . '/' . ltrim($img, '/');
  $rob = $seo['robots'] ?: ($g['robots'] ?? '') ?: 'index,follow,max-image-preview:large';
  $sim = V('global', 'simbolo') ?: 'assets/simbolo.png';
  $h = '<title>' . e($seo['title']) . '</title><meta name="description" content="' . e($seo['description']) . '"><link rel="canonical" href="' . e($canon) . '"><meta name="robots" content="' . e($rob) . '">';
  $h .= '<meta property="og:type" content="' . ($slug === 'post' ? 'article' : 'website') . '"><meta property="og:site_name" content="' . e($site) . '"><meta property="og:locale" content="pt_BR"><meta property="og:title" content="' . e($seo['title']) . '"><meta property="og:description" content="' . e($seo['description']) . '"><meta property="og:url" content="' . e($canon) . '"><meta property="og:image" content="' . e($imgAbs) . '">';
  $h .= '<meta name="twitter:card" content="summary_large_image"><meta name="twitter:title" content="' . e($seo['title']) . '"><meta name="twitter:description" content="' . e($seo['description']) . '"><meta name="twitter:image" content="' . e($imgAbs) . '">';
  if (!empty($g['google_verificacao'])) $h .= '<meta name="google-site-verification" content="' . e($g['google_verificacao']) . '">';
  if (!empty($g['bing_verificacao'])) $h .= '<meta name="msvalidate.01" content="' . e($g['bing_verificacao']) . '">';
  if (!empty($g['meta_verificacao'])) $h .= '<meta name="facebook-domain-verification" content="' . e($g['meta_verificacao']) . '">';
  $h .= '<link rel="icon" href="' . e(asset($sim)) . '" type="image/png">';
  $ld = ['@context' => 'https://schema.org', '@graph' => [['@type' => 'Organization', '@id' => siteUrl() . '/#org', 'name' => $site, 'url' => siteUrl() . '/', 'logo' => siteUrl() . '/' . ltrim(V('global', 'logo'), '/'), 'sameAs' => array_values(array_filter([trim($g['instagram_url'] ?? '') ?: 'https://www.instagram.com/' . ltrim(V('global', 'instagram'), '@')]))], ['@type' => 'WebSite', 'url' => siteUrl() . '/', 'name' => $site, 'inLanguage' => 'pt-BR']]];
  $h .= '<script type="application/ld+json">' . json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>' . $extraLd;
  if (!empty($g['ga4'])) $h .= '<script async src="https://www.googletagmanager.com/gtag/js?id=' . e($g['ga4']) . '"></script><script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments)}gtag("js",new Date());gtag("config",' . json_encode($g['ga4']) . ');</script>';
  if (!empty($g['meta_pixel'])) $h .= '<script>!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version="2.0";n.queue=[];t=b.createElement(e);t.async=!0;t.src="https://connect.facebook.net/en_US/fbevents.js";s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,"script");fbq("init",' . json_encode($g['meta_pixel']) . ');fbq("track","PageView");</script>';
  if (!empty($g['head_extra'])) $h .= $g['head_extra'];
  return $h;
}

/* ---------- moldura ---------- */
function menuItens() { return [['conceito', 'Conceito'], ['quem-faz', 'Quem faz'], ['projetos', 'Projetos'], ['incorporadoras', 'Incorporadoras'], ['blog', 'Blog'], ['contato', 'Contato']]; }
function moldura($slug, $seo, $path, $corpo, $extraLd = '', $escuroTopo = false) {
  $logo = V('global', 'logo'); $logoB = V('global', 'logo_branco') ?: $logo; $ig = V('global', 'instagram'); $ign = V('global', 'instagram_novos');
  $m = ''; foreach (menuItens() as $it) $m .= '<a href="' . e(u($it[0])) . '"' . ((strpos($path, $it[0]) === 0) ? ' class="on" aria-current="page"' : '') . '>' . e($it[1]) . '</a>';
  $css = RAIZ . '/assets/site.css'; $js = RAIZ . '/assets/site.js';
  $o = '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">' . headHtml($slug, $seo, $path, $extraLd);
  $o .= '<link rel="preload" href="' . e(BASE) . '/assets/fonts/lato-latin-300-normal.woff2" as="font" type="font/woff2" crossorigin><link rel="stylesheet" href="' . e(BASE) . '/assets/site.css?v=' . @filemtime($css) . '"></head><body>';
  $o .= '<a class="pula" href="#c">Pular para o conteúdo</a><header class="top' . ($escuroTopo ? ' sobre' : '') . '" id="top"><a class="logo" href="' . e(u()) . '" aria-label="Biosphere by C. Reymond">' . IMG($logo, 'Biosphere by C. Reymond', 'l-c', true) . IMG($logoB, '', 'l-b', true) . '</a><nav id="nav" aria-label="Principal">' . $m . '</nav><button class="burger" id="bg" aria-label="Menu" aria-expanded="false"><i></i><i></i></button></header>';
  $o .= '<main id="c">' . $corpo . '</main>';
  $o .= '<footer class="rod"><div class="wrap"><div class="rod-top"><a class="logo" href="' . e(u()) . '">' . IMG($logoB, 'Biosphere by C. Reymond', '', false) . '</a><p class="lema">' . e(V('global', 'lema')) . '</p><p class="redes"><a href="https://www.instagram.com/' . e(ltrim($ig, '@')) . '" target="_blank" rel="noopener">' . e($ig) . '</a>' . ($ign !== '' ? '<span>Novos empreendimentos: <a href="https://www.instagram.com/' . e(ltrim($ign, '@')) . '" target="_blank" rel="noopener">' . e($ign) . '</a></span>' : '') . '</p></div><div class="legal">' . paras(V('global', 'rodape')) . '</div><p class="cp">© ' . date('Y') . ' Biosphere by C. Reymond</p></div></footer>';
  $o .= '<div class="lb" id="lb" hidden><button class="lb-x" aria-label="Fechar">×</button><img alt=""><p></p></div><script src="' . e(BASE) . '/assets/site.js?v=' . @filemtime($js) . '" defer></script></body></html>';
  return $o;
}

/* ---------- blocos editoriais ---------- */
function blk($id, $rev = false) {
  global $BLOCOS; $tipo = $BLOCOS[$id] ?? 'split'; $v = function ($c) use ($id) { return V($id, $c); };
  if ($v('titulo') === '' && $v('texto') === '' && $v('img') === '') return '';
  $tg = tag($v('tag'));
  if ($tipo === 'wide') {
    return '<section class="sec wide rv"><div class="wrap">' . fig($v('img'), '', $v('credito'), $v('titulo'), '100vw') . '<div class="wide-t"><h2>' . t($v('titulo')) . '</h2><div class="lead">' . paras($v('texto')) . '</div></div></div></section>';
  }
  if ($tipo === 'duo') {
    return '<section class="sec duo rv"><div class="wrap">' . $tg . '<h2>' . t($v('titulo')) . '</h2><div class="pair">' . fig($v('img'), $v('legenda'), $v('credito'), '', '(min-width:900px) 48vw, 100vw') . fig($v('img2'), $v('legenda2'), $v('credito2'), '', '(min-width:900px) 48vw, 100vw') . '</div>' . ($v('texto') !== '' ? '<div class="apoio">' . paras($v('texto')) . '</div>' : '') . '</div></section>';
  }
  return '<section class="sec split' . ($tipo === 'dark' ? ' dark' : '') . ($rev ? ' rev' : '') . ' rv"><div class="wrap grid"><div class="txt">' . $tg . '<h2>' . t($v('titulo')) . '</h2><div class="lead">' . paras($v('texto')) . '</div></div>' . fig($v('img'), $v('legenda'), $v('credito'), $v('titulo')) . '</div></section>';
}
function pilares($escuro = true) {
  $o = '<section class="sec dark pilares rv"><div class="wrap">' . tag(V('pilares', 'pil_tag')) . '<h2>' . t(V('pilares', 'pil_titulo')) . '</h2><div class="pgrid">';
  for ($i = 1; $i <= 8; $i++) { $n = V('pilares', "pil{$i}_t"); if ($n === '') continue; $o .= '<div class="pil"><img class="simb" src="' . e(asset(V('global', 'simbolo'))) . '" alt="" width="30" height="30" loading="lazy"><h3>' . e($n) . '</h3><p>' . e(V('pilares', "pil{$i}_d")) . '</p></div>'; }
  return $o . '</div></div></section>';
}
function cta() {
  return '<section class="sec cta rv"><div class="wrap">' . tag(V('h_portfolio', 'cta_tag')) . '<h2>' . t(V('h_portfolio', 'cta_titulo')) . '</h2><p class="lead">' . nl2br(e(V('h_portfolio', 'cta_texto')), false) . '</p><a class="btn" href="' . e(u('incorporadoras')) . '">' . e(V('h_portfolio', 'cta_botao')) . '</a><p class="redes">Novos empreendimentos: <a href="https://www.instagram.com/' . e(ltrim(V('global', 'instagram_novos'), '@')) . '" target="_blank" rel="noopener">' . e(V('global', 'instagram_novos')) . '</a><br><span>' . e(V('global', 'instagram')) . '</span></p></div></section>';
}
function projetos3() { return [['essence', 'j_essence', 'projeto-essence'], ['cocoon', 'j_cocoon', 'projeto-cocoon'], ['vistas', 'j_vistas', 'projeto-vistas']]; }

/* ---------- páginas ---------- */
function paginaHome() {
  $v = function ($c) { return V('home', $c); }; $vid = video($v('hero_video'), 'hero-vid');
  $m = $vid !== '' ? $vid : '<figure class="hero-img">' . IMG($v('hero_img'), '', '', true, '(min-width:900px) 50vw, 100vw') . '<figcaption>' . e($v('hero_credito')) . '<br>' . e(V('global', 'aviso')) . '</figcaption></figure>';
  $o = '<section class="hero"><div class="hero-t">' . tag($v('hero_tag')) . '<h1>' . t($v('hero_titulo')) . '</h1><p class="sub">' . e($v('hero_sub')) . '</p>' . ($v('hero_cta') !== '' ? '<a class="btn" href="' . e(url($v('hero_cta_link'))) . '">' . e($v('hero_cta')) . '</a>' : '') . '</div>' . $m . '</section>';
  $o .= blk('h_conceito') . blk('h_esferas') . pilares();
  $o .= '<section class="sec port rv"><div class="wrap">' . tag(V('h_portfolio', 'port_tag')) . '<h2>' . t(V('h_portfolio', 'port_titulo')) . '</h2><div class="cards">';
  foreach (projetos3() as $p) { $g = $p[1]; $o .= '<a class="card" href="' . e(u($p[2])) . '"><span class="ci">' . IMG(V($g, 'card'), V($g, 'nome'), '', false, '(min-width:900px) 33vw, 100vw') . '</span><h3>' . e(V($g, 'local')) . '</h3><p>' . e(V($g, 'nome')) . '</p></a>'; }
  $o .= '</div></div></section><section class="sec pess rv"><div class="wrap">' . tag(V('h_portfolio', 'pess_tag')) . '<h2>' . t(V('h_portfolio', 'pess_titulo')) . '</h2><div class="trio">';
  foreach (['p_caua', 'p_marcus', 'p_luciano'] as $g) $o .= '<a class="pessoa" href="' . e(u('quem-faz')) . '#' . $g . '"><span class="ci">' . IMG(V($g, 'img'), V($g, 'nome'), '', false, '(min-width:900px) 25vw, 60vw') . '</span><h3>' . e(V($g, 'nome')) . '</h3><p>' . e(V($g, 'tag')) . '</p></a>';
  return $o . '</div></div></section>' . cta();
}
function paginaConceito() {
  $v = function ($c) { return V('conceito', $c); };
  $o = '<section class="sec split abre"><div class="wrap grid"><div class="txt">' . tag($v('tag')) . '<h1>' . t($v('titulo')) . '</h1><div class="lead">' . paras($v('texto')) . '</div></div>' . fig($v('img'), $v('legenda'), $v('credito'), $v('titulo')) . '</div></section>';
  $o .= blk('c_assinatura') . pilares() . blk('c_natureza') . blk('c_movimento') . blk('c_tempo') . blk('c_pessoas') . blk('c_tecnologia');
  $p = function ($i) { return [V('c_processo', "p{$i}_t"), V('c_processo', "p{$i}_d")]; };
  $lista = function ($a, $b) use ($p) { $h = ''; for ($i = $a; $i <= $b; $i++) { $x = $p($i); if ($x[0] === '') continue; $h .= '<li><span class="n">' . sprintf('%02d', $i) . '</span><div><h3>' . e($x[0]) . '</h3><p>' . e($x[1]) . '</p></div></li>'; } return $h; };
  $o .= '<section class="sec passos rv"><div class="wrap grid"><div class="txt">' . tag(V('c_processo', 'tag')) . '<h2>' . t(V('c_processo', 'titulo')) . '</h2><div class="lead">' . paras(V('c_processo', 'texto')) . '</div></div><ol class="steps">' . $lista(1, 3) . '</ol></div></section>';
  $o .= '<section class="sec dark passos rv"><div class="wrap grid"><div class="txt"><h2>' . t(V('c_processo', 'titulo2')) . '</h2><div class="lead">' . paras(V('c_processo', 'texto2')) . '</div></div><ol class="steps">' . $lista(4, 7) . '</ol></div></section>';
  return $o . cta();
}
function paginaQuemFaz() {
  $o = ''; $i = 0;
  foreach (['p_caua', 'p_marcus', 'p_luciano'] as $g) {
    $v = function ($c) use ($g) { return V($g, $c); }; $i++;
    $o .= '<section class="sec split pessoa-s' . ($i % 2 === 0 ? ' rev' : '') . ($i === 1 ? ' abre' : '') . ' rv" id="' . $g . '"><div class="wrap grid"><div class="txt">' . tag($v('tag')) . ($i === 1 ? '<h1>' : '<h2>') . t(str_replace(' ', "\n", $v('nome'), $cnt)) . ($i === 1 ? '</h1>' : '</h2>') . '<p class="dest">' . nl2br(e($v('sub')), false) . '</p><div class="lead">' . paras($v('texto')) . '</div></div><figure class="fig ret"><a class="zoom" href="' . e(asset($v('img'))) . '" data-lb>' . IMG($v('img'), $v('nome'), '', $i === 1, '(min-width:900px) 40vw, 90vw') . '</a><figcaption class="so">' . e($v('credito')) . '</figcaption></figure></div></section>';
  }
  $mv = function ($c) { return V('p_midia', $c); }; $cards = '';
  for ($n = 1; $n <= 3; $n++) { if ($mv("m{$n}_t") === '') continue; $im = $mv("m{$n}_img"); $l = $mv("m{$n}_link"); $in = ($im !== '' ? '<span class="ci">' . IMG($im, $mv("m{$n}_t"), '', false, '(min-width:900px) 30vw, 100vw') . '</span>' : '') . '<h3>' . e($mv("m{$n}_t")) . '</h3><p>' . e($mv("m{$n}_d")) . '</p>'; $cards .= $l !== '' ? '<a class="midia" href="' . e(url($l)) . '" target="_blank" rel="noopener">' . $in . '</a>' : '<div class="midia">' . $in . '</div>'; }
  if ($cards !== '') $o .= '<section class="sec rv"><div class="wrap">' . tag($mv('tag')) . '<h2>' . e($mv('titulo')) . '</h2><div class="cards">' . $cards . '</div></div></section>';
  return $o . cta();
}
function paginaProjetos() {
  $o = '<section class="sec abre-p"><div class="wrap">' . tag(V('h_portfolio', 'port_tag')) . '<h1>' . t(V('h_portfolio', 'port_titulo')) . '</h1></div></section><section class="sec port rv"><div class="wrap"><div class="cards big">';
  foreach (projetos3() as $p) { $g = $p[1]; $o .= '<a class="card" href="' . e(u($p[2])) . '"><span class="ci">' . IMG(V($g, 'card'), V($g, 'nome'), '', false, '(min-width:900px) 33vw, 100vw') . '</span><h3>' . e(V($g, 'local')) . '</h3><p class="nm">' . e(V($g, 'nome')) . '</p><p>' . e(V($g, 'texto')) . '</p>' . (V($g, 'real') !== '' ? '<p class="re">Realização: ' . e(V($g, 'real')) . '</p>' : '') . '</a>'; }
  $o .= '</div></div></section>';
  $b = function ($c) { return V('j_brasil', $c); }; $l = '';
  for ($i = 1; $i <= 3; $i++) if ($b("b{$i}_t") !== '') $l .= '<div class="br"><h3>' . e($b("b{$i}_t")) . '</h3><span class="loc">' . e($b("b{$i}_l")) . '</span><p>' . e($b("b{$i}_d")) . '</p></div>';
  $o .= '<section class="sec passos rv"><div class="wrap grid"><div class="txt">' . tag($b('tag')) . '<h2>' . t($b('titulo')) . '</h2><p class="dest">' . e($b('sub')) . '</p><div class="lead">' . paras($b('texto')) . '</div></div><div class="brl">' . $l . '</div></div></section>';
  return $o . cta();
}
function paginaProjeto($k) {
  $map = ['essence' => ['j_essence', ['e_rotina', 'e_ritmo']], 'cocoon' => ['j_cocoon', ['k_caminho', 'k_paisagem', 'k_lugar', 'k_movimento', 'k_essencial']], 'vistas' => ['j_vistas', ['v_terra', 'v_esporte', 'v_represa', 'v_cuidado', 'v_encontros', 'v_tempo', 'v_habitat']]];
  $g = $map[$k][0]; $v = function ($c) use ($g) { return V($g, $c); };
  $meta = '<p class="meta"><span>' . e($v('local')) . '</span>' . ($v('real') !== '' ? '<span>Realização: ' . e($v('real')) . '</span>' : '') . '</p>';
  $o = '<section class="sec split abre"><div class="wrap grid"><div class="txt">' . tag($v('nome')) . '<h1>' . t($v('titulo')) . '</h1>' . $meta . '<div class="lead">' . paras($v('texto')) . '</div></div>' . fig($v('img'), '', $v('credito'), $v('titulo')) . '</div></section>';
  $i = 0; foreach ($map[$k][1] as $b) { $o .= blk($b, ++$i % 2 === 0); }
  $o .= '<section class="sec outros rv"><div class="wrap"><h2>Outros projetos</h2><div class="cards">';
  foreach (projetos3() as $p) if ($p[0] !== $k) $o .= '<a class="card" href="' . e(u($p[2])) . '"><span class="ci">' . IMG(V($p[1], 'card'), V($p[1], 'nome'), '', false, '(min-width:900px) 33vw, 100vw') . '</span><h3>' . e(V($p[1], 'local')) . '</h3><p>' . e(V($p[1], 'nome')) . '</p></a>';
  return $o . '</div></div></section>' . cta();
}
function formHtml($tipo = 'incorporadoras') {
  $esc = $tipo === 'incorporadoras';
  return '<form class="form" id="fm" method="post" action="' . e(u('enviar')) . '" novalidate><input type="hidden" name="tipo" value="' . e($tipo) . '"><input type="text" name="site" class="hp" tabindex="-1" autocomplete="off" aria-hidden="true"><input type="hidden" name="t0" value="' . time() . '">'
    . '<label>Nome<input name="nome" required autocomplete="name"></label><label>E-mail<input name="email" type="email" required autocomplete="email"></label><label>Telefone / WhatsApp<input name="telefone" autocomplete="tel"></label>'
    . ($esc ? '<label>Empresa<input name="empresa" autocomplete="organization"></label><label>Cidade do empreendimento<input name="cidade"></label>' : '<label>Assunto<select name="assunto"><option>Quero conhecer os empreendimentos</option><option>Imprensa e parcerias</option><option>Sou incorporadora</option><option>Outro</option></select></label>')
    . '<label class="full">Mensagem<textarea name="mensagem" rows="5"></textarea></label><button class="btn" type="submit">Enviar</button><p class="fm-st" id="fs" role="status"></p></form>';
}
function paginaIncorporadoras() {
  $v = function ($c) { return V('incorporadoras', $c); }; $c = '';
  for ($i = 1; $i <= 3; $i++) if ($v("i{$i}_t") !== '') $c .= '<div class="p3"><h3>' . e($v("i{$i}_t")) . '</h3><p>' . e($v("i{$i}_d")) . '</p></div>';
  return '<section class="sec verde abre-v"><div class="wrap">' . tag($v('tag')) . '<h1>' . t($v('titulo')) . '</h1><div class="trio3">' . $c . '</div></div></section>'
    . '<section class="sec rv"><div class="wrap grid"><div class="txt"><h2>' . e($v('form_titulo')) . '</h2><div class="lead">' . paras(V('contato', 'texto')) . '</div></div>' . formHtml('incorporadoras') . '</div></section>' . cta();
}
function paginaContato() {
  $v = function ($c) { return V('contato', $c); };
  return '<section class="sec abre-p rv"><div class="wrap grid"><div class="txt"><h1>' . t($v('titulo')) . '</h1><div class="lead">' . paras($v('texto')) . '</div><p class="redes"><a href="https://www.instagram.com/' . e(ltrim(V('global', 'instagram'), '@')) . '" target="_blank" rel="noopener">' . e(V('global', 'instagram')) . '</a></p></div>' . formHtml('geral') . '</div></section>';
}
function paginaBlog() {
  $v = function ($c) { return V('blog', $c); }; $l = posts(); $o = '<section class="sec abre-p"><div class="wrap">' . tag($v('tag')) . '<h1>' . t($v('titulo')) . '</h1><p class="lead">' . e($v('texto')) . '</p></div></section><section class="sec rv"><div class="wrap">';
  if (!$l) return $o . '<p class="vazio">Em breve, novos artigos.</p></div></section>';
  $o .= '<div class="cards blog">';
  foreach ($l as $p) $o .= '<a class="card" href="' . e(u('blog/' . $p['slug'])) . '">' . (!empty($p['capa']) ? '<span class="ci">' . IMG($p['capa'], $p['titulo'], '', false, '(min-width:900px) 33vw, 100vw') . '</span>' : '') . '<time datetime="' . e($p['data']) . '">' . e(dataBr($p['data'])) . '</time><h3>' . e($p['titulo']) . '</h3><p>' . e($p['resumo'] ?? '') . '</p></a>';
  return $o . '</div></div></section>';
}
function dataBr($d) { $m = ['', 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro']; if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', (string)$d, $x)) return (string)$d; return (int)$x[3] . ' de ' . $m[(int)$x[2]] . ' de ' . $x[1]; }
function paginaPost($p) {
  $o = '<article class="sec post"><div class="wrap narrow"><a class="volta" href="' . e(u('blog')) . '">← Blog</a><time datetime="' . e($p['data']) . '">' . e(dataBr($p['data'])) . '</time><h1>' . e($p['titulo']) . '</h1>' . (!empty($p['resumo']) ? '<p class="lead">' . e($p['resumo']) . '</p>' : '') . '</div>';
  if (!empty($p['capa'])) $o .= '<div class="wrap"><figure class="fig capa">' . IMG($p['capa'], $p['titulo'], '', true, '(min-width:1100px) 1000px, 100vw') . '</figure></div>';
  $o .= '<div class="wrap narrow corpo">' . md($p['conteudo'] ?? '') . '</div></article>' . cta();
  return $o;
}
