<?php
// Retrospectiva — dados do BI.
//  • Com ?t=<token> (ou {t}): o cliente vê SÓ o dele, sem login.
//  • Logado (Authorization): admin/equipe veem qualquer cliente; cliente vê o seu.
// Ações (POST JSON): ver · lista · freq (token) · config · gerar · zap (admin)
require_once __DIR__ . '/sd-auth.php';
require_once __DIR__ . '/retro-lib.php';

$b = sd_body();
$t = preg_replace('/[^a-f0-9]/', '', (string)($_GET['t'] ?? $b['t'] ?? ''));
$acao = (string)($b['acao'] ?? 'ver');
$u = null; $clientId = null; $admin = false; $equipe = false;

if ($t !== '') {
    $c = pd_supa('/rest/v1/pd_retro_config?token=eq.' . $t . '&ativo=eq.true&select=client_id');
    if (empty($c['body'][0]['client_id'])) sd_fail('Link inválido ou desativado.', 404);
    $clientId = $c['body'][0]['client_id'];
    if (!in_array($acao, ['ver', 'freq'], true)) sd_fail('Ação indisponível por link.', 403);
} else {
    $u = sd_current_user();
    $admin = $u['role'] === 'admin'; $equipe = $admin || $u['role'] === 'team';
    $clientId = $equipe ? (string)($b['client_id'] ?? '') : (string)($u['client_id'] ?? '');
}

function retro_cfg_garante($clientId) {
    $c = pd_supa('/rest/v1/pd_retro_config?client_id=eq.' . urlencode($clientId) . '&select=*');
    if (!empty($c['body'][0])) return $c['body'][0];
    $n = pd_supa('/rest/v1/pd_retro_config', 'POST', ['client_id' => $clientId], ['Prefer: return=representation']);
    return $n['body'][0] ?? null;
}
function retro_cfg_publica($c) { return ['segmento' => $c['segmento'], 'bi_freq' => $c['bi_freq'], 'ativo' => $c['ativo'], 'zap_dia' => $c['zap_dia'], 'zap_hora' => $c['zap_hora'],
    'enviar_cliente' => $c['enviar_cliente'], 'destinatarios' => $c['destinatarios'], 'ultimo_zap_em' => $c['ultimo_zap_em'], 'token' => $c['token']]; }

if ($acao === 'lista') {
    if (!$equipe) sd_fail('Só a equipe.', 403);
    $cl = pd_supa('/rest/v1/clients?select=id,name&order=name.asc&limit=300');
    $cf = pd_supa('/rest/v1/pd_retro_config?select=client_id,ativo');
    $at = []; foreach ((array)($cf['body'] ?? []) as $x) if (is_array($x)) $at[$x['client_id']] = $x['ativo'];
    $out = []; foreach ((array)($cl['body'] ?? []) as $x) if (is_array($x)) $out[] = ['id' => $x['id'], 'nome' => $x['name'], 'config' => isset($at[$x['id']]) ? (bool)$at[$x['id']] : null];
    echo json_encode(['clientes' => $out], JSON_UNESCAPED_UNICODE); exit;
}
if ($clientId === '') sd_fail('Escolha um cliente.', 400);

if ($acao === 'freq') {
    $f = (string)($b['bi_freq'] ?? ''); if (!in_array($f, ['diario', 'semanal'], true)) sd_fail('Frequência inválida.');
    pd_supa('/rest/v1/pd_retro_config?client_id=eq.' . urlencode($clientId), 'PATCH', ['bi_freq' => $f]);
    echo json_encode(['ok' => true, 'bi_freq' => $f]); exit;
}

$cfg = retro_cfg_garante($clientId);
if (!$cfg) sd_fail('Rode o SQL v110 no Supabase.', 500);

if ($acao === 'config') {
    if (!$admin) sd_fail('Só admin.', 403);
    $p = [];
    if (isset($b['segmento']) && isset(RETRO_SEGMENTOS[$b['segmento']])) $p['segmento'] = $b['segmento'];
    if (isset($b['bi_freq']) && in_array($b['bi_freq'], ['diario', 'semanal'], true)) $p['bi_freq'] = $b['bi_freq'];
    if (isset($b['zap_dia'])) $p['zap_dia'] = max(1, min(7, (int)$b['zap_dia']));
    if (isset($b['zap_hora'])) $p['zap_hora'] = max(0, min(23, (int)$b['zap_hora']));
    if (isset($b['ativo'])) $p['ativo'] = (bool)$b['ativo'];
    if (isset($b['enviar_cliente'])) $p['enviar_cliente'] = (bool)$b['enviar_cliente'];
    if (isset($b['destinatarios'])) $p['destinatarios'] = array_values(array_filter(array_map('pdZapNormaliza', (array)$b['destinatarios'])));
    if ($p) pd_supa('/rest/v1/pd_retro_config?client_id=eq.' . urlencode($clientId), 'PATCH', $p);
    $cfg = retro_cfg_garante($clientId);
    echo json_encode(['ok' => true, 'config' => retro_cfg_publica($cfg)], JSON_UNESCAPED_UNICODE); exit;
}
if ($acao === 'gerar') {
    if (!$equipe) sd_fail('Só a equipe.', 403);
    $s = retro_gerar($clientId, 'semanal', true);
    if (!$s) sd_fail('Não consegui gerar agora.', 502);
    echo json_encode(['ok' => true, 'fim' => $s['fim']]); exit;
}
if ($acao === 'zap') {
    if (!$admin) sd_fail('Só admin.', 403);
    $r = retro_rodar($clientId, $u['id']);
    echo json_encode($r, JSON_UNESCAPED_UNICODE); exit;
}

// ver: o último relatório (diário se o cliente escolheu diário e já existe), mais a evolução das últimas semanas
$tipo = ($cfg['bi_freq'] === 'diario') ? 'diario' : 'semanal';
$s = pd_supa('/rest/v1/pd_retro_snaps?client_id=eq.' . urlencode($clientId) . '&tipo=eq.' . $tipo . '&order=fim.desc&limit=1&select=ini,fim,dados,criado_em');
$snap = $s['body'][0] ?? null;
if (!$snap && $tipo === 'diario') { $s = pd_supa('/rest/v1/pd_retro_snaps?client_id=eq.' . urlencode($clientId) . '&tipo=eq.semanal&order=fim.desc&limit=1&select=ini,fim,dados,criado_em'); $snap = $s['body'][0] ?? null; }
$h = pd_supa('/rest/v1/pd_retro_snaps?client_id=eq.' . urlencode($clientId) . '&tipo=eq.semanal&order=fim.desc&limit=8&select=fim,dados');
$hist = [];
foreach (array_reverse((array)($h['body'] ?? [])) as $x) { $a = $x['dados']['ads']['atual'] ?? null; if ($a) $hist[] = ['fim' => $x['fim'], 'gasto' => $a['gasto'], 'leads' => $a['leads'], 'cpl' => $a['cpl']]; }
$cli = pd_supa('/rest/v1/clients?id=eq.' . urlencode($clientId) . '&select=name')['body'][0]['name'] ?? '';
$out = ['cliente' => $cli, 'snap' => $snap, 'historico' => $hist, 'bi_freq' => $cfg['bi_freq'], 'pode_admin' => $admin, 'pode_equipe' => $equipe];
if ($admin) { $out['config'] = retro_cfg_publica($cfg); $out['link'] = retro_links($cfg['token'], $snap['fim'] ?? '')['bi']; }
echo json_encode($out, JSON_UNESCAPED_UNICODE);
