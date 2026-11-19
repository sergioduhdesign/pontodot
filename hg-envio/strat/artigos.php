<?php
define('STRAT', 1); require __DIR__ . '/_lib.php';
$A = artigos(true);
cabeca('Artigos — Stratesign', 'Textos de Marcus Araujo e Sérgio Duh sobre mercado imobiliário, marca e lançamento.', '/artigos/', '<meta property="og:type" content="website">' . "\n" . '<meta property="og:image" content="' . SITE . '/og.jpg">' . "\n" . (count($A) ? '' : '<meta name="robots" content="noindex, follow">' . "\n"));
?>
<main class="art">
  <h1>Artigos</h1>
  <?php if (!$A): ?><p class="art-r">Os primeiros textos estão a caminho.</p><?php else: ?>
  <div class="art-l"><?php foreach ($A as $a): ?>
    <a href="/artigos/<?= e($a['slug']) ?>"><span><?= e($a['autor'] ?? '') ?> · <?= e(data_br($a['data'])) ?></span><b><?= e($a['titulo']) ?></b><i><?= e(resumo_de($a)) ?></i></a>
  <?php endforeach; ?></div><?php endif; ?>
</main>
<?php rodape();
