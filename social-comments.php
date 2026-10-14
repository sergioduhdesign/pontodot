<?php
// =====================================================================
// SD Workflow — lista de posts recentes (pra abrir os comentários)
//
// Busca direto na Meta os últimos posts da conta — inclui os que
// foram publicados fora do SDWF também (conteúdo orgânico, postado
// direto pelo cliente ou pela equipe fora daqui). É por isso que não
// lemos de scheduled_post_targets: aqui o alvo é "tudo que existe na
// conta", não só o que passou pelo nosso agendador.
// =====================================================================

require_once __DIR__ . '/sd-auth.php';
require_once __DIR__ . '/social-publish-lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') sd_fail('Metodo nao permitido', 405);

$me = sd_current_user();
if ($me['role'] !== 'admin') {
    $p = sd_supabase('/rest/v1/profile_permissions?profile_id=eq.' . urlencode($me['id'])
        . '&permission=eq.social.publicar&select=permission');
    if (empty($p['body'][0])) sd_fail('Você não tem permissão para ver comentários.', 403);
}

$dados = sd_body();
$contaId = $dados['social_account_id'] ?? null;
if (!$contaId) sd_fail('Conta não informada.', 400);

$conta = supa('/rest/v1/social_accounts?id=eq.' . urlencode($contaId)
    . '&select=id,platform,external_account_id,account_name', 'GET');
$c = $conta['body'][0] ?? null;
if (!$c) sd_fail('Conta não encontrada.', 404);
if ($c['platform'] !== 'instagram') sd_fail('Comentários por enquanto só para Instagram.', 400);

$tk = supa('/rest/v1/social_account_tokens?social_account_id=eq.' . urlencode($contaId)
    . '&select=access_token', 'GET');
$token = $tk['body'][0]['access_token'] ?? null;
if (!$token) sd_fail('Sem token — reconecte essa conta em Admin → Redes sociais.', 400);

$r = meta_call('GET', "/{$c['external_account_id']}/media", [
    'fields' => 'id,caption,media_type,media_url,thumbnail_url,permalink,timestamp,like_count,comments_count',
    'limit'  => 25,
    'access_token' => $token,
]);

if ($r['code'] !== 200) {
    $msg = $r['body']['error']['message'] ?? 'Erro desconhecido';
    // Mensagem mais clara pro caso mais comum: permissão pedida depois
    // do último login — token velho não carrega ela.
    if (stripos($msg, 'permission') !== false || $r['code'] === 400) {
        $msg .= ' — se for erro de permissão, reconecte a conta em Admin → Redes sociais.';
    }
    sd_fail($msg, 502);
}

echo json_encode(['posts' => $r['body']['data'] ?? [], 'conta' => $c['account_name']]);
