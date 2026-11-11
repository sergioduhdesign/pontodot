<?php
/* robots.txt e sitemap.xml dinâmicos, a partir de conteudo/seo.json (configurado pela PD). */
$dir = __DIR__;
$t = @file_get_contents($dir . '/conteudo/seo.json'); $seo = $t ? (json_decode($t, true) ?: []) : [];
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
$origem = ($https ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'geo2030.com.br');
$f = $_GET['f'] ?? '';
if ($f === 'robots') {
  header('Content-Type: text/plain; charset=utf-8');
  if (stripos((string)($seo['robots'] ?? ''), 'noindex') !== false) { echo "User-agent: *\nDisallow: /\n"; exit; }
  echo "User-agent: *\nAllow: /\nDisallow: /admin/\nDisallow: /api.php\nDisallow: /conteudo/\n";
  if (!empty($seo['robots_txt_extra'])) echo trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string)$seo['robots_txt_extra'])) . "\n";
  echo "\nSitemap: " . $origem . "/sitemap.xml\n"; exit;
}
if ($f === 'sitemap') {
  header('Content-Type: application/xml; charset=utf-8');
  $urls = $seo['sitemap'] ?? [['loc' => $origem . '/']]; $mod = date('Y-m-d', @filemtime($dir . '/conteudo/compromissos.json') ?: time());
  echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
  foreach ($urls as $u) { $loc = is_array($u) ? ($u['loc'] ?? '') : (string)$u; if (!preg_match('#^https?://#i', $loc)) continue;
    echo '<url><loc>' . htmlspecialchars($loc, ENT_XML1) . '</loc><lastmod>' . htmlspecialchars(is_array($u) && !empty($u['lastmod']) ? $u['lastmod'] : $mod, ENT_XML1) . '</lastmod></url>' . "\n"; }
  echo '</urlset>'; exit;
}
http_response_code(404);
