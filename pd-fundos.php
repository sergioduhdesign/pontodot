<?php
// =====================================================================
// pontodot — FUNDOS DO APP (celular)
//   GET /pd-fundos.php?ia=<id>   → álbuns disponíveis:
//     · "ia"  : as imagens da IA da pessoa, em  pd/personagens/<id>/mobile/
//     · outros: cada subpasta de  pd/fundos/<album>/
//   Só lista arquivos jpg/jpeg/png/webp. Devolve endereços do próprio site.
// =====================================================================
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');
$raiz = __DIR__;
function pdf_lista($dir, $url) {
    $out = [];
    if (!is_dir($dir)) return $out;
    foreach (scandir($dir) as $f) {
        if ($f[0] === '.' || !preg_match('/\.(jpe?g|png|webp)$/i', $f)) continue;
        $out[] = $url . '/' . rawurlencode($f);
    }
    natsort($out);
    return array_values($out);
}
$albuns = [];
$id = preg_replace('/[^a-z0-9_\-]/i', '', (string)($_GET['ia'] ?? ''));
if ($id !== '') {
    $imgs = pdf_lista("$raiz/pd/personagens/$id/mobile", "/pd/personagens/$id/mobile");
    if ($imgs) $albuns[] = ['id' => 'ia', 'nome' => 'Minha IA', 'imagens' => $imgs];
}
$base = "$raiz/pd/fundos";
if (is_dir($base)) {
    foreach (scandir($base) as $d) {
        if ($d[0] === '.' || !is_dir("$base/$d")) continue;
        $slug = preg_replace('/[^a-z0-9_\-]/i', '', $d);
        $imgs = pdf_lista("$base/$d", '/pd/fundos/' . rawurlencode($d));
        if ($imgs) $albuns[] = ['id' => $slug, 'nome' => ucfirst(str_replace(['-', '_'], ' ', $d)), 'imagens' => $imgs];
    }
}
echo json_encode(['ok' => true, 'albuns' => $albuns], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
