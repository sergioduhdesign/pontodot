<?php
define('STRAT', 1); require __DIR__ . '/_lib.php';
header('Content-Type: application/xml; charset=utf-8'); header('Cache-Control: public, max-age=600');
$A = artigos(true); $x = function ($s) { return htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8'); };
$U = [[SITE . '/', date('Y-m-d', filemtime(__DIR__ . '/index.html')), '1.0']];
if ($A) $U[] = [SITE . '/artigos/', $A[0]['data'], '0.7'];
foreach ($A as $a) $U[] = [SITE . '/artigos/' . $a['slug'], substr($a['atualizado'] ?? $a['data'], 0, 10), '0.6'];
$U[] = [SITE . '/privacidade', date('Y-m-d', filemtime(__DIR__ . '/privacidade.html')), '0.2'];
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($U as $u) echo '<url><loc>' . $x($u[0]) . '</loc><lastmod>' . $x($u[1]) . '</lastmod><priority>' . $u[2] . "</priority></url>\n";
echo "</urlset>\n";
