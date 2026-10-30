<?php
// =====================================================================
// SD Workflow — remoção de usuário
// Só ADMIN. Impede que o admin se remova sozinho.
// =====================================================================

require_once __DIR__ . '/sd-auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') sd_fail('Método não permitido', 405);

$me = sd_require_role(['admin']);

$body       = sd_body();
$userId     = $body['user_id'] ?? '';
$transferTo = $body['transfer_to'] ?? null;

if (!$userId) sd_fail('user_id obrigatório.');
if ($userId === $me['id']) sd_fail('Você não pode remover a própria conta.', 403);

if ($transferTo !== null && !preg_match('/^[A-Z][A-Z0-9]{1,19}$/', (string)$transferTo)) {
    sd_fail('Responsável de destino inválido.');
}

// --- 1. Descobrir o setor do usuário ---------------------------------
$p = sd_supabase('/rest/v1/profiles?id=eq.' . urlencode($userId) . '&select=team_key,name');
$userTeamKey = $p['body'][0]['team_key'] ?? null;

// --- 2. Reatribuir tarefas, se pedido --------------------------------
if ($transferTo && $userTeamKey) {
    sd_supabase(
        '/rest/v1/tasks?resp=eq.' . urlencode($userTeamKey),
        'PATCH',
        ['resp' => $transferTo]
    );
}

// --- 3. Soft delete no profile ---------------------------------------
sd_supabase('/rest/v1/profiles?id=eq.' . urlencode($userId), 'PATCH', ['active' => false]);

// --- 4. Remover do Auth ----------------------------------------------
$del = sd_supabase('/auth/v1/admin/users/' . urlencode($userId), 'DELETE');

// 404 = já não existe no Auth (só no profiles). Tudo bem.
if (!in_array($del['code'], [200, 204, 404], true)) {
    sd_fail('Erro ao remover o usuário do Auth (código ' . $del['code'] . ').', 502);
}

echo json_encode(['success' => true]);
