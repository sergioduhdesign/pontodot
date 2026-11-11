<?php
/* GEO2030 · entrega a página com SEO configurável (conteudo/seo.json, escrito pela PD) e conteúdo publicado (conteudo/compromissos.json). */
$dir = __DIR__;
function gj($f) { $t = @file_get_contents($f); $j = $t ? json_decode($t, true) : null; return is_array($j) ? $j : []; }
function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
$html = @file_get_contents($dir . '/index.html');
if ($html === false) { http_response_code(500); exit('index.html ausente'); }
$seo = gj($dir . '/conteudo/seo.json'); $cont = gj($dir . '/conteudo/compromissos.json');
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
$origem = ($https ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'geo2030.com.br');
$abs = function ($u) use ($origem) { $u = trim((string)$u); if ($u === '') return ''; return preg_match('#^https?://#i', $u) ? $u : $origem . '/' . ltrim($u, '/'); };

$titulo = trim($seo['title'] ?? '') ?: 'GEO2030 · Territórios que Cuidam';
$desc = trim($seo['description'] ?? '');
$canon = trim($seo['canonical'] ?? '') ?: $origem . '/';
$robots = trim($seo['robots'] ?? '') ?: 'index,follow,max-image-preview:large';
$lang = trim($seo['lang'] ?? '') ?: 'pt-BR';
$og = $seo['og'] ?? []; $tw = $seo['twitter'] ?? [];

$h = [];
$h[] = '<link rel="canonical" href="' . e($canon) . '">';
$h[] = '<meta name="robots" content="' . e($robots) . '">';
if (!empty($seo['theme_color'])) $h[] = '<meta name="theme-color" content="' . e($seo['theme_color']) . '">';
$ogT = trim($og['title'] ?? '') ?: $titulo; $ogD = trim($og['description'] ?? '') ?: $desc; $ogI = $abs($og['image'] ?? '');
$h[] = '<meta property="og:type" content="' . e($og['type'] ?? 'website') . '">';
$h[] = '<meta property="og:site_name" content="' . e($og['site_name'] ?? 'GEO2030') . '">';
$h[] = '<meta property="og:locale" content="' . e($og['locale'] ?? 'pt_BR') . '">';
$h[] = '<meta property="og:url" content="' . e($canon) . '">';
$h[] = '<meta property="og:title" content="' . e($ogT) . '">';
if ($ogD !== '') $h[] = '<meta property="og:description" content="' . e($ogD) . '">';
if ($ogI !== '') $h[] = '<meta property="og:image" content="' . e($ogI) . '">';
$h[] = '<meta name="twitter:card" content="' . e($tw['card'] ?? ($ogI !== '' ? 'summary_large_image' : 'summary')) . '">';
$h[] = '<meta name="twitter:title" content="' . e(trim($tw['title'] ?? '') ?: $ogT) . '">';
$twD = trim($tw['description'] ?? '') ?: $ogD; if ($twD !== '') $h[] = '<meta name="twitter:description" content="' . e($twD) . '">';
$twI = $abs($tw['image'] ?? '') ?: $ogI; if ($twI !== '') $h[] = '<meta name="twitter:image" content="' . e($twI) . '">';
if (!empty($seo['verificacoes']['google'])) $h[] = '<meta name="google-site-verification" content="' . e($seo['verificacoes']['google']) . '">';
if (!empty($seo['verificacoes']['bing'])) $h[] = '<meta name="msvalidate.01" content="' . e($seo['verificacoes']['bing']) . '">';
foreach (preg_split('/\r?\n/', (string)($seo['head_extra'] ?? '')) as $l) { $l = trim($l); if ($l !== '' && preg_match('/^<(meta|link)\b[^<>]*>$/i', $l) && !preg_match('/on\w+\s*=|javascript:/i', $l)) $h[] = $l; }
/* dados estruturados: os que a PD mandar, ou Organization + WebSite a partir dos dados da organização */
$ld = [];
foreach (($seo['jsonld'] ?? []) as $o) if (is_array($o) && !empty($o['@type'])) $ld[] = $o + ['@context' => 'https://schema.org'];
if (!$ld) {
  $org = $seo['organizacao'] ?? [];
  if (!empty($org['nome'])) {
    $o = ['@context' => 'https://schema.org', '@type' => 'Organization', 'name' => $org['nome'], 'url' => $org['url'] ?? $origem . '/'];
    if (!empty($org['logo'])) $o['logo'] = $abs($org['logo']);
    if (!empty($org['sameAs'])) $o['sameAs'] = array_values((array)$org['sameAs']);
    $ld[] = $o;
  }
  $ld[] = ['@context' => 'https://schema.org', '@type' => 'WebSite', 'name' => $titulo, 'url' => $canon, 'inLanguage' => $lang];
}
foreach ($ld as $o) $h[] = '<script type="application/ld+json">' . json_encode($o, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . '</script>';
if ($cont) $h[] = '<script>window.GEO_CONTENT=' . json_encode($cont, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS) . ';</script>';

/* título, descrição e idioma no lugar dos antigos */
$html = preg_replace('#<title>.*?</title>#is', '<title>' . e($titulo) . '</title>', $html, 1);
$descTag = $desc !== '' ? '<meta name="description" content="' . e($desc) . '">' : '';
$html = preg_match('#<meta\s+name="description"[^>]*>#i', $html) ? preg_replace('#<meta\s+name="description"[^>]*>#i', $descTag, $html, 1) : str_replace('</title>', '</title>' . $descTag, $html);
$html = preg_replace('#<html lang="[^"]*"#i', '<html lang="' . e($lang) . '"', $html, 1);

/* texto para quem não roda script (buscadores, leitores de tela) */
$sr = '<div class="sr-seo"><h1>' . e($titulo) . '</h1>' . ($desc !== '' ? '<p>' . e($desc) . '</p>' : '');
$cs = $cont['compromissos'] ?? [];
if ($cs) {
  ksort($cs, SORT_NUMERIC); $sr .= '<ul>';
  foreach ($cs as $n => $c) { if (empty($c['name'])) continue; $sr .= '<li><h2>' . e($c['name']) . '</h2>' . (!empty($c['why']) ? '<p>' . e($c['why']) . '</p>' : '') . (!empty($c['status']) ? '<p>' . e($c['status']) . '</p>' : '') . '</li>'; }
  $sr .= '</ul>';
}
$sr .= '</div><noscript><p style="position:fixed;top:90px;left:20px;right:20px;z-index:9">Este site usa JavaScript para exibir o relógio dos doze compromissos.</p></noscript>';

$v = max(@filemtime($dir . '/assets/geo.css'), @filemtime($dir . '/assets/geo.js'), @filemtime($dir . '/assets/merge.js'));
$html = str_replace(['assets/geo.css"', 'assets/geo.js"', 'assets/merge.js"'], ['assets/geo.css?v=' . $v . '"', 'assets/geo.js?v=' . $v . '"', 'assets/merge.js?v=' . $v . '"'], $html);
$html = str_replace(['<!--GEO_HEAD-->', '<!--GEO_BODY-->'], [implode("\n", $h), $sr], $html);

$etag = '"' . md5($html) . '"';
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: public, max-age=60, must-revalidate');
header('ETag: ' . $etag);
if (stripos($robots, 'noindex') !== false) header('X-Robots-Tag: ' . $robots);
if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) { http_response_code(304); exit; }
echo $html;
