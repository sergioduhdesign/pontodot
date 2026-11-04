<?php
// =====================================================================
// Pontodot — ANÚNCIOS: números das campanhas, por cliente
//
//   POST {acao:"resumo",    periodo}            -> cada conta de anúncio, com gasto/leads/CPL
//   POST {acao:"campanhas", conta, periodo}     -> as campanhas de UMA conta
//   POST {acao:"anuncios",  conta, campanha, periodo} -> os anúncios (criativos + números) de UMA campanha
//   POST {acao:"vincular",  conta, client_id}   -> (admin) de qual cliente é a conta
//
// Lê da Meta e guarda o DESTINO dos anúncios novos (a criação do anúncio em si é do publicador, na fila do aprovador). Só a equipe (admin/team).
// Guarda 10 minutos em disco: a Meta limita consultas no nível de acesso
// inicial, e a plataforma não pode queimar esse limite à toa.
// =====================================================================

require_once __DIR__ . '/sd-auth.php';
require_once __DIR__ . '/meta-ads-lib.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') sd_fail('Método não permitido', 405);
$me = sd_current_user();
if (!in_array($me['role'] ?? '', ['admin', 'team'], true)) sd_fail('Só a equipe vê os números de anúncio.', 403);

$b = sd_body();
$acao = (string)($b['acao'] ?? 'resumo');
$PERIODOS = ['today', 'yesterday', 'last_7d', 'last_30d', 'this_month', 'last_month'];
$periodo = in_array($b['periodo'] ?? '', $PERIODOS, true) ? $b['periodo'] : 'last_7d';
$CAMPOS = 'spend,reach,impressions,clicks,actions';

function sai($d) { echo json_encode(['ok' => true] + $d, JSON_UNESCAPED_UNICODE); exit; }
function idConta($v) { $v = (string)$v; return preg_match('/^act_\d{3,25}$/', $v) ? $v : null; }

// -------------------------------------------------------------- destino do anúncio (fila única)
//   conjuntos {conta, campanha}      -> conjuntos de anúncio da campanha
//   formularios {client_id}          -> formulários de lead da Página do cliente
//   destino_ler {client_id}          -> onde as peças aprovadas de anúncio entram
//   destino_gravar {client_id, product_id?, conta, campaign_id, adset_id, link?, cta?, lead_form_id?}
if (in_array($acao, ['conjuntos', 'formularios', 'destino_ler', 'destino_gravar'], true)) {
    $uuid = fn($v) => preg_match('/^[0-9a-f-]{36}$/i', (string)$v) ? (string)$v : null;
    if ($acao === 'destino_ler') {
        $cli = $uuid($b['client_id'] ?? ''); if (!$cli) sd_fail('Cliente inválido.', 400);
        $q = pd_supa('/rest/v1/pd_anuncio_destino?client_id=eq.' . $cli . '&select=*,products(name)');
        if ($q['code'] >= 300) sd_fail('Falta rodar o migration v157 no Supabase.', 500);
        sai(['destinos' => array_values(array_filter((array)$q['body'], 'is_array'))]);
    }
    if ($acao === 'destino_gravar') {
        $cli = $uuid($b['client_id'] ?? ''); $prod = $uuid($b['product_id'] ?? '');
        $conta = idConta($b['conta'] ?? ''); $adset = preg_replace('/\D/', '', (string)($b['adset_id'] ?? ''));
        if (!$cli || !$conta || !$adset) sd_fail('Faltou cliente, conta ou conjunto de anúncios.', 400);
        $link = trim((string)($b['link'] ?? '')); if ($link !== '' && !preg_match('#^https?://#i', $link)) sd_fail('O link precisa começar com http:// ou https://', 400);
        $cta = preg_match('/^[A-Z_]{3,30}$/', (string)($b['cta'] ?? '')) ? $b['cta'] : 'LEARN_MORE';
        $form = preg_replace('/\D/', '', (string)($b['lead_form_id'] ?? ''));
        $del = '/rest/v1/pd_anuncio_destino?client_id=eq.' . $cli . ($prod ? '&product_id=eq.' . $prod : '&product_id=is.null');
        pd_supa($del, 'DELETE');
        $r = pd_supa('/rest/v1/pd_anuncio_destino', 'POST', ['client_id' => $cli, 'product_id' => $prod, 'conta' => $conta,
            'campaign_id' => preg_replace('/\D/', '', (string)($b['campaign_id'] ?? '')) ?: null, 'adset_id' => $adset,
            'link' => $link ?: null, 'cta' => $cta, 'lead_form_id' => $form ?: null, 'atualizado_em' => gmdate('c')]);
        if ($r['code'] >= 300) sd_fail('Não consegui salvar o destino (rodou o migration v157?).', 500);
        sai(['salvo' => true]);
    }
    if ($acao === 'conjuntos') {
        $id = idConta($b['conta'] ?? ''); $camp = preg_replace('/\D/', '', (string)($b['campanha'] ?? ''));
        if (!$id || !$camp) sd_fail('Conta ou campanha inválida.', 400);
        $qc = pd_supa('/rest/v1/meta_ad_accounts?id=eq.' . urlencode($id) . '&select=conectada_por');
        $tok = pd_token_para($qc['body'][0]['conectada_por'] ?? null); if (!$tok) sd_fail('Ninguém com acesso válido à Meta. Reconecte a Meta.', 409);
        $r = pd_meta('GET', "/$camp/adsets", ['fields' => 'id,name,effective_status', 'limit' => 100], $tok);
        if ($r['code'] !== 200) sd_fail(pd_meta_erro($r), 502);
        sai(['conjuntos' => array_map(fn($x) => ['id' => $x['id'], 'nome' => $x['name'] ?? $x['id'], 'status' => $x['effective_status'] ?? ''], (array)($r['body']['data'] ?? []))]);
    }
    // formularios: usa a chave da PÁGINA do cliente
    $cli = $uuid($b['client_id'] ?? ''); if (!$cli) sd_fail('Cliente inválido.', 400);
    $pg = pd_supa('/rest/v1/social_accounts?platform=eq.facebook&client_id=eq.' . $cli . '&select=id,external_account_id,account_name&limit=1');
    $pag = $pg['body'][0] ?? null; if (!$pag) sd_fail('Este cliente não tem Página do Facebook vinculada (Redes sociais).', 404);
    $tk = pd_supa('/rest/v1/social_account_tokens?social_account_id=eq.' . urlencode($pag['id']) . '&select=access_token');
    $tp = $tk['body'][0]['access_token'] ?? null; if (!$tp) sd_fail('Sem chave da Página. Reconecte a Meta.', 409);
    $r = pd_meta('GET', '/' . $pag['external_account_id'] . '/leadgen_forms', ['fields' => 'id,name,status', 'limit' => 100], $tp);
    if ($r['code'] !== 200) sd_fail(pd_meta_erro($r), 502);
    sai(['pagina' => $pag['account_name'], 'formularios' => array_map(fn($x) => ['id' => $x['id'], 'nome' => $x['name'] ?? $x['id'], 'status' => $x['status'] ?? ''], array_values(array_filter((array)($r['body']['data'] ?? []), fn($x) => ($x['status'] ?? 'ACTIVE') !== 'ARCHIVED')))]);
}

// -------------------------------------------------------------- vincular
if ($acao === 'vincular') {
    if (($me['role'] ?? '') !== 'admin') sd_fail('Só admin vincula conta de anúncio a cliente.', 403);
    $conta = idConta($b['conta'] ?? ''); if (!$conta) sd_fail('Conta inválida.', 400);
    $cli = $b['client_id'] ?? null;
    if ($cli !== null && !preg_match('/^[0-9a-f-]{36}$/i', (string)$cli)) sd_fail('Cliente inválido.', 400);
    $r = pd_supa('/rest/v1/meta_ad_accounts?id=eq.' . urlencode($conta), 'PATCH', ['client_id' => $cli]);
    if ($r['code'] >= 300) sd_fail('Não consegui salvar o vínculo.', 500);
    sai(['conta' => $conta, 'client_id' => $cli]);
}

// as contas que a plataforma conhece (o login da Meta é que as traz)
$q = pd_supa('/rest/v1/meta_ad_accounts?select=id,nome,moeda,empresa,status_meta,client_id,conectada_por,clients(name)&order=nome');
if ($q['code'] >= 300) sd_fail('As tabelas de anúncio ainda não existem. Rode o migration_meta_ads.sql no Supabase.', 500);
$contas = array_values(array_filter((array)$q['body'], 'is_array'));

// -------------------------------------------------------------- anúncios
// criativos (imagem/vídeo/texto) + números de cada anúncio de uma campanha
if ($acao === 'anuncios') {
    $id = idConta($b['conta'] ?? ''); if (!$id) sd_fail('Conta inválida.', 400);
    $camp = (string)($b['campanha'] ?? ''); if (!preg_match('/^\d{5,25}$/', $camp)) sd_fail('Campanha inválida.', 400);
    $c = null; foreach ($contas as $x) if ($x['id'] === $id) $c = $x;
    if (!$c) sd_fail('Essa conta não está conectada na plataforma.', 404);
    $chave = "ads|$camp|$periodo";
    if ($cache = pd_cache_ler($chave, 600)) sai($cache + ['cache' => true]);
    $tok = pd_token_para($c['conectada_por'] ?? null);
    if (!$tok) sd_fail('Ninguém com acesso válido à Meta. Reconecte a Meta.', 409);

    $ads = pd_meta('GET', "/$camp/ads", ['fields' => 'id,name,effective_status,preview_shareable_link,creative{thumbnail_url,image_url,title,body,object_type,video_id}',
        'thumbnail_width' => 480, 'limit' => 100], $tok);
    if ($ads['code'] !== 200) sd_fail(pd_meta_erro($ads), 502);
    $ins = pd_meta('GET', "/$camp/insights", ['level' => 'ad', 'date_preset' => $periodo,
        'fields' => 'ad_id,' . $CAMPOS . ',frequency,ctr', 'limit' => 100], $tok);
    $num = [];
    foreach ((array)($ins['body']['data'] ?? []) as $l) if (is_array($l)) $num[$l['ad_id'] ?? ''] = $l;

    $lista = [];
    foreach ((array)($ads['body']['data'] ?? []) as $a) {
        if (!is_array($a)) continue;
        $cr = is_array($a['creative'] ?? null) ? $a['creative'] : [];
        $l = $num[$a['id']] ?? [];
        $lista[] = ['id' => $a['id'], 'nome' => $a['name'] ?? '', 'status' => $a['effective_status'] ?? null,
            'imagem' => $cr['image_url'] ?? ($cr['thumbnail_url'] ?? null), 'miniatura' => $cr['thumbnail_url'] ?? null,
            'titulo' => $cr['title'] ?? null, 'texto' => $cr['body'] ?? null,
            'tipo' => !empty($cr['video_id']) ? 'video' : 'imagem', 'link' => $a['preview_shareable_link'] ?? null,
            'ctr' => isset($l['ctr']) ? round((float)$l['ctr'], 2) : null,
            'frequencia' => isset($l['frequency']) ? round((float)$l['frequency'], 2) : null,
        ] + pd_numeros($l, $c['moeda'] ?? 'BRL');
    }
    usort($lista, function ($a, $b) { return $b['gasto'] <=> $a['gasto']; });
    $out = ['conta' => $id, 'campanha' => $camp, 'periodo' => $periodo, 'anuncios' => $lista, 'em' => gmdate('c')];
    pd_cache_gravar($chave, $out);
    sai($out);
}

// ------------------------------------------------------------- campanhas
if ($acao === 'campanhas') {
    $id = idConta($b['conta'] ?? ''); if (!$id) sd_fail('Conta inválida.', 400);
    $c = null; foreach ($contas as $x) if ($x['id'] === $id) $c = $x;
    if (!$c) sd_fail('Essa conta não está conectada na plataforma.', 404);
    $chave = "camp|$id|$periodo";
    if ($cache = pd_cache_ler($chave, 600)) sai($cache + ['cache' => true]);
    $tok = pd_token_para($c['conectada_por'] ?? null);
    if (!$tok) sd_fail('Ninguém com acesso válido à Meta. Reconecte a Meta.', 409);

    $ins = pd_meta('GET', "/$id/insights", ['level' => 'campaign', 'date_preset' => $periodo,
        'fields' => 'campaign_id,campaign_name,' . $CAMPOS, 'limit' => 100], $tok);
    if ($ins['code'] !== 200) sd_fail(pd_meta_erro($ins), 502);
    $cps = pd_meta('GET', "/$id/campaigns", ['fields' => 'id,name,effective_status,objective,daily_budget,lifetime_budget',
        'limit' => 200], $tok);
    $info = [];
    foreach ((array)($cps['body']['data'] ?? []) as $x) if (is_array($x)) $info[$x['id']] = $x;

    $lista = [];
    foreach ((array)($ins['body']['data'] ?? []) as $l) {
        if (!is_array($l)) continue;
        $i = $info[$l['campaign_id'] ?? ''] ?? [];
        $lista[] = ['id' => $l['campaign_id'] ?? '', 'nome' => $l['campaign_name'] ?? ($i['name'] ?? ''),
            'status' => $i['effective_status'] ?? null, 'objetivo' => $i['objective'] ?? null,
            'orcamento_dia' => isset($i['daily_budget']) ? round($i['daily_budget'] / 100, 2) : null,
        ] + pd_numeros($l, $c['moeda'] ?? 'BRL');
    }
    usort($lista, function ($a, $b) { return $b['gasto'] <=> $a['gasto']; });
    $out = ['conta' => $id, 'periodo' => $periodo, 'campanhas' => $lista, 'em' => gmdate('c')];
    pd_cache_gravar($chave, $out);
    sai($out);
}

// ---------------------------------------------------------------- resumo
$chave = "resumo|$periodo|" . md5(json_encode(array_column($contas, 'id')));
if ($cache = pd_cache_ler($chave, 600)) sai($cache + ['cache' => true]);
$linhas = []; $avisos = [];
foreach (array_slice($contas, 0, 60) as $c) {
    $base = ['conta' => $c['id'], 'nome' => $c['nome'], 'empresa' => $c['empresa'],
             'client_id' => $c['client_id'], 'cliente' => $c['clients']['name'] ?? null,
             'ativa' => (int)($c['status_meta'] ?? 1) === 1];
    $tok = pd_token_para($c['conectada_por'] ?? null);
    if (!$tok) { $linhas[] = $base + ['erro' => 'Sem acesso válido. Reconecte a Meta.']; continue; }
    $r = pd_meta('GET', '/' . $c['id'] . '/insights', ['date_preset' => $periodo, 'fields' => $CAMPOS], $tok, 20);
    if ($r['code'] !== 200) { $linhas[] = $base + ['erro' => pd_meta_erro($r)]; continue; }
    $l = $r['body']['data'][0] ?? [];
    $linhas[] = $base + pd_numeros(is_array($l) ? $l : [], $c['moeda'] ?? 'BRL');
}
// quem gastou mais aparece primeiro; conta parada vai pro fim
usort($linhas, function ($a, $b) { return ($b['gasto'] ?? -1) <=> ($a['gasto'] ?? -1); });
$out = ['periodo' => $periodo, 'contas' => $linhas, 'em' => gmdate('c')];
pd_cache_gravar($chave, $out);
sai($out);
