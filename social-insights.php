<?php
// =====================================================================
// SD Workflow — métricas de posts publicados
//
// Busca ao vivo na Meta — não guardamos cópia no nosso banco, porque
// visualização e curtida mudam a cada minuto, e uma cópia salva ficaria
// velha rápido demais pra valer a pena. Só busca quando alguém pede,
// clicando em "Ver métricas" — não fica puxando sozinho.
//
// SÓ FUNCIONA PARA quem reconectou depois que a permissão
// instagram_manage_insights entrou no pedido de login. Posts
// publicados antes disso continuam sem métrica, mesmo depois de
// reconectar — a permissão vale pra frente, não volta no tempo.
// =====================================================================

require_once __DIR__ . '/sd-auth.php';
require_once __DIR__ . '/social-publish-lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') sd_fail('Metodo nao permitido', 405);

$me = sd_current_user();
if ($me['role'] !== 'admin') {
    $p = sd_supabase('/rest/v1/profile_permissions?profile_id=eq.' . urlencode($me['id'])
        . '&permission=eq.social.publicar&select=permission');
    if (empty($p['body'][0])) sd_fail('Você não tem permissão para ver métricas.', 403);
}

$dados = sd_body();
$targetIds = $dados['target_ids'] ?? [];
if (!is_array($targetIds) || !count($targetIds)) sd_fail('Nenhum alvo informado.', 400);
if (count($targetIds) > 25) sd_fail('Máximo de 25 por vez.', 400);

$r = supa('/rest/v1/scheduled_post_targets'
    . '?id=in.(' . implode(',', array_map('urlencode', $targetIds)) . ')'
    . '&select=id,social_account_id,platform,status,platform_post_id', 'GET');
if ($r['code'] !== 200) sd_fail('Erro ao buscar os alvos.', 500);

$resultados = [];
foreach ($r['body'] as $t) {
    if ($t['status'] !== 'publicado' || !$t['platform_post_id']) {
        $resultados[] = ['id' => $t['id'], 'ok' => false, 'motivo' => 'Ainda não publicado.'];
        continue;
    }

    $tk = supa('/rest/v1/social_account_tokens?social_account_id=eq.' . urlencode($t['social_account_id'])
        . '&select=access_token', 'GET');
    $token = $tk['body'][0]['access_token'] ?? null;
    if (!$token) {
        $resultados[] = ['id' => $t['id'], 'ok' => false, 'motivo' => 'Sem token — reconecte a conta.'];
        continue;
    }

    if ($t['platform'] === 'instagram') {
        $insights = meta_call('GET', "/{$t['platform_post_id']}/insights", [
            'metric' => 'reach,likes,comments,saved,shares,total_interactions',
            'access_token' => $token,
        ]);

        if ($insights['code'] !== 200) {
            $msg = $insights['body']['error']['message'] ?? 'Erro desconhecido';
            $resultados[] = ['id' => $t['id'], 'ok' => false, 'motivo' => $msg];
            continue;
        }

        $metricas = [];
        foreach ($insights['body']['data'] ?? [] as $m) {
            $metricas[$m['name']] = $m['values'][0]['value'] ?? 0;
        }
        $resultados[] = ['id' => $t['id'], 'ok' => true, 'metricas' => $metricas];

    } else {
        // Facebook usa nomes de métrica diferentes do Instagram — a
        // mesma ideia (alcance, engajamento), API separada.
        $insights = meta_call('GET', "/{$t['platform_post_id']}/insights", [
            'metric' => 'post_impressions,post_engaged_users,post_reactions_by_type_total,post_clicks',
            'access_token' => $token,
        ]);

        if ($insights['code'] !== 200) {
            $msg = $insights['body']['error']['message'] ?? 'Erro desconhecido';
            $resultados[] = ['id' => $t['id'], 'ok' => false, 'motivo' => $msg];
            continue;
        }

        $metricas = [];
        foreach ($insights['body']['data'] ?? [] as $m) {
            $v = $m['values'][0]['value'] ?? 0;
            // post_reactions_by_type_total vem como objeto {like:N,love:N,...}
            // — soma tudo num número só, mais fácil de mostrar na tela.
            if (is_array($v)) $v = array_sum($v);
            $metricas[$m['name']] = $v;
        }
        $resultados[] = ['id' => $t['id'], 'ok' => true, 'metricas' => $metricas];
    }
}

echo json_encode(['resultados' => $resultados]);
