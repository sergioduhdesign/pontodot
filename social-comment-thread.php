<?php
// =====================================================================
// SD Workflow — comentários de um post (com as respostas já dentro)
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
$mediaId = $dados['media_id'] ?? null;
if (!$contaId || !$mediaId) sd_fail('Dados incompletos.', 400);

$tk = supa('/rest/v1/social_account_tokens?social_account_id=eq.' . urlencode($contaId)
    . '&select=access_token', 'GET');
$token = $tk['body'][0]['access_token'] ?? null;
if (!$token) sd_fail('Sem token — reconecte essa conta.', 400);

$r = meta_call('GET', "/{$mediaId}/comments", [
    'fields' => 'id,text,username,timestamp,like_count,replies{id,text,username,timestamp,like_count}',
    'limit'  => 50,
    'access_token' => $token,
]);

if ($r['code'] !== 200) {
    sd_fail($r['body']['error']['message'] ?? 'Erro ao buscar comentários', 502);
}

echo json_encode(['comentarios' => $r['body']['data'] ?? []]);
