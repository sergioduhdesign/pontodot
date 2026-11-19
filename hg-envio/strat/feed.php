<?php
define('STRAT', 1); require __DIR__ . '/_lib.php';
header('Content-Type: application/rss+xml; charset=utf-8'); header('Cache-Control: public, max-age=600');
$A = array_slice(artigos(true), 0, 30); $x = function ($s) { return htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8'); };
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n"; ?>
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:content="http://purl.org/rss/1.0/modules/content/">
<channel>
<title>Stratesign — Artigos</title>
<link><?= SITE ?>/artigos/</link>
<atom:link href="<?= SITE ?>/feed.xml" rel="self" type="application/rss+xml"/>
<description>A agência do imobiliário brasileiro. Textos sobre mercado, marca e lançamento.</description>
<language>pt-BR</language>
<?php if ($A): ?><lastBuildDate><?= date(DATE_RSS, strtotime($A[0]['data'] . ' 12:00:00')) ?></lastBuildDate>
<?php endif; foreach ($A as $a): $u = SITE . '/artigos/' . $a['slug']; ?>
<item>
<title><?= $x($a['titulo']) ?></title>
<link><?= $x($u) ?></link>
<guid isPermaLink="true"><?= $x($u) ?></guid>
<pubDate><?= date(DATE_RSS, strtotime($a['data'] . ' 12:00:00')) ?></pubDate>
<author>contato@stratesign.com.br (<?= $x($a['autor'] ?? 'Stratesign') ?>)</author>
<description><?= $x(resumo_de($a)) ?></description>
<content:encoded><![CDATA[<?= str_replace(']]>', ']]]]><![CDATA[>', str_replace('src="/img/', 'src="' . SITE . '/img/', corpo_html($a['corpo'] ?? ''))) ?>]]></content:encoded>
</item>
<?php endforeach; ?>
</channel>
</rss>
