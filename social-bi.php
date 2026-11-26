<?php
// =====================================================================
// Pontodot — SOCIAL BI: tudo o que a Meta entrega de UM cliente, num pacote só (só leitura).
//
//   POST {acao:"bi", client_id, periodo}   -> contas do cliente (Instagram e Página do Facebook) com:
//        totais do período + período anterior, série diária (alcance, seguidores), público (idade/gênero, cidade, país),
//        posts do período (com alcance/salvos/compartilhamentos dos melhores) e avisos do que a Meta não entregou.
//   POST {acao:"clientes"}                 -> clientes que têm rede social conectada
//
// Lê ao vivo da Meta com a chave da Página (social_account_tokens), em paralelo, e guarda 30 min em disco.
// Só a equipe (admin/team). Nada aqui publica ou altera nada.
// =====================================================================

require_once __DIR__ . '/sd-auth.php';
require_once __DIR__ . '/pd-elenco-lib.php';
require_once __DIR__ . '/meta-ads-lib.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') sd_fail('Método não permitido', 405);
$me = sd_current_user();
if (!in_array($me['role'] ?? '', ['admin', 'team'], true)) sd_fail('Só a equipe vê o BI das redes.', 403);
@set_time_limit(120);

$b = sd_body();
$acao = (string)($b['acao'] ?? 'bi');
$PER = ['last_7d' => 7, 'last_30d' => 30, 'this_month' => 0];
$periodo = isset($PER[$b['periodo'] ?? '']) ? $b['periodo'] : 'last_30d';
function sai($d) { echo json_encode(['ok' => true] + $d, JSON_UNESCAPED_UNICODE); exit; }

// ---- chamadas em paralelo à Graph API (mesma assinatura do pd_meta) ----
function sbi_lote(array $reqs, $tok, $timeout = 30) {
    $mh = curl_multi_init(); $hs = [];
    foreach ($reqs as $k => $r) {
        $p = $r[1]; $p['access_token'] = $tok; $p['appsecret_proof'] = hash_hmac('sha256', $tok, SD_META_APP_SECRET);
        $ch = curl_init((defined('PD_META_BASE') ? PD_META_BASE : 'https://graph.facebook.com/') . PD_META_VERSAO . $r[0] . '?' . http_build_query($p));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout]);
        curl_multi_add_handle($mh, $ch); $hs[$k] = $ch;
    }
    do { $st = curl_multi_exec($mh, $ativo); if ($ativo) curl_multi_select($mh, 1.0); } while ($ativo && $st === CURLM_OK);
    $out = [];
    foreach ($hs as $k => $ch) {
        $raw = curl_multi_getcontent($ch);
        $out[$k] = ['code' => curl_getinfo($ch, CURLINFO_HTTP_CODE), 'body' => json_decode((string)$raw, true)];
        curl_multi_remove_handle($mh, $ch); curl_close($ch);
    }
    curl_multi_close($mh);
    return $out;
}
function sbi_ok($r) { return ($r['code'] ?? 0) === 200 && is_array($r['body'] ?? null); }
// total_value de uma métrica (ou null)
function sbi_total($r) { return sbi_ok($r) && isset($r['body']['data'][0]['total_value']['value']) ? (int)$r['body']['data'][0]['total_value']['value'] : null; }
// série diária [[YYYY-MM-DD, valor], ...]
function sbi_serie($r) {
    if (!sbi_ok($r)) return null; $o = [];
    foreach ((array)($r['body']['data'][0]['values'] ?? []) as $v) { $t = strtotime($v['end_time'] ?? ''); if ($t) $o[] = [date('Y-m-d', $t - 3600 * 12), (int)($v['value'] ?? 0)]; }
    return $o;
}
// demografia: results -> [[dimensões...], valor]
function sbi_demo($r) {
    if (!sbi_ok($r)) return null; $o = [];
    foreach ((array)($r['body']['data'][0]['total_value']['breakdowns'][0]['results'] ?? []) as $x) $o[] = [(array)($x['dimension_values'] ?? []), (int)($x['value'] ?? 0)];
    return $o;
}
function sbi_janela($periodo) {
    $tz = new DateTimeZone('America/Sao_Paulo'); $fim = new DateTime('now', $tz);
    $ini = $periodo === 'this_month' ? new DateTime(date('Y-m-01 00:00:00'), $tz) : (new DateTime('today', $tz))->modify('-' . ($periodo === 'last_30d' ? 29 : 6) . ' day');
    $ini = $ini->getTimestamp(); $fim = $fim->getTimestamp(); $span = max(86400, $fim - $ini);
    return [$ini, $fim, $ini - $span, $ini - 1];
}

if ($acao === 'clientes') {
    $q = pd_supa('/rest/v1/social_accounts?select=client_id,platform,clients(name)&client_id=not.is.null');
    $por = [];
    foreach ((array)($q['body'] ?? []) as $c) { $id = $c['client_id']; $por[$id] = $por[$id] ?? ['id' => $id, 'nome' => $c['clients']['name'] ?? '', 'redes' => []]; $por[$id]['redes'][] = $c['platform']; }
    sai(['clientes' => array_values($por)]);
}

if ($acao !== 'bi') sd_fail('Ação desconhecida.', 400);
$cid = (string)($b['client_id'] ?? ''); if ($cid === '') sd_fail('Escolha um cliente.', 400);
$chave = 'sbi|' . $cid . '|' . $periodo;
if (empty($b['fresco']) && ($cache = pd_cache_ler($chave, 1800))) sai($cache);

$q = pd_supa('/rest/v1/social_accounts?client_id=eq.' . urlencode($cid) . '&select=id,platform,external_account_id,account_name,account_avatar_url,client_id,clients(name)');
$contas = (array)($q['body'] ?? []);
if (!$contas) sai(['contas' => [], 'periodo' => $periodo, 'erro' => 'Este cliente ainda não tem rede social conectada.']);
[$ini, $fim, $pini, $pfim] = sbi_janela($periodo);

$saida = [];
foreach ($contas as $c) {
    $tk = pd_supa('/rest/v1/social_account_tokens?social_account_id=eq.' . urlencode($c['id']) . '&select=access_token');
    $tok = $tk['body'][0]['access_token'] ?? null;
    $o = ['id' => $c['id'], 'plataforma' => $c['platform'], 'nome' => $c['account_name'], 'avatar' => $c['account_avatar_url'], 'cliente' => $c['clients']['name'] ?? null,
          'seguidores' => null, 'totais' => [], 'anterior' => [], 'serie' => ['alcance' => [], 'seguidores' => [], 'visualizacoes' => []],
          'publico' => ['idade_genero' => [], 'cidades' => [], 'paises' => []], 'posts' => [], 'avisos' => []];
    if (!$tok) { $o['erro'] = 'Sem chave desta conta. Reconecte em Redes sociais.'; $saida[] = $o; continue; }
    $ext = $c['external_account_id'];

    if ($c['platform'] === 'instagram') {
        $ins = fn($m, $i, $f, $extra = []) => ["/$ext/insights", array_merge(['metric' => $m, 'metric_type' => 'total_value', 'period' => 'day', 'since' => $i, 'until' => $f], $extra)];
        $reqs = ['perfil' => ["/$ext", ['fields' => 'followers_count,media_count,username']],
                 'serie_alc' => ["/$ext/insights", ['metric' => 'reach', 'period' => 'day', 'since' => $ini, 'until' => $fim]],
                 'serie_seg' => ["/$ext/insights", ['metric' => 'follower_count', 'period' => 'day', 'since' => max($ini, $fim - 29 * 86400), 'until' => $fim]],
                 'media' => ["/$ext/media", ['fields' => 'id,caption,media_type,media_product_type,thumbnail_url,media_url,permalink,timestamp,like_count,comments_count', 'limit' => 100]],
                 'd_idade' => ["/$ext/insights", ['metric' => 'follower_demographics', 'metric_type' => 'total_value', 'period' => 'lifetime', 'timeframe' => 'last_30_days', 'breakdown' => 'age,gender']],
                 'd_cidade' => ["/$ext/insights", ['metric' => 'follower_demographics', 'metric_type' => 'total_value', 'period' => 'lifetime', 'timeframe' => 'last_30_days', 'breakdown' => 'city']],
                 'd_pais' => ["/$ext/insights", ['metric' => 'follower_demographics', 'metric_type' => 'total_value', 'period' => 'lifetime', 'timeframe' => 'last_30_days', 'breakdown' => 'country']]];
        $MET = ['alcance' => 'reach', 'visualizacoes' => 'views', 'interacoes' => 'total_interactions', 'engajaram' => 'accounts_engaged', 'curtidas' => 'likes', 'comentarios' => 'comments',
                'compart' => 'shares', 'salvos' => 'saves', 'visitas_perfil' => 'profile_views', 'cliques_site' => 'website_clicks'];
        foreach ($MET as $k => $m) $reqs['t_' . $k] = $ins($m, $ini, $fim);
        foreach (['alcance', 'visualizacoes', 'interacoes'] as $k) $reqs['a_' . $k] = $ins($MET[$k], $pini, $pfim);
        $R = sbi_lote($reqs, $tok);

        if (sbi_ok($R['perfil'])) { $o['seguidores'] = (int)($R['perfil']['body']['followers_count'] ?? 0); $o['usuario'] = $R['perfil']['body']['username'] ?? null; } else $o['avisos'][] = 'Seguidores: ' . pd_meta_erro($R['perfil']);
        foreach ($MET as $k => $m) { $v = sbi_total($R['t_' . $k]); if ($v !== null) $o['totais'][$k] = $v; else $o['avisos'][] = ucfirst(str_replace('_', ' ', $k)) . ': ' . pd_meta_erro($R['t_' . $k]); }
        foreach (['alcance', 'visualizacoes', 'interacoes'] as $k) { $v = sbi_total($R['a_' . $k]); if ($v !== null) $o['anterior'][$k] = $v; }
        $s = sbi_serie($R['serie_alc']); if ($s !== null) $o['serie']['alcance'] = $s; else $o['avisos'][] = 'Alcance por dia: ' . pd_meta_erro($R['serie_alc']);
        $s = sbi_serie($R['serie_seg']); if ($s !== null) $o['serie']['seguidores'] = $s;   // só os últimos 30 dias existem na Meta
        foreach (['d_idade' => 'idade_genero', 'd_cidade' => 'cidades', 'd_pais' => 'paises'] as $rk => $dk) {
            $d = sbi_demo($R[$rk]); if ($d === null) { if ($rk === 'd_idade') $o['avisos'][] = 'Público: ' . pd_meta_erro($R[$rk]); continue; }
            foreach ($d as $x) $o['publico'][$dk][] = $dk === 'idade_genero' ? ['idade' => $x[0][0] ?? '', 'genero' => $x[0][1] ?? '', 'v' => $x[1]] : ['n' => $x[0][0] ?? '', 'v' => $x[1]];
        }
        // posts: período atual e anterior (só contagem do anterior)
        if (sbi_ok($R['media'])) foreach ((array)($R['media']['body']['data'] ?? []) as $m) {
            $t = strtotime($m['timestamp'] ?? ''); if (!$t) continue;
            if ($t < $ini) { if ($t >= $pini) $o['anterior']['posts'] = ($o['anterior']['posts'] ?? 0) + 1; continue; }
            $o['posts'][] = ['id' => $m['id'], 'texto' => mb_substr((string)($m['caption'] ?? ''), 0, 240), 'tipo' => (strtoupper($m['media_product_type'] ?? '') === 'REELS') ? 'reels' : strtolower($m['media_type'] ?? 'image'),
                'imagem' => $m['thumbnail_url'] ?? $m['media_url'] ?? null, 'link' => $m['permalink'] ?? null, 'quando' => $m['timestamp'],
                'curtidas' => (int)($m['like_count'] ?? 0), 'comentarios' => (int)($m['comments_count'] ?? 0)];
        } else $o['avisos'][] = 'Posts: ' . pd_meta_erro($R['media']);
        // alcance, salvos e compartilhamentos dos 12 melhores
        usort($o['posts'], fn($x, $y) => ($y['curtidas'] + $y['comentarios'] * 3) <=> ($x['curtidas'] + $x['comentarios'] * 3));
        $R2 = []; foreach (array_slice($o['posts'], 0, 12) as $i => $p) {
            $R2["b$i"] = ["/{$p['id']}/insights", ['metric' => 'reach,saved,shares']];
            $R2["v$i"] = ["/{$p['id']}/insights", ['metric' => 'views']];
        }
        if ($R2) { $R2 = sbi_lote($R2, $tok);
            foreach (array_slice(array_keys($o['posts']), 0, 12) as $i) {
                foreach (['b' => ['reach' => 'alcance', 'saved' => 'salvos', 'shares' => 'compart'], 'v' => ['views' => 'views']] as $pre => $mapa)
                    if (sbi_ok($R2["$pre$i"])) foreach ((array)($R2["$pre$i"]['body']['data'] ?? []) as $d) { $k = $mapa[$d['name'] ?? ''] ?? null; if ($k) $o['posts'][$i][$k] = (int)($d['values'][0]['value'] ?? 0); }
            } }
    } else {   // Página do Facebook — melhor esforço: a Meta muda as métricas de página com frequência
        $reqs = ['perfil' => ["/$ext", ['fields' => 'followers_count,fan_count']],
                 'posts' => ["/$ext/posts", ['fields' => 'id,message,created_time,permalink_url,full_picture,reactions.limit(0).summary(true),comments.limit(0).summary(true),shares', 'since' => $pini, 'limit' => 100]]];
        foreach (['alcance' => 'page_total_media_view_unique', 'visualizacoes' => 'page_media_view', 'interacoes' => 'page_post_engagements', 'seguidores' => 'page_daily_follows_unique'] as $k => $m)
            $reqs['s_' . $k] = ["/$ext/insights", ['metric' => $m, 'period' => 'day', 'since' => $ini, 'until' => $fim]];
        $R = sbi_lote($reqs, $tok);
        if (sbi_ok($R['perfil'])) $o['seguidores'] = (int)($R['perfil']['body']['followers_count'] ?? $R['perfil']['body']['fan_count'] ?? 0); else $o['avisos'][] = 'Seguidores: ' . pd_meta_erro($R['perfil']);
        foreach (['alcance', 'visualizacoes', 'interacoes', 'seguidores'] as $k) { $s = sbi_serie($R['s_' . $k]); if ($s !== null) { $o['serie'][$k] = $s; if ($k !== 'seguidores') $o['totais'][$k] = array_sum(array_column($s, 1)); } else $o['avisos'][] = ucfirst($k) . ': ' . pd_meta_erro($R['s_' . $k]); }
        if (sbi_ok($R['posts'])) foreach ((array)($R['posts']['body']['data'] ?? []) as $m) {
            $t = strtotime($m['created_time'] ?? ''); if (!$t) continue;
            if ($t < $ini) { $o['anterior']['posts'] = ($o['anterior']['posts'] ?? 0) + 1; continue; }
            $o['posts'][] = ['id' => $m['id'], 'texto' => mb_substr((string)($m['message'] ?? ''), 0, 240), 'tipo' => 'post', 'imagem' => $m['full_picture'] ?? null, 'link' => $m['permalink_url'] ?? null, 'quando' => $m['created_time'],
                'curtidas' => (int)($m['reactions']['summary']['total_count'] ?? 0), 'comentarios' => (int)($m['comments']['summary']['total_count'] ?? 0), 'compart' => (int)($m['shares']['count'] ?? 0)];
        } else $o['avisos'][] = 'Posts: ' . pd_meta_erro($R['posts']);
        $o['totais']['curtidas'] = array_sum(array_column($o['posts'], 'curtidas')); $o['totais']['comentarios'] = array_sum(array_column($o['posts'], 'comentarios')); $o['totais']['compart'] = array_sum(array_column($o['posts'], 'compart'));
        if (!isset($o['totais']['interacoes'])) $o['totais']['interacoes'] = $o['totais']['curtidas'] + $o['totais']['comentarios'] + $o['totais']['compart'];
    }
    $saida[] = $o;
}
$pac = ['contas' => $saida, 'periodo' => $periodo, 'ini' => date('Y-m-d', $ini), 'fim' => date('Y-m-d', $fim), 'gerado_em' => date('c')];
pd_cache_gravar($chave, $pac);
sai($pac);
