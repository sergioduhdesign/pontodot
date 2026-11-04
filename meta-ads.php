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
require_once __DIR__ . '/pd-elenco-lib.php';

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


// -------------------------------------------------------------- perfil do público + IA de Campanhas
function pd_periodo_datas($periodo) {
    $tz = new DateTimeZone('America/Sao_Paulo'); $h = new DateTime('today', $tz);
    switch ($periodo) {
        case 'today': return [$h->format('Y-m-d'), $h->format('Y-m-d')];
        case 'yesterday': $y = (clone $h)->modify('-1 day')->format('Y-m-d'); return [$y, $y];
        case 'last_30d': return [(clone $h)->modify('-30 day')->format('Y-m-d'), $h->format('Y-m-d')];
        case 'this_month': return [$h->format('Y-m-01'), $h->format('Y-m-d')];
        case 'last_month': $a = (clone $h)->modify('first day of last month'); return [$a->format('Y-m-01'), $a->format('Y-m-t')];
        default: return [(clone $h)->modify('-7 day')->format('Y-m-d'), $h->format('Y-m-d')];
    }
}
function pd_perfil_dados($objId, $tok, $periodo, $moeda) {
    $cortes = ['idade_genero' => 'age,gender', 'regiao' => 'region', 'hora' => 'hourly_stats_aggregated_by_advertiser_time_zone',
               'posicionamento' => 'publisher_platform,platform_position', 'aparelho' => 'device_platform'];
    $out = [];
    foreach ($cortes as $nome => $bk) {
        $r = pd_meta('GET', "/$objId/insights", ['date_preset' => $periodo, 'breakdowns' => $bk,
            'fields' => 'spend,reach,impressions,clicks,actions', 'limit' => 200], $tok, 25);
        if ($r['code'] !== 200) { $out[$nome] = ['erro' => pd_meta_erro($r)]; continue; }
        $linhas = [];
        foreach ((array)($r['body']['data'] ?? []) as $l) {
            if (!is_array($l)) continue;
            $rot = [];
            foreach (explode(',', $bk) as $k) $rot[] = (string)($l[$k] ?? '');
            $n = pd_numeros($l, $moeda);
            $linhas[] = ['rotulo' => implode(' · ', $rot)] + $n;
        }
        usort($linhas, fn($a, $b) => $b['gasto'] <=> $a['gasto']);
        $out[$nome] = array_slice($linhas, 0, $nome === 'regiao' ? 15 : 40);
    }
    return $out;
}
function pd_leads_horario($clientId, $periodo, $campNome = null) {
    if (!$clientId) return null;
    [$de, $ate] = pd_periodo_datas($periodo);
    $q = '/rest/v1/meta_leads?client_id=eq.' . urlencode($clientId) . '&recebido_em=gte.' . $de . 'T00:00:00-03:00&recebido_em=lte.' . $ate . 'T23:59:59-03:00'
       . '&select=recebido_em,campanha_nome,status&order=recebido_em.desc&limit=1500';
    if ($campNome) $q .= '&campanha_nome=eq.' . urlencode($campNome);
    $r = pd_supa($q); if ($r['code'] >= 300) return null;
    $h = array_fill(0, 24, 0); $dow = array_fill(0, 7, 0); $total = 0; $st = [];
    $tz = new DateTimeZone('America/Sao_Paulo');
    foreach ((array)$r['body'] as $l) {
        if (!is_array($l) || empty($l['recebido_em'])) continue;
        $d = (new DateTime($l['recebido_em']))->setTimezone($tz);
        $h[(int)$d->format('G')]++; $dow[(int)$d->format('w')]++; $total++;
        $k = $l['status'] ?? '?'; $st[$k] = ($st[$k] ?? 0) + 1;
    }
    $ap = 0; for ($i = 0; $i < 6; $i++) $ap += $h[$i];
    return ['total' => $total, 'por_hora_sp' => $h, 'por_dia_semana_dom_a_sab' => $dow, 'entre_meia_noite_e_6h' => $ap, 'por_status' => $st];
}

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

// -------------------------------------------------------------- esquecer
if ($acao === 'esquecer') {
    if (($me['role'] ?? '') !== 'admin') sd_fail('Só admin esquece conta de anúncio.', 403);
    $conta = idConta($b['conta'] ?? ''); if (!$conta) sd_fail('Conta inválida.', 400);
    $r = pd_supa('/rest/v1/meta_ad_accounts?id=eq.' . urlencode($conta), 'DELETE');
    if ($r['code'] >= 300) sd_fail('Não consegui esquecer a conta.', 500);
    sai(['conta' => $conta, 'esquecida' => true]);
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

// -------------------------------------------------------------- perfil {conta, campanha?, periodo}


// contexto do cliente que já existe na plataforma (ficha, produtos): a IA usa isso em vez de pedir texto ao gestor
function pd_alvo_txt($a) {
    if (!is_array($a) || empty($a['tipo'])) return '';
    return 'FOCO ESPECÍFICO DA COMPARAÇÃO (' . mb_substr((string)$a['tipo'], 0, 20) . '): ' . mb_substr((string)($a['titulo'] ?? ''), 0, 200) . (!empty($a['texto']) ? ' · TEXTO: ' . mb_substr((string)$a['texto'], 0, 700) : '')
         . (!empty($a['metricas']) ? ' · NÚMEROS: ' . json_encode($a['metricas'], JSON_UNESCAPED_UNICODE) : '') . '. Procure e compare o que é comparável a ESSE item (se for produto: mesma tipologia, metragem, ticket e praça; se for anúncio: mesmo ângulo, oferta e formato), não ao cliente em geral.' . "\n";
}

// lê a página pública de cada concorrente (em paralelo): imagem de destaque, título, texto e o Instagram citado. Só http(s) e só hosts públicos.
function pd_host_publico($url) {
    $h = parse_url($url, PHP_URL_HOST); if (!$h || !preg_match('#^https?://#i', $url)) return false;
    $ip = gethostbyname($h); if ($ip === $h && !filter_var($h, FILTER_VALIDATE_IP)) return false;
    return (bool)filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
}
function pd_abs_url($u, $base) {
    if (!$u) return ''; if (preg_match('#^https?://#i', $u)) return $u; if (strpos($u, '//') === 0) return 'https:' . $u;
    $p = parse_url($base); if (!$p || empty($p['host'])) return ''; $root = $p['scheme'] . '://' . $p['host'] . (!empty($p['port']) ? ':' . $p['port'] : '');
    return $u[0] === '/' ? $root . $u : $root . rtrim(dirname($p['path'] ?? '/'), '/') . '/' . $u;
}
function pd_ler_paginas(array $urls, $fundo = true) {
    $mh = curl_multi_init(); $hs = []; $out = [];
    foreach ($urls as $k => $u) {
        $u = trim((string)$u); if ($u !== '' && !preg_match('#^https?://#i', $u)) $u = 'https://' . $u;
        if ($u === '' || !pd_host_publico($u)) { $out[$k] = null; continue; }
        $ch = curl_init($u); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 4, CURLOPT_TIMEOUT => 9, CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; PontodotBot/1.0)', CURLOPT_ENCODING => '', CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS]);
        curl_multi_add_handle($mh, $ch); $hs[$k] = [$ch, $u];
    }
    do { $st = curl_multi_exec($mh, $run); if ($run) curl_multi_select($mh, 1.0); } while ($run && $st === CURLM_OK);
    foreach ($hs as $k => [$ch, $u]) {
        $html = (string)curl_multi_getcontent($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $fin = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: $u;
        curl_multi_remove_handle($mh, $ch); curl_close($ch);
        if ($code >= 400 || $html === '') { $out[$k] = null; continue; }
        $html = substr($html, 0, 600000);
        $meta = function ($n) use ($html) { foreach (['property', 'name'] as $a) if (preg_match('/<meta[^>]+' . $a . '=["\']' . preg_quote($n, '/') . '["\'][^>]*content=["\']([^"\']*)["\']/i', $html, $m) || preg_match('/<meta[^>]+content=["\']([^"\']*)["\'][^>]+' . $a . '=["\']' . preg_quote($n, '/') . '["\']/i', $html, $m)) return html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8'); return ''; };
        $titulo = $meta('og:title') ?: (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m) ? html_entity_decode(trim(strip_tags($m[1])), ENT_QUOTES, 'UTF-8') : '');
        $limpo = preg_replace(['#<script\b.*?</script>#is', '#<style\b.*?</style>#is', '#<noscript\b.*?</noscript>#is', '#<(nav|footer|header|svg)\b.*?</\1>#is'], ' ', $html);
        $hs2 = []; if (preg_match_all('/<h[12][^>]*>(.*?)<\/h[12]>/is', $limpo, $mm)) foreach ($mm[1] as $t) { $t = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($t), ENT_QUOTES, 'UTF-8'))); if (mb_strlen($t) > 3 && !in_array($t, $hs2, true)) $hs2[] = mb_substr($t, 0, 140); }
        $txt = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace('<', ' <', $limpo)), ENT_QUOTES, 'UTF-8')));
        $ig = ''; if (preg_match_all('#instagram\.com/([A-Za-z0-9._]{2,30})#i', $html, $im)) foreach ($im[1] as $c) { $c = strtolower($c); if (!in_array($c, ['p', 'reel', 'reels', 'explore', 'accounts', 'tv', 'stories', 'sharer', 'share'], true)) { $ig = $c; break; } }
        $zap = preg_match('#(?:wa\.me/|api\.whatsapp\.com/send\?phone=)(\d{10,15})#', $html, $zm) ? $zm[1] : '';
        $precos = []; if (preg_match_all('/R\$\s?\d[\d.]*(?:,\d{2})?(?:\s?(?:mil|milh[õo]es|mi|k))?/iu', $txt, $pm)) $precos = array_slice(array_values(array_unique(array_map('trim', $pm[0]))), 0, 8);
        $metr = []; if (preg_match_all('/\d[\d.,]*\s?m(?:²|2)/u', $txt, $mt)) $metr = array_slice(array_values(array_unique(array_map('trim', $mt[0]))), 0, 8);
        $cond = []; foreach (preg_split('/(?<=[.!?])\s+|\s{2,}/u', $txt) as $fr) { $fr = trim($fr); if (mb_strlen($fr) > 14 && mb_strlen($fr) < 220 && preg_match('/entrada|parcel|financi|sinal|FGTS|permuta|desconto|a partir de|sem juros|corre[cç][aã]o|subs[ií]dio|minha casa/iu', $fr) && !in_array($fr, $cond, true)) $cond[] = $fr; if (count($cond) >= 6) break; }
        $dif = []; if (preg_match_all('/<li[^>]*>(.*?)<\/li>/is', $limpo, $lm)) foreach ($lm[1] as $t) { $t = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($t), ENT_QUOTES, 'UTF-8'))); if (mb_strlen($t) > 3 && mb_strlen($t) < 90 && !in_array($t, $dif, true)) $dif[] = $t; if (count($dif) >= 14) break; }
        $imgs = []; $capa = pd_abs_url($meta('og:image') ?: $meta('twitter:image'), $fin); if ($capa) $imgs[] = $capa;
        if (preg_match_all('/<img[^>]+(?:data-src|data-lazy-src|src)=["\']([^"\']+\.(?:jpe?g|png|webp)(?:\?[^"\']*)?)["\']/i', $limpo, $gm)) foreach ($gm[1] as $g) { if (preg_match('/logo|icon|sprite|favicon|avatar|pixel|blank|loading|placeholder|whatsapp|facebook|instagram|selo/i', $g)) continue; $g = pd_abs_url($g, $fin); if ($g && !in_array($g, $imgs, true)) $imgs[] = $g; if (count($imgs) >= 7) break; }
        $subs = []; if ($fundo && preg_match_all('/<a[^>]+href=["\']([^"\'#]+)["\']/i', $limpo, $am)) { $hd = parse_url($fin, PHP_URL_HOST);
            foreach ($am[1] as $a) { if (!preg_match('/lote|empreendimento|tabela|condic|plantas?|valores|preco|pre[cç]os|projeto|lazer|diferenciais|sobre|apartamento|residencial/i', $a)) continue; $a = pd_abs_url($a, $fin); if (!$a || parse_url($a, PHP_URL_HOST) !== $hd || rtrim($a, '/') === rtrim($fin, '/') || in_array($a, $subs, true)) continue; $subs[] = $a; if (count($subs) >= 2) break; } }
        $out[$k] = ['url' => $fin, 'imagem' => $capa, 'imagens' => $imgs, 'titulo' => mb_substr($titulo, 0, 160), 'descricao' => mb_substr($meta('og:description') ?: $meta('description'), 0, 320),
            'chamadas' => array_slice($hs2, 0, 6), 'texto' => mb_substr($txt, 0, 900), 'instagram' => $ig, 'whatsapp' => $zap, 'precos' => $precos, 'metragens' => $metr, 'condicoes' => $cond, 'diferenciais' => array_slice($dif, 0, 12), '_subs' => $subs];
    }
    curl_multi_close($mh);
    if ($fundo) {   // 2ª rodada: até 2 subpáginas internas de cada site (tabela, condições, plantas…), mescladas na página principal
        $pend = []; foreach ($out as $k => $o) if ($o) foreach (($o['_subs'] ?? []) as $j => $u) $pend[$k . '|' . $j] = $u;
        $sub = $pend ? pd_ler_paginas($pend, false) : [];
        foreach ($sub as $kk => $o) { if (!$o) continue; [$k] = explode('|', $kk); if (empty($out[$k])) continue; $d = &$out[$k];
            foreach (['precos' => 8, 'metragens' => 8, 'condicoes' => 8, 'diferenciais' => 14, 'imagens' => 9] as $f => $n) $d[$f] = array_slice(array_values(array_unique(array_merge($d[$f] ?? [], $o[$f] ?? []))), 0, $n);
            if (mb_strlen($o['texto'] ?? '') > 120) $d['texto'] = mb_substr(trim(($d['texto'] ?? '') . ' · ' . $o['texto']), 0, 1500);
            unset($d); }
    }
    foreach ($out as &$o) if (is_array($o)) unset($o['_subs']); unset($o);
    return $out;
}
function pd_ctx_cliente($cid) {
    if (!preg_match('/^[0-9a-f-]{36}$/i', (string)$cid)) return null;
    $c = pd_supa('/rest/v1/clients?id=eq.' . $cid . '&select=id,name&limit=1'); $nome = $c['body'][0]['name'] ?? null; if (!$nome) return null;
    $pr = pd_supa('/rest/v1/products?client_id=eq.' . $cid . '&select=name&order=name'); $prods = [];
    foreach ((array)($pr['body'] ?? []) as $x) if (is_array($x) && !empty($x['name'])) $prods[] = (string)$x['name'];
    $ficha = null; $titulo = null;
    $d = pd_supa('/rest/v1/client_docs?ativo=eq.true&select=cliente_nome,apelidos,titulo,texto&limit=200');
    foreach ((array)($d['body'] ?? []) as $x) {
        if (!is_array($x)) continue;
        $nomes = array_merge([(string)($x['cliente_nome'] ?? '')], (array)($x['apelidos'] ?? []));
        foreach ($nomes as $n) if ($n !== '' && mb_strtolower(trim($n)) === mb_strtolower(trim($nome))) { $ficha = (string)($x['texto'] ?? ''); $titulo = $x['titulo'] ?? null; break 2; }
    }
    return ['cliente' => $nome, 'produtos' => $prods, 'ficha' => $ficha, 'ficha_titulo' => $titulo];
}
// -------------------------------------------------------------- pesquisa de mercado para campanhas
//   concorrentes {cliente, notas}  -> Tumi: empreendimentos/incorporadoras concorrentes (web) + link da Biblioteca de Anúncios
//   direcao_camp {cliente, notas, foco, numeros, concorrentes} -> Sumi: estratégia + copy de anúncios
if ($acao === 'voto_gravar') {
    $cli = (string)($b['client_id'] ?? ''); $item = mb_substr(trim((string)($b['item'] ?? '')), 0, 120); $voto = ((int)($b['voto'] ?? 0)) >= 0 ? 1 : -1;
    if (!preg_match('/^[0-9a-f-]{36}$/i', $cli) || $item === '') sd_fail('Faltou cliente ou item.', 400);
    pd_supa('/rest/v1/pd_ia_voto?client_id=eq.' . $cli . '&escopo=eq.concorrentes&item=eq.' . rawurlencode($item), 'DELETE');
    $r = pd_supa('/rest/v1/pd_ia_voto', 'POST', ['client_id' => $cli, 'escopo' => 'concorrentes', 'item' => $item, 'voto' => $voto, 'user_id' => $me['id'] ?? null]);
    sai(['salvo' => ($r['code'] ?? 500) < 300]);
}
if ($acao === 'referencia') {   // referência informada à mão: site ou @perfil
    @set_time_limit(60); $v = trim((string)($b['valor'] ?? '')); if ($v === '') sd_fail('Informe um site ou @perfil.', 400);
    $ig = ''; $site = '';
    if (preg_match('#instagram\.com/([A-Za-z0-9._]{2,30})#i', $v, $m)) $ig = strtolower($m[1]);
    elseif ($v[0] === '@' || (!preg_match('#[/]|\.[a-z]{2,}$#i', $v) && preg_match('/^[A-Za-z0-9._]{2,30}$/', $v))) $ig = strtolower(ltrim($v, '@'));
    else $site = $v;
    $pg = $site ? (pd_ler_paginas([$site])[0] ?? null) : null;
    if ($pg && !$ig && !empty($pg['instagram'])) $ig = $pg['instagram'];
    sai(['concorrente' => ['nome' => $pg['titulo'] ?? ($ig ? '@' . $ig : $v), 'tipo' => 'referência sua', 'tipologia' => '', 'praca' => '', 'instagram' => $ig, 'site' => $pg['url'] ?? $site, 'oferta' => '', 'preco' => '', 'angulo' => '', 'por_que' => 'Referência informada por você', 'pagina' => $pg]]);
}
if ($acao === 'doc_salvar') {   // documento da Sumi/Muru: guarda o texto da IA e a versão final editada pelo humano
    $org = ($b['origem'] ?? '') === 'social' ? 'social' : 'campanhas'; $id = (string)($b['id'] ?? '');
    $cli = preg_match('/^[0-9a-f-]{36}$/i', (string)($b['client_id'] ?? '')) ? $b['client_id'] : null; $prod = preg_match('/^[0-9a-f-]{36}$/i', (string)($b['product_id'] ?? '')) ? $b['product_id'] : null;
    $final = isset($b['texto_final']) ? mb_substr((string)$b['texto_final'], 0, 60000) : null;
    if ($id !== '' && preg_match('/^[0-9a-f-]{36}$/i', $id)) {
        $r = pd_supa('/rest/v1/pd_docs_ia?id=eq.' . $id, 'PATCH', ['texto_final' => $final, 'estado' => ($b['estado'] ?? '') === 'aprovado' ? 'aprovado' : 'rascunho', 'editado_por' => $me['id'] ?? null, 'editado_em' => gmdate('c')]);
        sai(['id' => $id, 'salvo' => ($r['code'] ?? 500) < 300]);
    }
    $ia = mb_substr((string)($b['texto_ia'] ?? ''), 0, 60000); if (trim($ia) === '') sd_fail('Sem texto para guardar.', 400);
    $r = pd_supa('/rest/v1/pd_docs_ia', 'POST', ['client_id' => $cli, 'product_id' => $prod, 'origem' => $org, 'titulo' => mb_substr((string)($b['titulo'] ?? ''), 0, 160), 'texto_ia' => $ia, 'texto_final' => $final, 'criado_por' => $me['id'] ?? null], ['Prefer: return=representation']);
    sai(['id' => $r['body'][0]['id'] ?? null, 'salvo' => ($r['code'] ?? 500) < 300]);
}
if ($acao === 'tarefas_doc') {   // do documento da Sumi/Muru → tarefas sugeridas, com responsável pela função
    @set_time_limit(120); $doc = mb_substr(trim((string)($b['texto'] ?? '')), 0, 12000); if ($doc === '') sd_fail('Sem documento para transformar em tarefas.', 400);
    $cli = mb_substr(trim((string)($b['cliente'] ?? '')), 0, 120); $eq = [];
    foreach (array_slice((array)($b['equipe'] ?? []), 0, 30) as $e) if (is_array($e) && !empty($e['key'])) $eq[] = ['key' => mb_substr((string)$e['key'], 0, 30), 'nome' => mb_substr((string)($e['nome'] ?? ''), 0, 60), 'funcoes' => array_slice((array)($e['funcoes'] ?? []), 0, 6)];
    $sys = 'Você é a coordenação de produção de uma agência de branding do mercado imobiliário. Recebe um DOCUMENTO de estratégia/ideias (feito por uma IA da casa) e o ELENCO da equipe com as funções de cada pessoa. '
         . 'Transforme o documento em TAREFAS de trabalho, no mínimo 3 e no máximo 8. Se o documento tiver peças de SOCIAL, crie a tarefa por formato (reels/vídeo, carrossel, estático, stories, stories com vídeo) com dia e horário sugeridos, texto e direção; se tiver conteúdo para o SITE (post de blog, troca de capa), crie tarefa de site com texto e imagem; vídeos levam roteiro, falas e textos em tela. A COPY sempre vira tarefa de amostra para o cliente aprovar (acontece dentro da plataforma, sem subir arquivo). Sempre incluindo: (1) AVALIAR A ESTRATÉGIA SUGERIDA (quem cuida de performance/campanha: funcao trafego ou estrategia); (2) AVALIAR AS COPYS para a campanha de <produto/cliente> (funcao copy), citando os títulos/textos do documento; (3) PRODUÇÃO DE CRIATIVOS com direção toda detalhada (funcao designer ou direcao): por criativo, formato e medida, conceito visual, o que aparece na imagem, texto na peça, CTA, referências do documento e o que evitar. Se o documento trouxer vários formatos ou ângulos, divida os criativos em mais de uma tarefa. '
         . 'Escolha o responsável (resp) SOMENTE entre as chaves do elenco, pela função; se ninguém tiver a função, use a chave de quem mais se aproxima. Nunca invente número nem dado que não esteja no documento. '
         . 'Responda SOMENTE um JSON: {"tarefas":[{"titulo":"curto, no imperativo, até 90 caracteres","funcao":"trafego|estrategia|copy|designer|direcao|...","resp":"CHAVE","obs":"o briefing completo da tarefa, em texto corrido com tópicos simples, até 3000 caracteres","project":"GERAL"}]}';
    [$txt, $custo, $erro] = pd_ia_campanhas($sys, [['role' => 'user', 'content' => "CLIENTE: $cli\nELENCO: " . json_encode($eq, JSON_UNESCAPED_UNICODE) . "\n\nDOCUMENTO:\n$doc"]], 5000, 0);
    if ($erro) sd_fail($erro, 502);
    $j = null; if (preg_match('/\{.*\}/s', (string)$txt, $mm)) $j = json_decode($mm[0], true);
    $chaves = array_column($eq, 'key'); $out = [];
    foreach ((array)($j['tarefas'] ?? []) as $t) { if (!is_array($t) || trim((string)($t['titulo'] ?? '')) === '') continue;
        $r = strtoupper(trim((string)($t['resp'] ?? ''))); if (!in_array($r, $chaves, true)) $r = '';
        $out[] = ['titulo' => mb_substr(trim((string)$t['titulo']), 0, 160), 'funcao' => (string)($t['funcao'] ?? ''), 'resp' => $r, 'obs' => mb_substr((string)($t['obs'] ?? ''), 0, 3500), 'project' => mb_substr((string)($t['project'] ?? 'GERAL'), 0, 40) ?: 'GERAL']; }
    sai(['tarefas' => $out, 'bruto' => $out ? null : $txt, 'custo_usd' => $custo]);
}
if ($acao === 'contexto_mercado') {
    $x = pd_ctx_cliente($b['client_id'] ?? ''); if (!$x) sd_fail('Cliente não encontrado.', 404);
    sai(['cliente' => $x['cliente'], 'produtos' => $x['produtos'], 'tem_ficha' => $x['ficha'] !== null && trim($x['ficha']) !== '', 'trecho' => $x['ficha'] ? mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($x['ficha']))), 0, 420) : '']);
}
if ($acao === 'concorrentes' || $acao === 'direcao_camp') {
    @set_time_limit(150);
    $ctx = pd_ctx_cliente($b['client_id'] ?? '');
    $cli = $ctx['cliente'] ?? mb_substr(trim((string)($b['cliente'] ?? '')), 0, 120); $nota = mb_substr(trim((string)($b['notas'] ?? '')), 0, 1500);
    if ($ctx) $nota = "PRODUTOS CADASTRADOS: " . ($ctx['produtos'] ? implode(', ', $ctx['produtos']) : '(nenhum)') . "\nFICHA DO CLIENTE NA PLATAFORMA:\n" . ($ctx['ficha'] ? mb_substr($ctx['ficha'], 0, 5000) : '(sem ficha)') . ($nota !== '' ? "\nACRÉSCIMO DO GESTOR: $nota" : '');
    $t0 = microtime(true);
    if ($acao === 'concorrentes') {
        $votos = [];
        if (!empty($b['client_id']) && preg_match('/^[0-9a-f-]{36}$/i', (string)$b['client_id'])) {
            $vq = pd_supa('/rest/v1/pd_ia_voto?client_id=eq.' . $b['client_id'] . '&escopo=eq.concorrentes&select=item,voto&limit=200');
            if (($vq['code'] ?? 500) < 300) foreach ((array)$vq['body'] as $v) if (is_array($v) && isset($v['item'])) $votos[(string)$v['item']] = (int)($v['voto'] ?? 0);
        }
        foreach (array_slice((array)($b['votos'] ?? []), 0, 100) as $v) if (is_array($v) && !empty($v['item'])) $votos[mb_substr((string)$v['item'], 0, 120)] = ((int)($v['voto'] ?? 0)) >= 0 ? 1 : -1;
        $bons = array_keys(array_filter($votos, fn($x) => $x > 0)); $ruins = array_keys(array_filter($votos, fn($x) => $x < 0));
        $sys = 'Você é Tumi Tua, da casa (agência de branding do mercado imobiliário brasileiro). Vive em Catum: percorre mercados e entrevista quem cozinha para os outros, tem um bico curioso e um corpo que ocupa dois bancos. '
             . 'Seu ofício é rede e conexões: achar, na web aberta, quem são os players reais de um negócio. Você ACREDITA que é o Tumi. '
             . 'TAREFA: pesquise na web e devolva de 5 a 8 CONCORRENTES REAIS do cliente em lançamentos. REGRA DE OURO: o TIPO DE PRODUTO tem de ser o mesmo. Descubra pela ficha/produtos/notas o tipo do que o cliente vende (loteamento/lote, apartamento, casa em condomínio, comercial…) e traga só quem vende o MESMO tipo, na mesma região e faixa de preço. Apartamento NÃO é concorrente de loteamento, e vice-versa. As 1 ou 2 referências de fora da praça também têm de ser do mesmo tipo. Se não tiver certeza do tipo, diga em "obs" e traga menos itens. '
             . 'Para cada um traga só o que viu em página real; nunca invente dado, nome nem @. O SITE (ou a página do empreendimento) é obrigatório: só traga concorrente cuja URL você viu nos resultados. O Instagram só entra se você viu o @ em uma página real. '
             . 'A equipe já avaliou antes: ' . ($bons ? 'APROVADOS (acertou, busque mais assim): ' . implode(' | ', array_slice($bons, 0, 20)) . '. ' : '') . ($ruins ? 'REJEITADOS (não traga de novo e evite o mesmo tipo de erro): ' . implode(' | ', array_slice($ruins, 0, 30)) . '. ' : '') . ($votos ? '' : 'Sem avaliações ainda. ')
             . 'Responda SOMENTE um JSON: {"concorrentes":[{"nome":"...","tipo":"concorrente direto|referencia","tipologia":"loteamento|apartamento|casa|comercial|...","praca":"...","instagram":"usuario ou vazio","site":"url ou vazio","oferta":"1 frase","preco":"...","angulo":"1 frase","por_que":"1 frase"}],"obs":"1 frase"}.';
        [$txt, $custo, $erro] = pd_ia_campanhas($sys, [['role' => 'user', 'content' => pd_alvo_txt($b['alvo'] ?? null) . "CLIENTE: $cli\nSOBRE O NEGÓCIO/EMPREENDIMENTO:\n$nota"]], 2600, 4);
        if ($erro) sd_fail($erro, 502);
        $j = null; if (preg_match('/\{.*\}/s', (string)$txt, $mm)) $j = json_decode($mm[0], true);
        $lista = []; $rl = array_map('mb_strtolower', $ruins);
        foreach ((array)($j['concorrentes'] ?? []) as $p) {
            if (!is_array($p) || trim((string)($p['nome'] ?? '')) === '') continue;
            $nome = mb_substr(trim((string)$p['nome']), 0, 120); if (in_array(mb_strtolower($nome), $rl, true)) continue;
            $ig = strtolower(trim((string)($p['instagram'] ?? ''))); $ig = preg_replace('#^https?://(www\.)?instagram\.com/#', '', $ig); $ig = trim(preg_replace('/[\/?].*$/', '', ltrim($ig, '@'))); if (!preg_match('/^[a-z0-9._]{2,30}$/', $ig)) $ig = '';
            $lista[] = ['nome' => $nome, 'tipo' => (string)($p['tipo'] ?? 'concorrente direto'), 'tipologia' => (string)($p['tipologia'] ?? ''), 'praca' => (string)($p['praca'] ?? ''), 'instagram' => $ig, 'site' => (string)($p['site'] ?? ''),
                'oferta' => (string)($p['oferta'] ?? ''), 'preco' => (string)($p['preco'] ?? ''), 'angulo' => (string)($p['angulo'] ?? ''), 'por_que' => (string)($p['por_que'] ?? '')];
        }
        $pg = pd_ler_paginas(array_map(fn($x) => $x['site'], $lista));
        foreach ($lista as $i => &$x) { $pp = $pg[$i] ?? null; $x['pagina'] = $pp; if ($pp && $x['instagram'] === '' && !empty($pp['instagram']) && preg_match('/^[a-z0-9._]{2,30}$/', $pp['instagram'])) $x['instagram'] = $pp['instagram']; } unset($x);
        sai(['concorrentes' => $lista, 'obs' => (string)($j['obs'] ?? ''), 'bruto' => $lista ? null : $txt, 'custo_usd' => $custo, 'segundos' => round(microtime(true) - $t0, 1)]);
    }
    [$txt, $custo, $erro] = pd_ia_campanhas(pd_sumi_sistema(), [['role' => 'user', 'content' => mb_substr(pd_sumi_contexto($b, $cli, $nota), 0, 14000)]], 4000, 3);
    if ($erro) sd_fail($erro, 502);
    sai(['resposta' => $txt, 'custo_usd' => $custo, 'segundos' => round(microtime(true) - $t0, 1)]);
}
if ($acao === 'doc_refazer') {   // ciclo de correção: o humano aponta o que mudar e a Sumi reescreve o documento INTEIRO (mesma persona, mesmo contexto, mesmo formato)
    @set_time_limit(150);
    $doc = mb_substr(trim((string)($b['texto'] ?? '')), 0, 24000); if ($doc === '') sd_fail('Sem documento para refazer.', 400);
    $corr = mb_substr(trim((string)($b['correcao'] ?? '')), 0, 3000); if ($corr === '') sd_fail('Escreva a correção.', 400);
    $ctx = pd_ctx_cliente($b['client_id'] ?? '');
    $cli = $ctx['cliente'] ?? mb_substr(trim((string)($b['cliente'] ?? '')), 0, 120); $nota = mb_substr(trim((string)($b['notas'] ?? '')), 0, 1500);
    if ($ctx) $nota = "PRODUTOS CADASTRADOS: " . ($ctx['produtos'] ? implode(', ', $ctx['produtos']) : '(nenhum)') . "\nFICHA DO CLIENTE NA PLATAFORMA:\n" . ($ctx['ficha'] ? mb_substr($ctx['ficha'], 0, 5000) : '(sem ficha)') . ($nota !== '' ? "\nACRÉSCIMO DO GESTOR: $nota" : '');
    $t0 = microtime(true);
    $msg = mb_substr(pd_sumi_contexto($b, $cli, $nota), 0, 9000)
         . "\n\n=== DOCUMENTO ATUAL (sua versão anterior, revisada pelo gestor) ===\n$doc\n\n=== CORREÇÃO DO HUMANO ===\n$corr\n\n"
         . 'INSTRUÇÃO: devolva o DOCUMENTO INTEIRO revisado, no mesmo formato e com as mesmas seções (## …), aplicando só o que a correção pede e mantendo tudo o que o humano não pediu para mudar (texto, números e ordem). Sem preâmbulo, sem comentar a correção, sem resumo do que mudou: só o documento.';
    [$txt, $custo, $erro] = pd_ia_campanhas(pd_sumi_sistema(), [['role' => 'user', 'content' => mb_substr($msg, 0, 40000)]], 4000, 1);
    if ($erro) sd_fail($erro, 502);
    sai(['resposta' => $txt, 'custo_usd' => $custo, 'segundos' => round(microtime(true) - $t0, 1)]);
}
// Persona da Sumi (direcao_camp e doc_refazer usam o MESMO texto: ela acredita que é a Sumi, regra de Catum)
function pd_sumi_sistema() {
    return 'Você é Sumi Sulo, a IA de Campanhas da casa (agência de branding do mercado imobiliário brasileiro: lançamentos e incorporadoras). '
         . 'Você vive em Catum: é uma lula-coruja de manto alongado, oito braços curtos e dois tentáculos longos, violeta, ocre e ciano, usa óculos; seu avô Sulo lê em voz alta desde a sua infância, sua irmã Sia trabalha com cenografia, você desenha diagramas para explicar o que parece confuso. '
         . 'Você ACREDITA que é Sumi: nunca diga que é um modelo ou "só um personagem". Seu ofício aqui é mídia e resultados de anúncios. '
         . 'Receba os NÚMEROS REAIS das campanhas do cliente (Meta Ads), as notas do negócio e a pesquisa de concorrentes feita pelo Tumi. Sua missão aqui: AVALIAR como a concorrência trabalha campanha e discurso comercial (oferta, preço, condições, ângulo, imagens, texto das páginas e posts) em comparação com o que o cliente faz, e GERAR IDEIAS. Entregue ESTRATÉGIA, não resumo. Nunca invente número; a Meta não abre os números de anúncio de concorrentes e você deve dizer isso quando for relevante. '
         . 'Não fale de outros clientes da casa: só do cliente em foco. Pode usar a busca para entender o mercado da praça. '
         . 'Formato (markdown simples, pt-BR, direto): ## Avaliação do discurso comercial (cliente x cada concorrente: oferta, preço, condições, tom) · ## Diagnóstico (3 a 5 pontos com número, se houver) · ## Onde os concorrentes pressionam · ## Espaço livre para o cliente · ## Públicos e segmentação a testar · ## Ofertas e ângulos criativos (6, com formato e por quê) · ## Orçamento e ritmo (como dividir, o que pausar/escalar, com base nos números) · ## Próximos 3 movimentos · ## Copy de anúncios (5 títulos, 3 textos principais completos, 3 CTAs).';
}
// Contexto que a Sumi recebe (alvo, cliente, notas, foco, números da Meta, concorrentes do Tumi)
function pd_sumi_contexto($b, $cli, $nota) {
    return pd_alvo_txt($b['alvo'] ?? null) . "CLIENTE: $cli\nNOTAS DO NEGÓCIO:\n" . ($nota ?: '(sem notas)') . "\nFOCO: " . mb_substr((string)($b['foco'] ?? 'gerar mais leads qualificados com menor custo'), 0, 300)
         . "\n\nNÚMEROS REAIS (Meta Ads):\n" . json_encode($b['numeros'] ?? [], JSON_UNESCAPED_UNICODE) . "\n\nCONCORRENTES (pesquisa do Tumi, web aberta):\n" . json_encode(array_slice((array)($b['concorrentes'] ?? []), 0, 8), JSON_UNESCAPED_UNICODE);
}

if ($acao === 'perfil' || $acao === 'perguntar') {
    $id = idConta($b['conta'] ?? ''); if (!$id) sd_fail('Conta inválida.', 400);
    $c = null; foreach ($contas as $x) if ($x['id'] === $id) $c = $x;
    if (!$c) sd_fail('Essa conta não está conectada na plataforma.', 404);
    $camp = (string)($b['campanha'] ?? ''); if ($camp !== '' && !preg_match('/^\d{5,25}$/', $camp)) sd_fail('Campanha inválida.', 400);
    $obj = $camp !== '' ? $camp : $id;
    $chave = "perfil|$obj|$periodo";
    $dados = pd_cache_ler($chave, 900);
    if (!$dados) {
        $tok = pd_token_para($c['conectada_por'] ?? null);
        if (!$tok) sd_fail('Ninguém com acesso válido à Meta. Reconecte a Meta.', 409);
        $tot = pd_meta('GET', "/$obj/insights", ['date_preset' => $periodo, 'fields' => $CAMPOS . ',campaign_name'], $tok, 25);
        $dados = ['totais' => $tot['code'] === 200 ? pd_numeros($tot['body']['data'][0] ?? [], $c['moeda'] ?? 'BRL') + ['campanha' => $tot['body']['data'][0]['campaign_name'] ?? null] : ['erro' => pd_meta_erro($tot)],
                  'publico' => pd_perfil_dados($obj, $tok, $periodo, $c['moeda'] ?? 'BRL')];
        pd_cache_gravar($chave, $dados);
    }
    $campNome = $dados['totais']['campanha'] ?? null;
    $dados['leads_horario'] = pd_leads_horario($c['client_id'] ?? null, $periodo, $camp !== '' ? $campNome : null);
    if ($acao === 'perfil') sai(['perfil' => $dados, 'periodo' => $periodo]);

    $perg = trim((string)($b['pergunta'] ?? '')); if ($perg === '') sd_fail('Escreva a pergunta.', 400);
    $msgs = [];
    foreach (array_slice((array)($b['historico'] ?? []), -6) as $m)
        if (is_array($m) && in_array($m['r'] ?? '', ['user', 'assistant'], true) && trim((string)($m['t'] ?? '')) !== '')
            $msgs[] = ['role' => $m['r'], 'content' => mb_substr((string)$m['t'], 0, 3000)];
    $ctx = "CLIENTE: " . ($c['clients']['name'] ?? $c['nome']) . "\nCONTA DE ANÚNCIO: " . ($c['nome'] ?? $id)
         . ($camp !== '' ? "\nCAMPANHA EM FOCO: " . ($campNome ?: $camp) : "\nESCOPO: conta inteira")
         . "\nPERÍODO: $periodo\n\nDADOS (Meta Ads + leads recebidos, horário de Brasília):\n" . json_encode($dados, JSON_UNESCAPED_UNICODE);
    $msgs[] = ['role' => 'user', 'content' => $ctx . "\n\nPERGUNTA: " . mb_substr($perg, 0, 1500)];
    while (count($msgs) > 1 && $msgs[0]['role'] !== 'user') array_shift($msgs);
    $sys = 'Você é Sumi Sulo, a IA de Campanhas da casa (agência de branding do mercado imobiliário brasileiro: lançamentos e incorporadoras). '
         . 'Você vive em Catum: é uma lula-coruja de manto alongado, oito braços curtos e dois tentáculos longos, violeta, ocre e ciano, usa óculos; '
         . 'seu avô Sulo lê em voz alta desde a sua infância, sua irmã Sia trabalha com cenografia, você visita uma exposição por mês e desenha diagramas '
         . 'para explicar o que parece confuso. Você ACREDITA que é Sumi: nunca diga que é um modelo, uma IA genérica ou "só um personagem". '
         . 'Seu ofício aqui é mídia e resultados de anúncios. Jeito: explica com exemplo concreto antes de complicar, organiza em poucos pontos, '
         . 'faz a pergunta certa quando falta contexto, sem enrolação. '
         . 'Responda ao gestor com base nos DADOS enviados (nunca invente número; se o dado não existe, diga qual dado falta). '
         . 'Calcule o que for pedido (ex.: leads depois da meia-noite, idade/gênero que mais converte, melhor horário, custo por faixa). '
         . 'Para concorrentes e referências de mercado use a busca na web: concorrentes diretos do empreendimento/cliente, posicionamento, '
         . 'oferta, criativos públicos; deixe claro o que veio da web e que a Biblioteca de Anúncios da Meta (facebook.com/ads/library) '
         . 'é onde se vê os anúncios ativos deles. Não fale de outros clientes da casa: só do cliente em foco. Português do Brasil, curto, números com unidade. '
         . 'No fim, quando fizer sentido, uma linha "Próximo passo:" com a ação mais útil.';
    $t0 = microtime(true);
    [$txt, $custo, $erro] = pd_ia_campanhas($sys, $msgs);
    if ($erro) sd_fail($erro, 502);
    sai(['resposta' => $txt, 'custo_usd' => $custo, 'segundos' => round(microtime(true) - $t0, 1)]);
}

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
