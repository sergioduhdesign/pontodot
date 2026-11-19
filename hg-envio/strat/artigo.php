<?php
define('STRAT', 1); require __DIR__ . '/_lib.php';
$a = artigo_por_slug(preg_replace('/[^a-z0-9-]/', '', (string)($_GET['a'] ?? '')));
if (!$a) { http_response_code(404); readfile(__DIR__ . '/404.html'); exit; }
$url = '/artigos/' . $a['slug']; $desc = resumo_de($a); $capa = !empty($a['capa']) ? SITE . $a['capa'] : SITE . '/og.jpg';
$ld = ['@context' => 'https://schema.org', '@type' => 'Article', 'headline' => $a['titulo'], 'description' => $desc, 'image' => [$capa], 'datePublished' => $a['data'], 'dateModified' => substr($a['atualizado'] ?? $a['data'], 0, 10),
    'author' => ['@type' => 'Person', 'name' => $a['autor'] ?? 'Stratesign'], 'publisher' => ['@type' => 'Organization', 'name' => 'Stratesign', 'logo' => ['@type' => 'ImageObject', 'url' => SITE . '/icon-512.png']], 'mainEntityOfPage' => SITE . $url, 'inLanguage' => 'pt-BR'];
cabeca($a['titulo'] . ' — Stratesign', $desc, $url, '<meta property="og:type" content="article">' . "\n" . '<meta property="og:image" content="' . e($capa) . '">' . "\n" . '<meta property="article:published_time" content="' . e($a['data']) . '">' . "\n" . '<script type="application/ld+json">' . json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . '</script>' . "\n");
?>
<main class="art">
  <p class="art-m"><a href="/artigos/">Artigos</a> · <?= e($a['autor'] ?? '') ?> · <time datetime="<?= e($a['data']) ?>"><?= e(data_br($a['data'])) ?></time></p>
  <h1><?= e($a['titulo']) ?></h1>
  <?php if (trim((string)($a['resumo'] ?? '')) !== ''): ?><p class="art-r"><?= e($a['resumo']) ?></p><?php endif; ?>
  <?php if (!empty($a['capa'])): ?><figure class="art-c"><img src="<?= e($a['capa']) ?>" alt=""></figure><?php endif; ?>
  <div class="art-t"><?= corpo_html($a['corpo'] ?? '') ?></div>
  <p class="art-f"><a href="/#conversa">Vamos conversar? Faça o diagnóstico em cinco perguntas →</a></p>
</main>
<?php rodape();
