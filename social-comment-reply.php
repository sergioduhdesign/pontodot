<?php
// =====================================================================
// SD Workflow — responder um comentário
//
// Guarda um registro leve de cada resposta enviada por aqui (quem, o
// quê, quando) — não porque a resposta em si precise, mas porque é a
// base que a fase de IA vai precisar depois: saber o que já foi
// respondido, e por quem (humano ou IA).
// =====================================================================

require_once __DIR__ . '/sd-auth.php';
require_once __DIR__ . '/social-publish-lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') sd_fail('Metodo nao permitido', 405);

$me = sd_current_user();
if ($me['role'] !== 'admin') {
    $p = sd_supabase('/rest/v1/profile_permissions?profile_id=eq.' . urlencode($me['id'])
        . '&permission=eq.social.publicar&select=permission');
    if (empty($p['body'][0])) sd_fail('Você não tem permissão para responder.', 403);
}

$dados = sd_body();
$contaId = $dados['social_account_id'] ?? null;
$commentId = $dados['comment_id'] ?? null;
$mensagem = trim($dados['message'] ?? '');
if (!$contaId || !$commentId) sd_fail('Dados incompletos.', 400);
if ($mensagem === '') sd_fail('Escreva algo antes de enviar.', 400);
if (mb_strlen($mensagem) > 2200) sd_fail('Resposta longa demais.', 400);

$tk = supa('/rest/v1/social_account_tokens?social_account_id=eq.' . urlencode($contaId)
    . '&select=access_token', 'GET');
$token = $tk['body'][0]['access_token'] ?? null;
if (!$token) sd_fail('Sem token — reconecte essa conta.', 400);

$r = meta_call('POST', "/{$commentId}/replies", [
    'message' => $mensagem,
    'access_token' => $token,
]);

if ($r['code'] !== 200 || empty($r['body']['id'])) {
    sd_fail($r['body']['error']['message'] ?? 'Erro ao enviar a resposta', 502);
}

// Log best-effort — se falhar, a resposta já foi enviada de verdade
// pra Meta, então não vale travar o usuário por causa disso.
supa('/rest/v1/social_comment_replies', 'POST', [
    'social_account_id' => $contaId, 'comment_id' => $commentId,
    'reply_ig_id' => $r['body']['id'], 'message' => $mensagem,
    'author' => 'humano', 'author_profile_id' => $me['id'],
]);

echo json_encode(['ok' => true, 'reply_id' => $r['body']['id']]);
