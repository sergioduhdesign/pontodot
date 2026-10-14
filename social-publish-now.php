<?php
// =====================================================================
// SD Workflow — publicar agora (botão manual)
//
// Faz exatamente o que o robô do cron faz, mas na hora, para um ou
// vários alvos específicos que a pessoa escolheu na tela de Redes
// Sociais — sem esperar o horário agendado nem o relógio do cron.
//
// Protegido por SESSÃO (token de quem está logado), não pela chave do
// cron — porque quem chama aqui é uma pessoa, dentro do app, clicando
// num botão. Só quem tem a permissão de publicar consegue.
// =====================================================================

require_once __DIR__ . '/sd-auth.php';
require_once __DIR__ . '/social-publish-lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') sd_fail('Metodo nao permitido', 405);

$me = sd_current_user();
if ($me['role'] !== 'admin') {
    $p = sd_supabase('/rest/v1/profile_permissions?profile_id=eq.' . urlencode($me['id'])
        . '&permission=eq.social.publicar&select=permission');
    if (empty($p['body'][0])) sd_fail('Você não tem permissão para publicar.', 403);
}

$dados = sd_body();
$targetIds = $dados['target_ids'] ?? [];
if (!is_array($targetIds) || !count($targetIds)) sd_fail('Nenhum alvo informado.', 400);
if (count($targetIds) > 30) sd_fail('Máximo de 30 por vez — publique em lotes menores.', 400);

$r = supa('/rest/v1/scheduled_post_targets'
    . '?id=in.(' . implode(',', array_map('urlencode', $targetIds)) . ')'
    . '&select=id,post_id,social_account_id,platform,placement,caption_override,status,attempts,'
    . 'scheduled_posts(id,client_id,caption,media_urls,post_type,scheduled_at,batch_name)', 'GET');

if ($r['code'] !== 200) sd_fail('Erro ao buscar os alvos: HTTP ' . $r['code'], 500);

$resultados = [];
foreach ($r['body'] as $t) {
    if ($t['status'] === 'publicado') {
        $resultados[] = ['id' => $t['id'], 'ok' => true, 'ja_estava' => true];
        continue;
    }

    // Mesma trava do cron: marca "publicando" antes de tentar, pra não
    // publicar em dobro se o cron pegar o mesmo alvo ao mesmo tempo.
    $trava = supa('/rest/v1/scheduled_post_targets?id=eq.' . urlencode($t['id']) . '&status=neq.publicando',
        'PATCH', ['status' => 'publicando'], ['Prefer: return=representation']);
    if (empty($trava['body'][0])) {
        $resultados[] = ['id' => $t['id'], 'ok' => false, 'erro' => 'Já sendo processado.'];
        continue;
    }

    $ok = publicarAlvo($t);
    $resultados[] = ['id' => $t['id'], 'ok' => $ok];
}

echo json_encode(['resultados' => $resultados]);
