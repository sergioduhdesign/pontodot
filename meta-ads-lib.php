<?php
// =====================================================================
// Pontodot — biblioteca de ANÚNCIOS da Meta
//
// Tudo que fala com a Marketing API passa por aqui: a versão da API
// mora num lugar só, toda chamada vai com appsecret_proof (a Meta
// confere que quem chama tem o segredo do app, não só o token), e o
// texto dos erros vem em português, com o que fazer.
//
// Versão: v25.0 (vale até julho/2028). A parte antiga (publicar,
// comentários) ainda usa v21.0, que vence em 21/01/2027 — migrar antes.
// =====================================================================

require_once __DIR__ . '/../sd-private/sd-config.php';
require_once __DIR__ . '/pd-elenco-lib.php';

const PD_META_VERSAO = 'v25.0';
const PD_META_CACHE  = __DIR__ . '/.cache-pd';

/** Uma chamada à Graph/Marketing API. Nunca lança: devolve code + body. */
function pd_meta($method, $path, $params, $token, $timeout = 30) {
    $params['access_token']    = $token;
    $params['appsecret_proof'] = hash_hmac('sha256', $token, SD_META_APP_SECRET);
    $url = (defined('PD_META_BASE') ? PD_META_BASE : 'https://graph.facebook.com/') . PD_META_VERSAO . $path;
    $ch = curl_init();
    $op = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout];
    if ($method === 'GET') { $op[CURLOPT_URL] = $url . '?' . http_build_query($params); }
    else { $op[CURLOPT_URL] = $url; $op[CURLOPT_POST] = true; $op[CURLOPT_POSTFIELDS] = http_build_query($params); }
    curl_setopt_array($ch, $op);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => json_decode((string)$raw, true)];
}

/** O erro da Meta, em português e com o próximo passo. */
function pd_meta_erro($r) {
    $e = $r['body']['error'] ?? [];
    $cod = (int)($e['code'] ?? 0); $sub = (int)($e['error_subcode'] ?? 0);
    if ($cod === 190 || in_array($sub, [463, 467, 460], true))
        return 'O acesso à Meta venceu ou foi retirado. Reconecte a Meta (botão "Reconectar") — leva um minuto.';
    if ($cod === 10 || $cod === 200 || $cod === 294)
        return 'A Meta não deu permissão para isso nesta conta. Reconecte a Meta aceitando os anúncios, ou peça acesso de parceiro ao cliente.';
    if (in_array($cod, [4, 17, 32, 613, 80000, 80004], true))
        return 'A Meta pediu um tempo (limite de consultas). Tente de novo em alguns minutos.';
    if ($r['code'] === 0) return 'A Meta não respondeu agora. Tente de novo.';
    $det = trim((string)($e['error_user_msg'] ?? '')); if ($det === '' && !empty($e['error_data'])) $det = mb_substr(json_encode($e['error_data'], JSON_UNESCAPED_UNICODE), 0, 200);
    return 'A Meta recusou: ' . mb_substr((string)($e['message'] ?? ('HTTP ' . $r['code'])), 0, 160) . ($det !== '' ? ' — ' . mb_substr($det, 0, 260) : '') . ($sub ? ' (sub ' . $sub . ')' : '');
}

/** Supabase com a chave do servidor (ignora RLS — use com cuidado). */
function pd_supa($path, $method = 'GET', $body = null, $extra = []) {
    $ch = curl_init();
    $h = array_merge(['apikey: ' . SD_SECRET_KEY, 'Content-Type: application/json'], $extra);
    curl_setopt_array($ch, [
        CURLOPT_URL => SD_SUPABASE_URL . $path, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => $h,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => json_decode((string)$raw, true)];
}

/** Leads de verdade, sem contar duas vezes: a Meta repete o mesmo lead
 *  em vários action_type. Vale o primeiro que existir, nesta ordem. */
function pd_leads_de($actions) {
    $por = [];
    foreach ((array)$actions as $a) { if (is_array($a)) $por[$a['action_type'] ?? ''] = (float)($a['value'] ?? 0); }
    foreach (['lead', 'onsite_conversion.lead_grouped', 'offsite_conversion.fb_pixel_lead', 'onsite_web_lead'] as $t)
        if (isset($por[$t])) return (int)$por[$t];
    return 0;
}

/** Os números que a casa olha, iguais pra conta e pra campanha. */
function pd_numeros($linha, $moeda = 'BRL') {
    $gasto = round((float)($linha['spend'] ?? 0), 2);
    $leads = pd_leads_de($linha['actions'] ?? []);
    return [
        'gasto'      => $gasto,
        'alcance'    => (int)($linha['reach'] ?? 0),
        'impressoes' => (int)($linha['impressions'] ?? 0),
        'cliques'    => (int)($linha['clicks'] ?? 0),
        'leads'      => $leads,
        'cpl'        => $leads > 0 ? round($gasto / $leads, 2) : null,
        'moeda'      => $moeda ?: 'BRL',
    ];
}

/** A chave de quem conectou a conta; se ela venceu, a de qualquer admin que ainda vale. */
function pd_token_para($conectadaPor) {
    $agora = gmdate('c');
    if ($conectadaPor) {
        $t = pd_supa('/rest/v1/meta_user_tokens?user_id=eq.' . urlencode($conectadaPor) . '&select=access_token,expira_em');
        $l = $t['body'][0] ?? null;
        if ($l && (!$l['expira_em'] || $l['expira_em'] > $agora)) return $l['access_token'];
    }
    $t = pd_supa('/rest/v1/meta_user_tokens?select=access_token,expira_em&order=atualizado_em.desc&limit=5');
    foreach ((array)($t['body'] ?? []) as $l)
        if (is_array($l) && (!$l['expira_em'] || $l['expira_em'] > $agora)) return $l['access_token'];
    return null;
}

/** Cache curto em disco: a Meta limita consultas, e número de anúncio não muda a cada segundo. */
function pd_cache_ler($chave, $validade) {
    $f = PD_META_CACHE . '/ads-' . md5($chave) . '.json';
    if (!is_file($f) || (time() - filemtime($f)) > $validade) return null;
    $d = json_decode((string)file_get_contents($f), true);
    return is_array($d) ? $d : null;
}
function pd_cache_gravar($chave, $dados) {
    if (!is_dir(PD_META_CACHE)) { @mkdir(PD_META_CACHE, 0755, true); @file_put_contents(PD_META_CACHE . '/.htaccess', "Deny from all\n"); }
    @file_put_contents(PD_META_CACHE . '/ads-' . md5($chave) . '.json', json_encode($dados), LOCK_EX);
}

// Claude com busca na web, para as IAs de campanha (Sumi/Tumi). Devolve [texto, custo_usd, erro].
function pd_ia_campanhas($system, $msgs, $max = 1800, $usos = 4) {
    if (!defined('SD_ANTHROPIC_KEY') || !SD_ANTHROPIC_KEY) return [null, 0, 'Sem chave da Anthropic no servidor.'];
    $body = ['model' => defined('SD_ANALISTA_MODEL') ? SD_ANALISTA_MODEL : 'claude-sonnet-4-5', 'max_tokens' => $max, 'system' => $system . "\n\n" . pdElencoTexto(false),
        'messages' => $msgs];
    if ($usos > 0) $body['tools'] = [['type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => $usos]];
    $base = defined('PD_ANTHROPIC_BASE') ? rtrim(PD_ANTHROPIC_BASE, '/') : 'https://api.anthropic.com';
    $ch = curl_init($base . '/v1/messages');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 110,
        CURLOPT_HTTPHEADER => ['x-api-key: ' . SD_ANTHROPIC_KEY, 'anthropic-version: 2023-06-01', 'content-type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE)]);
    $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code !== 200) return [null, 0, 'A IA não respondeu (HTTP ' . $code . ').'];
    $j = json_decode((string)$raw, true); $t = '';
    foreach ((array)($j['content'] ?? []) as $x) if (($x['type'] ?? '') === 'text') $t .= $x['text'];
    $u = $j['usage'] ?? [];
    $custo = (($u['input_tokens'] ?? 0) * 3 + ($u['output_tokens'] ?? 0) * 15) / 1e6 + (($u['server_tool_use']['web_search_requests'] ?? 0) * 0.01);
    return [trim($t), round($custo, 4), null];
}
