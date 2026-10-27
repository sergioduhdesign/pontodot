<?php
// Banco de mídia da casa: busca em Pexels / Unsplash / Pixabay (APIs oficiais) e Biblioteca com controle de licença.
// Chaves ficam no sd-config.php do servidor: SD_PEXELS_KEY · SD_UNSPLASH_KEY · SD_PIXABAY_KEY (fonte sem chave = desligada).
// Ações: fontes · buscar · salvar · listar · uso
require_once __DIR__ . '/sd-auth.php';
$u = sd_current_user(); if (!in_array($u['role'], ['admin', 'team'], true)) sd_fail('Só a equipe.', 403);
$b = sd_body(); $acao = (string)($b['acao'] ?? 'fontes');
@set_time_limit(40);

function bnGet($url, $hdr = []) {
    $ch = curl_init($url); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_HTTPHEADER => $hdr]);
    $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$code, json_decode((string)$raw, true)];
}
function bnFontes() {
    return ['pexels' => defined('SD_PEXELS_KEY') && SD_PEXELS_KEY, 'unsplash' => defined('SD_UNSPLASH_KEY') && SD_UNSPLASH_KEY, 'pixabay' => defined('SD_PIXABAY_KEY') && SD_PIXABAY_KEY];
}
$LIC = ['pexels' => ['Licença Pexels: uso comercial livre, sem atribuição obrigatória.', ''], 'unsplash' => ['Licença Unsplash: uso comercial livre, atribuição não obrigatória.', ''], 'pixabay' => ['Licença Pixabay: uso comercial; não revender isolado; baixar a mídia (sem hotlink).', 'Pixabay']];

if ($acao === 'fontes') { echo json_encode(['fontes' => bnFontes()]); exit; }

if ($acao === 'buscar') {
    $q = mb_substr(trim((string)($b['q'] ?? '')), 0, 100); if ($q === '') sd_fail('Escreva o que procurar.');
    $tipo = ($b['tipo'] ?? 'foto') === 'video' ? 'video' : 'foto'; $on = bnFontes(); $out = []; $erros = [];
    $qs = rawurlencode($q);
    if ($on['pexels']) { [$c, $j] = bnGet($tipo === 'video' ? "https://api.pexels.com/videos/search?query=$qs&per_page=12" : "https://api.pexels.com/v1/search?query=$qs&per_page=12", ['Authorization: ' . SD_PEXELS_KEY]);
        if ($c === 200) foreach ((array)($tipo === 'video' ? ($j['videos'] ?? []) : ($j['photos'] ?? [])) as $x) {
            if ($tipo === 'video') { $f = array_values(array_filter((array)$x['video_files'], fn($v) => ($v['quality'] ?? '') === 'hd')); $f = $f[0] ?? ($x['video_files'][0] ?? []);
                $out[] = ['fonte' => 'pexels', 'ext_id' => (string)$x['id'], 'tipo' => 'video', 'titulo' => 'Vídeo Pexels ' . $x['id'], 'autor' => $x['user']['name'] ?? '', 'pagina_url' => $x['url'] ?? '', 'thumb_url' => $x['image'] ?? '', 'media_url' => $f['link'] ?? '']; }
            else $out[] = ['fonte' => 'pexels', 'ext_id' => (string)$x['id'], 'tipo' => 'foto', 'titulo' => $x['alt'] ?? '', 'autor' => $x['photographer'] ?? '', 'pagina_url' => $x['url'] ?? '', 'thumb_url' => $x['src']['medium'] ?? '', 'media_url' => $x['src']['large2x'] ?? ($x['src']['original'] ?? '')];
        } else $erros[] = 'Pexels respondeu ' . $c; }
    if ($on['unsplash'] && $tipo === 'foto') { [$c, $j] = bnGet("https://api.unsplash.com/search/photos?query=$qs&per_page=12", ['Authorization: Client-ID ' . SD_UNSPLASH_KEY]);
        if ($c === 200) foreach ((array)($j['results'] ?? []) as $x) $out[] = ['fonte' => 'unsplash', 'ext_id' => (string)$x['id'], 'tipo' => 'foto', 'titulo' => $x['alt_description'] ?? '', 'autor' => $x['user']['name'] ?? '', 'pagina_url' => $x['links']['html'] ?? '', 'thumb_url' => $x['urls']['small'] ?? '', 'media_url' => $x['urls']['regular'] ?? '', 'dl' => $x['links']['download_location'] ?? ''];
        else $erros[] = 'Unsplash respondeu ' . $c; }
    if ($on['pixabay']) { [$c, $j] = bnGet($tipo === 'video' ? "https://pixabay.com/api/videos/?key=" . SD_PIXABAY_KEY . "&q=$qs&per_page=12" : "https://pixabay.com/api/?key=" . SD_PIXABAY_KEY . "&q=$qs&image_type=photo&per_page=12");
        if ($c === 200) foreach ((array)($j['hits'] ?? []) as $x) {
            if ($tipo === 'video') $out[] = ['fonte' => 'pixabay', 'ext_id' => (string)$x['id'], 'tipo' => 'video', 'titulo' => $x['tags'] ?? '', 'autor' => $x['user'] ?? '', 'pagina_url' => $x['pageURL'] ?? '', 'thumb_url' => $x['videos']['tiny']['thumbnail'] ?? '', 'media_url' => $x['videos']['medium']['url'] ?? ''];
            else $out[] = ['fonte' => 'pixabay', 'ext_id' => (string)$x['id'], 'tipo' => 'foto', 'titulo' => $x['tags'] ?? '', 'autor' => $x['user'] ?? '', 'pagina_url' => $x['pageURL'] ?? '', 'thumb_url' => $x['webformatURL'] ?? '', 'media_url' => $x['largeImageURL'] ?? ''];
        } else $erros[] = 'Pixabay respondeu ' . $c; }
    echo json_encode(['itens' => $out, 'erros' => $erros, 'fontes' => $on, 'licencas' => array_map(fn($l) => $l[0], $LIC)], JSON_UNESCAPED_UNICODE); exit;
}
if ($acao === 'salvar') {
    $i = (array)($b['item'] ?? []); $f = (string)($i['fonte'] ?? ''); if (!isset($LIC[$f]) && !in_array($f, ['envato', 'upload'], true)) sd_fail('Fonte inválida.');
    foreach (['thumb_url', 'media_url', 'pagina_url'] as $k) if (!empty($i[$k]) && !preg_match('#^https://#', (string)$i[$k])) sd_fail('Endereço inválido.');
    if ($f === 'unsplash' && !empty($i['dl']) && defined('SD_UNSPLASH_KEY') && preg_match('#^https://api\.unsplash\.com/#', (string)$i['dl'])) bnGet($i['dl'], ['Authorization: Client-ID ' . SD_UNSPLASH_KEY]);   // exigência da API: avisar o download
    $d = ['fonte' => $f, 'ext_id' => mb_substr((string)($i['ext_id'] ?? ''), 0, 80), 'tipo' => in_array($i['tipo'] ?? '', ['foto', 'video', 'audio'], true) ? $i['tipo'] : 'foto', 'titulo' => mb_substr((string)($i['titulo'] ?? ''), 0, 200), 'autor' => mb_substr((string)($i['autor'] ?? ''), 0, 120),
        'pagina_url' => $i['pagina_url'] ?? null, 'thumb_url' => $i['thumb_url'] ?? null, 'media_url' => $i['media_url'] ?? null, 'licenca' => $LIC[$f][0] ?? mb_substr((string)($i['licenca'] ?? ''), 0, 300), 'atribuicao' => $LIC[$f][1] ?? '',
        'cliente' => mb_substr((string)($i['cliente'] ?? ''), 0, 120) ?: null, 'salvo_por' => $u['id']];
    if ($d['ext_id'] === '') sd_fail('Item sem identificador.');
    $r = sd_supabase('/rest/v1/pd_biblioteca?on_conflict=fonte,ext_id', 'POST', $d, ['Prefer: resolution=merge-duplicates,return=representation']);
    if (($r['code'] ?? 500) >= 300) sd_fail('Rode o SQL v114 no Supabase.', 500);
    require_once __DIR__ . '/pd-lib.php'; pdAcervo('midia_banco', 'banco', ['categoria' => 'banco_fotos', 'nome' => (string)($d['titulo'] ?: $d['ext_id']), 'url' => (string)($d['pagina_url'] ?? ''), 'nota' => 'fonte ' . $d['fonte'] . ($d['cliente'] ? ' · cliente ' . $d['cliente'] : ''), 'por' => $u['id']]);
    echo json_encode(['ok' => true, 'id' => $r['body'][0]['id'] ?? null]); exit;
}
if ($acao === 'listar') {
    $q = trim((string)($b['q'] ?? '')); $f = '/rest/v1/pd_biblioteca?select=*,pd_biblioteca_usos(cliente,projeto,peca,nota,em)&order=salvo_em.desc&limit=60';
    if ($q !== '') $f .= '&or=(titulo.ilike.' . rawurlencode('*' . str_replace(['(', ')', ','], ' ', $q) . '*') . ',autor.ilike.' . rawurlencode('*' . str_replace(['(', ')', ','], ' ', $q) . '*') . ',cliente.ilike.' . rawurlencode('*' . str_replace(['(', ')', ','], ' ', $q) . '*') . ')';
    $r = sd_supabase($f); if (($r['code'] ?? 500) >= 300) sd_fail('Rode o SQL v114 no Supabase.', 500);
    echo json_encode(['itens' => $r['body']], JSON_UNESCAPED_UNICODE); exit;
}
if ($acao === 'uso') {   // registra onde o item foi usado (cliente · projeto · peça)
    $id = (string)($b['item_id'] ?? ''); $cli = mb_substr(trim((string)($b['cliente'] ?? '')), 0, 120); if (!preg_match('/^[0-9a-f-]{36}$/i', $id) || $cli === '') sd_fail('Informe o item e o cliente.');
    $r = sd_supabase('/rest/v1/pd_biblioteca_usos', 'POST', ['item_id' => $id, 'cliente' => $cli, 'projeto' => mb_substr((string)($b['projeto'] ?? ''), 0, 160), 'peca' => mb_substr((string)($b['peca'] ?? ''), 0, 160), 'nota' => mb_substr((string)($b['nota'] ?? ''), 0, 300), 'por' => $u['id']]);
    if (($r['code'] ?? 500) >= 300) sd_fail('Não consegui registrar o uso.', 500);
    echo json_encode(['ok' => true]); exit;
}
sd_fail('Ação desconhecida.');
