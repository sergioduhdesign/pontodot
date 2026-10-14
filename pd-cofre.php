<?php
// =====================================================================
// Pontodot — O COFRE
//
// Onde as senhas de serviço dos clientes moram (senha de aplicativo do
// WordPress, FTP do site…). A regra que não se negocia:
//   · quem GRAVA é a equipe, por um formulário seguro — nunca pelo chat
//   · quem LÊ o segredo é SÓ o servidor (nem admin vê pelo app)
//   · a resposta NUNCA devolve o segredo — nem na hora de gravar
//
//   POST {acao:"guardar", client_id, servico, rotulo?, usuario?, segredo}
//   POST {acao:"listar",  client_id}         -> sem os segredos
//   POST {acao:"apagar",  client_id, servico} (só admin)
// =====================================================================

require_once __DIR__ . '/sd-auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') sd_fail('Metodo nao permitido', 405);
$me = sd_current_user();
if (!in_array($me['role'], ['admin', 'team'], true)) sd_fail('Só a equipe usa o cofre.', 403);

$d = sd_body();
$acao = $d['acao'] ?? 'listar';
$clientId = (string)($d['client_id'] ?? '');
if (!preg_match('/^[0-9a-fA-F-]{36}$/', $clientId)) sd_fail('Cliente inválido.', 400);
$ok = function ($x) { header('Content-Type: application/json'); echo json_encode(['ok' => true] + $x, JSON_UNESCAPED_UNICODE); exit; };

if ($acao === 'guardar') {
    $servico = strtolower(trim((string)($d['servico'] ?? '')));
    if (!preg_match('/^[a-z0-9_\-]{2,30}$/', $servico)) sd_fail('Serviço inválido (wordpress, ftp…).', 400);
    $segredo = (string)($d['segredo'] ?? '');
    if (strlen($segredo) < 4 || strlen($segredo) > 500) sd_fail('Veio sem a senha.', 400);
    $r = sd_supabase('/rest/v1/pd_credenciais?on_conflict=client_id,servico', 'POST', [
        'client_id' => $clientId, 'servico' => $servico,
        'rotulo' => mb_substr(trim((string)($d['rotulo'] ?? '')), 0, 80) ?: null,
        'usuario' => mb_substr(trim((string)($d['usuario'] ?? '')), 0, 120) ?: null,
        'segredo' => $segredo, 'criado_por' => $me['id'], 'criado_em' => date('c'),
    ], ['Prefer: resolution=merge-duplicates']);
    if ($r['code'] >= 300) sd_fail('Não consegui guardar (rodou o SQL da v62?).', 500);
    $ok(['guardado' => $servico]);
}

if ($acao === 'listar') {
    $r = sd_supabase('/rest/v1/pd_credenciais?client_id=eq.' . urlencode($clientId)
        . '&select=servico,rotulo,usuario,criado_em&order=servico');
    // cinto e suspensório: mesmo que o banco devolva mais, o segredo não sai daqui
    $lista = array_map(fn($c) => ['servico' => $c['servico'] ?? '', 'rotulo' => $c['rotulo'] ?? null,
        'usuario' => $c['usuario'] ?? null, 'criado_em' => $c['criado_em'] ?? null], (array)($r['body'] ?? []));
    $ok(['credenciais' => $lista]);
}

if ($acao === 'apagar') {
    if ($me['role'] !== 'admin') sd_fail('Só admin apaga credencial.', 403);
    $servico = strtolower(trim((string)($d['servico'] ?? '')));
    sd_supabase('/rest/v1/pd_credenciais?client_id=eq.' . urlencode($clientId)
        . '&servico=eq.' . urlencode($servico), 'DELETE');
    $ok(['apagado' => $servico]);
}

sd_fail('Ação desconhecida.', 400);
