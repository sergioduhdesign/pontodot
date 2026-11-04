<?php
// =====================================================================
// Pontodot — CONFERIR A FILA antes da hora
//
// Olha cada post agendado nos próximos dias e diz se vai sair ou o que
// vai impedir: conta desconectada, chave vencida, Instagram sem acesso,
// arte faltando, peça que deixou de estar aprovada, data já velha demais.
// Só LÊ (nada é publicado, nada é gasto). Quem usa: o botão "Conferir
// fila" da caixa Social. Sessão obrigatória; equipe ou quem publica.
// =====================================================================
require_once __DIR__ . '/sd-auth.php';
require_once __DIR__ . '/social-publish-lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') sd_fail('Metodo nao permitido', 405);
$me = sd_current_user();
if (!in_array($me['role'] ?? '', ['admin', 'team'], true)) sd_fail('Só a equipe confere a fila.', 403);
@set_time_limit(60);

$agora = time();
$r = supa('/rest/v1/scheduled_post_targets?status=eq.agendado'
    . '&select=id,post_id,social_account_id,platform,placement,scheduled_at_override,'
    . 'scheduled_posts(id,client_id,batch_name,scheduled_at,media_urls)&limit=400', 'GET');
if ($r['code'] !== 200) sd_fail('Não consegui ler a fila: HTTP ' . $r['code'], 500);

$ate = $agora + 7 * 86400;
$alvos = [];
foreach ((array)$r['body'] as $t) {
    $q = strtotime($t['scheduled_at_override'] ?? ($t['scheduled_posts']['scheduled_at'] ?? '')) ?: null;
    if ($q === null || $q > $ate) continue;
    $t['_q'] = $q; $alvos[] = $t;
}
usort($alvos, fn($a, $b) => $a['_q'] <=> $b['_q']);

// 1 checagem por conta (não por post): o resultado vale pra todos os posts dela
$ids = array_values(array_unique(array_column($alvos, 'social_account_id')));
$contas = [];
if ($ids) {
    $ac = supa('/rest/v1/social_accounts?id=in.(' . implode(',', array_map('rawurlencode', $ids)) . ')&select=id,platform,account_name,external_account_id,status', 'GET');
    foreach ((array)($ac['body'] ?? []) as $a) $contas[$a['id']] = $a;
}
$estadoConta = [];
foreach ($contas as $cid => $c) {
    $prob = null; $extra = null;
    $tk = supa('/rest/v1/social_account_tokens?social_account_id=eq.' . urlencode($cid) . '&select=access_token', 'GET');
    $token = $tk['body'][0]['access_token'] ?? null;
    if (($c['status'] ?? '') !== 'conectada') $prob = 'conta desconectada — reconecte em Redes sociais';
    elseif (!$token) $prob = 'sem chave de acesso — reconecte a Meta';
    else {
        $campos = $c['platform'] === 'instagram' ? 'id,username' : 'id,name';
        $x = meta_call('GET', '/' . $c['external_account_id'], ['fields' => $campos, 'access_token' => $token], 20);
        if ($x['code'] !== 200) $prob = 'a Meta recusou o acesso: ' . meta_motivo($x);
        elseif ($c['platform'] === 'instagram') {
            // limite de publicações das últimas 24 h (o Instagram aceita 100 por conta)
            $l = meta_call('GET', '/' . $c['external_account_id'] . '/content_publishing_limit', ['fields' => 'quota_usage,config', 'access_token' => $token], 20);
            $uso = $l['body']['data'][0]['quota_usage'] ?? null;
            if ($uso !== null && (int)$uso >= 90) $prob = 'perto do limite diário do Instagram (' . (int)$uso . ' de 100 em 24 h)';
            elseif ($l['code'] !== 200) $prob = 'a conta responde, mas não confirmou permissão de publicar: ' . meta_motivo($l);
        }
    }
    $estadoConta[$cid] = $prob;
}

$saida = ['ok' => 0, 'problemas' => 0, 'itens' => []];
foreach ($alvos as $t) {
    $p = $t['scheduled_posts'] ?? [];
    $c = $contas[$t['social_account_id']] ?? null;
    $probs = [];
    if (!$c) $probs[] = 'a conta deste post não existe mais';
    elseif ($estadoConta[$t['social_account_id']] ?? null) $probs[] = $estadoConta[$t['social_account_id']];
    $midias = is_array($p['media_urls'] ?? null) ? $p['media_urls'] : [];
    if (!$midias) $probs[] = 'sem nenhuma arte';
    $lim = pd_tolerancia_seg($t['placement'] ?? 'feed');
    if ($agora - $t['_q'] > $lim) $probs[] = 'já passou da hora demais — não vai sair sozinho (reagende)';
    $item = pd_item_do_lote($p['batch_name'] ?? '');
    if ($item) {
        $it = supa('/rest/v1/approval_items?id=eq.' . urlencode($item) . '&select=status,title', 'GET');
        $st = $it['body'][0]['status'] ?? null;
        if ($st && $st !== 'aprovado') $probs[] = 'a peça não está mais aprovada (' . $st . ')';
    }
    $saida[$probs ? 'problemas' : 'ok']++;
    if ($probs) $saida['itens'][] = ['alvo' => $t['id'], 'quando' => gmdate('c', $t['_q']), 'conta' => $c['account_name'] ?? '?',
        'plataforma' => $t['platform'], 'lugar' => $t['placement'], 'problemas' => $probs];
}
$saida['total'] = count($alvos);
$saida['contas'] = array_map(fn($c) => ['nome' => $c['account_name'], 'plataforma' => $c['platform'], 'problema' => $estadoConta[$c['id']] ?? null], array_values($contas));
echo json_encode($saida, JSON_UNESCAPED_UNICODE);
