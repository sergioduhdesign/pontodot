<?php
// lista pública dos artigos publicados (sem o corpo), lida pela home
define('STRAT', 1); require __DIR__ . '/_lib.php';
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: public, max-age=120');
$o = []; foreach (artigos(true) as $a) $o[] = ['slug' => $a['slug'], 'titulo' => $a['titulo'], 'autor' => $a['autor'] ?? '', 'data' => $a['data'], 'resumo' => resumo_de($a), 'publicado' => true];
echo json_encode(['artigos' => $o], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
