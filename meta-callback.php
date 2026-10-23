<?php
require_once __DIR__ . '/sd-base.php';
// =====================================================================
// SD Workflow — retorno da Meta (Facebook Login for Business)
//
// Chega aqui como navegação de página inteira, vindo direto da Meta —
// não é uma chamada da nossa plataforma, então não tem o cabeçalho de
// sessão de costume. A prova de que o pedido é legítimo é o "state"
// assinado, gerado pelo meta-connect-start.php.
//
// O QUE ACONTECE, PASSO A PASSO:
//   1. Confere a assinatura do state (sem isso, para aqui)
//   2. Troca o "code" da Meta por um token de usuário de curta duração
//   3. Troca esse token por um de longa duração (~60 dias)
//   4. Pergunta pra Meta: quais Páginas esse usuário administra?
//   5. Pra cada Página, pergunta: tem Instagram profissional vinculado?
//   6. Salva cada conta encontrada — Facebook e Instagram — com o
//      token de PÁGINA (não o de usuário). Esse token de Página, uma
//      vez obtido, não vence: continua valendo enquanto você seguir
//      administrador daquela Página na Meta.
//   7. Manda o navegador de volta pro app, com o resultado.
// =====================================================================

require_once __DIR__ . '/../sd-private/sd-config.php';

const META_VERSAO = 'v25.0';

function meta_get($path, $params) {
    $url = (defined('PD_META_BASE') ? PD_META_BASE : 'https://graph.facebook.com/') . META_VERSAO . $path . '?' . http_build_query($params);
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
    ]);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => json_decode($raw, true)];
}

function supa($path, $method, $body = null, $extra = []) {
    $ch = curl_init();
    $headers = array_merge([
        'apikey: ' . SD_SECRET_KEY, 'Content-Type: application/json',
    ], $extra);
    curl_setopt_array($ch, [
        CURLOPT_URL => SD_SUPABASE_URL . $path, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => json_decode($raw, true)];
}

// Volta pro app com uma mensagem — sempre pela mesma porta, sucesso ou erro.
$RET_BASE = '';
function voltar($status, $msg = '') {
    $qs = http_build_query(['meta' => $status, 'msg' => $msg]);
    // volta pra FRENTE (o verso sozinho, app.html, não tem o botão de virar)
    global $RET_BASE;
    header('Location: ' . ($RET_BASE ?: sd_base()) . '/?' . $qs);
    exit;
}

// --- 1. Confere o state -----------------------------------------------
$state = $_GET['state'] ?? '';
$partes = explode('.', $state, 2);
if (count($partes) !== 2) voltar('erro', 'Retorno inválido da Meta.');
[$payload, $assinaturaRecebida] = $partes;

$assinaturaEsperada = hash_hmac('sha256', $payload, SD_META_APP_SECRET);
if (!hash_equals($assinaturaEsperada, $assinaturaRecebida)) {
    voltar('erro', 'Não foi possível validar a origem do retorno.');
}

$dados = json_decode(base64_decode($payload), true);
if (!$dados || ($dados['exp'] ?? 0) < time()) {
    voltar('erro', 'O link de conexão expirou — tente conectar de novo.');
}
$adminId = $dados['uid'];
$RET_BASE = (!empty($dados['ret']) && in_array($dados['ret'], sd_origens(), true)) ? $dados['ret'] : '';

// A Meta pode voltar com erro (usuário cancelou, por exemplo)
if (!empty($_GET['error'])) {
    voltar('erro', $_GET['error_description'] ?? 'Conexão cancelada.');
}

$code = $_GET['code'] ?? '';
if (!$code) voltar('erro', 'Código ausente no retorno da Meta.');

// --- 2. Troca o código pelo token curto ---------------------------------
$r1 = meta_get('/oauth/access_token', [
    'client_id'     => SD_META_APP_ID,
    'client_secret' => SD_META_APP_SECRET,
    'redirect_uri'  => SD_META_REDIRECT,
    'code'          => $code,
]);
if ($r1['code'] !== 200 || empty($r1['body']['access_token'])) {
    error_log('Meta callback - troca de code falhou: ' . json_encode($r1));
    voltar('erro', 'A Meta recusou o código de autorização.');
}
$tokenCurto = $r1['body']['access_token'];

// --- 3. Troca pelo token de longa duração -------------------------------
$r2 = meta_get('/oauth/access_token', [
    'grant_type'        => 'fb_exchange_token',
    'client_id'         => SD_META_APP_ID,
    'client_secret'     => SD_META_APP_SECRET,
    'fb_exchange_token' => $tokenCurto,
]);
if ($r2['code'] !== 200 || empty($r2['body']['access_token'])) {
    error_log('Meta callback - troca long-lived falhou: ' . json_encode($r2));
    voltar('erro', 'Não foi possível gerar um acesso duradouro.');
}
$tokenLongo = $r2['body']['access_token'];

// --- 3b. ANÚNCIOS: guarda a chave de quem conectou e traz as contas -----
// A conta de anúncio fala com a chave da PESSOA (não a da Página). Ela vale
// ~60 dias; quando vencer, a plataforma avisa pra reconectar. Fica numa
// tabela que nenhum usuário lê pelo app — só este servidor.
require_once __DIR__ . '/meta-ads-lib.php';
$expira = isset($r2['body']['expires_in']) ? gmdate('c', time() + (int)$r2['body']['expires_in']) : null;
$rt = supa('/rest/v1/meta_user_tokens', 'POST', ['user_id' => $adminId, 'access_token' => $tokenLongo,
    'expira_em' => $expira, 'atualizado_em' => gmdate('c')], ['Prefer: resolution=merge-duplicates']);
if ($rt['code'] >= 300) error_log('Meta callback - meta_user_tokens falhou (rodou o migration_meta_ads.sql?): HTTP ' . $rt['code']);
$contasAds = 0;
$ra = pd_meta('GET', '/me/adaccounts', ['fields' => 'id,name,currency,account_status,business{name}', 'limit' => 200], $tokenLongo);
if ($ra['code'] === 200) {
    foreach ((array)($ra['body']['data'] ?? []) as $a) {
        if (!is_array($a) || !preg_match('/^act_\d+$/', (string)($a['id'] ?? ''))) continue;
        // sem client_id aqui: o vínculo com o cliente é escolha do admin e não pode ser apagado num relogin
        $up = supa('/rest/v1/meta_ad_accounts', 'POST', ['id' => $a['id'], 'nome' => $a['name'] ?? null,
            'moeda' => $a['currency'] ?? null, 'empresa' => $a['business']['name'] ?? null,
            'status_meta' => isset($a['account_status']) ? (int)$a['account_status'] : null,
            'conectada_por' => $adminId, 'atualizado_em' => gmdate('c')], ['Prefer: resolution=merge-duplicates']);
        if ($up['code'] < 300) $contasAds++;
    }
} else {
    error_log('Meta callback - /me/adaccounts: HTTP ' . $ra['code'] . ' ' . json_encode($ra['body']['error']['message'] ?? ''));
}

// --- 4 e 5. Páginas administradas + Instagram vinculado -----------------
$r3 = meta_get('/me/accounts', [
    'fields' => 'id,name,access_token,picture,instagram_business_account{id,username,profile_picture_url}',
    'access_token' => $tokenLongo,
    'limit' => 100,
]);
if ($r3['code'] !== 200) {
    error_log('Meta callback - /me/accounts falhou: ' . json_encode($r3));
    voltar('erro', 'Não consegui listar suas Páginas.');
}

$paginas = $r3['body']['data'] ?? [];
error_log('Meta callback - paginas encontradas: ' . count($paginas) . ' — ' . json_encode(array_map(
    fn($p) => ['id'=>$p['id']??null,'nome'=>$p['name']??null,'tem_token'=>!empty($p['access_token']),'tem_ig'=>!empty($p['instagram_business_account']['id']??null)],
    $paginas
)));
if (!count($paginas)) {
    voltar($contasAds ? 'ok' : 'aviso', $contasAds
        ? "Nenhuma Página, mas $contasAds conta(s) de anúncio conectada(s)."
        : 'Login feito, mas nenhuma Página foi encontrada nesta conta.');
}

// --- 6. Salva cada conta encontrada -------------------------------------
$conectadas = 0;

foreach ($paginas as $pagina) {
    $tokenPagina = $pagina['access_token'] ?? null;
    if (!$tokenPagina) continue;

    // --- Facebook (a Página em si) ---
    $fbAccountId = salvarConta('facebook', $pagina['id'], $pagina['name'],
        $pagina['picture']['data']['url'] ?? null, $adminId);
    if ($fbAccountId) { salvarToken($fbAccountId, $tokenPagina); $conectadas++; }

    // LEADS NA HORA: assina a Página pra Meta avisar a plataforma a cada
    // formulário preenchido (precisa de pages_manage_metadata). Se falhar,
    // segue — a conexão do resto não depende disso.
    $sub = pd_meta('POST', '/' . $pagina['id'] . '/subscribed_apps', ['subscribed_fields' => 'leadgen'], $tokenPagina);
    if ($sub['code'] === 200 && !empty($sub['body']['success'])) $leadsAtivos = ($leadsAtivos ?? 0) + 1;
    else error_log('Meta callback - leadgen ' . $pagina['id'] . ': HTTP ' . $sub['code'] . ' ' . json_encode($sub['body']['error']['message'] ?? ''));

    // --- Instagram, se a Página tiver um vinculado ---
    $ig = $pagina['instagram_business_account'] ?? null;
    if ($ig && !empty($ig['id'])) {
        $igAccountId = salvarConta('instagram', $ig['id'], $ig['username'] ?? $pagina['name'],
            $ig['profile_picture_url'] ?? null, $adminId);
        // O Instagram publica através do token da Página vinculada —
        // por isso o mesmo token entra aqui também.
        if ($igAccountId) { salvarToken($igAccountId, $tokenPagina); $conectadas++; }
    }
}

voltar('ok', $conectadas . ' conta(s) conectada(s)'
    . ($contasAds ? ' · ' . $contasAds . ' de anúncio' : '')
    . (!empty($leadsAtivos) ? ' · leads chegando de ' . $leadsAtivos . ' Página(s)' : '') . '.');

// =========================================================================
// Funções auxiliares — leem antes de escrever, porque não temos certeza
// de que existe restrição de unicidade (platform, external_account_id)
// na tabela, e um "upsert" às cegas poderia duplicar linha a cada login.
// =========================================================================
function salvarConta($platform, $externalId, $nome, $avatarUrl, $adminId) {
    $existente = supa('/rest/v1/social_accounts?platform=eq.' . urlencode($platform)
        . '&external_account_id=eq.' . urlencode($externalId) . '&select=id', 'GET');

    if (!empty($existente['body'][0]['id'])) {
        $id = $existente['body'][0]['id'];
        $upd = supa('/rest/v1/social_accounts?id=eq.' . urlencode($id), 'PATCH', [
            'account_name' => $nome, 'account_avatar_url' => $avatarUrl,
            'status' => 'conectada', 'updated_at' => date('c'),
        ]);
        if ($upd['code'] >= 300) error_log('Meta callback - PATCH social_accounts falhou: ' . json_encode($upd));
        return $id;
    }

    $ins = supa('/rest/v1/social_accounts', 'POST', [
        'platform' => $platform, 'external_account_id' => $externalId,
        'account_name' => $nome, 'account_avatar_url' => $avatarUrl,
        'status' => 'conectada', 'connected_by' => $adminId, 'connected_at' => date('c'),
        // client_id fica NULO de propósito — vinculado depois, na tela
        // de conexão, onde o admin confirma de qual cliente é cada conta.
    ], ['Prefer: return=representation']);

    if ($ins['code'] >= 300 || empty($ins['body'][0]['id'])) {
        error_log('Meta callback - POST social_accounts falhou (' . $platform . '/' . $externalId . '): '
            . 'HTTP ' . $ins['code'] . ' — ' . json_encode($ins['body']));
        return null;
    }
    return $ins['body'][0]['id'];
}

function salvarToken($accountId, $accessToken) {
    $existente = supa('/rest/v1/social_account_tokens?social_account_id=eq.' . urlencode($accountId) . '&select=social_account_id', 'GET');

    if (!empty($existente['body'][0])) {
        $upd = supa('/rest/v1/social_account_tokens?social_account_id=eq.' . urlencode($accountId), 'PATCH', [
            'access_token' => $accessToken, 'updated_at' => date('c'),
            'token_expires_at' => null,
        ]);
        if ($upd['code'] >= 300) error_log('Meta callback - PATCH social_account_tokens falhou: ' . json_encode($upd));
    } else {
        $ins = supa('/rest/v1/social_account_tokens', 'POST', [
            'social_account_id' => $accountId, 'access_token' => $accessToken,
            'token_expires_at' => null,
        ]);
        if ($ins['code'] >= 300) {
            error_log('Meta callback - POST social_account_tokens falhou (' . $accountId . '): '
                . 'HTTP ' . $ins['code'] . ' — ' . json_encode($ins['body']));
        }
    }
}
