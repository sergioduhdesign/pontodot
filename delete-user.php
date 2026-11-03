<?php
// =====================================================================
// SD Workflow — remoção de usuário (painel)
// Só ADMIN. Impede que o admin se remova sozinho.
// Usa o MESMO motor da IA/zap (iaUsuarioExcluirExec): perfil sai de verdade,
// permissões somem, Auth é removido (contornando o 500 por referências).
// =====================================================================

require_once __DIR__ . '/sd-auth.php';
require_once __DIR__ . '/pd-lib.php';
require_once __DIR__ . '/pd-ia-lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') sd_fail('Método não permitido', 405);

$me = sd_require_role(['admin']);

$body       = sd_body();
$userId     = $body['user_id'] ?? '';
$transferTo = $body['transfer_to'] ?? null;

if (!$userId) sd_fail('user_id obrigatório.');
if ($userId === $me['id']) sd_fail('Você não pode remover a própria conta.', 403);
if ($transferTo !== null && $transferTo !== '' && !preg_match('/^[A-Z][A-Z0-9]{1,19}$/', (string)$transferTo)) sd_fail('Responsável de destino inválido.');

$p = sd_supabase('/rest/v1/profiles?id=eq.' . urlencode($userId) . '&select=id,name,email,role,team_key,active')['body'][0] ?? null;
if (!$p) {   // perfil já não existe: garante que o Auth também não
    sd_supabase('/auth/v1/admin/users/' . urlencode($userId), 'DELETE');
    echo json_encode(['success' => true, 'texto' => 'já não existia']); exit;
}

$lote = sprintf('%08x-%04x-4%03x-%04x-%012x', mt_rand(0, 0xffffffff), mt_rand(0, 0xffff), mt_rand(0, 0xfff), mt_rand(0x8000, 0xbfff), mt_rand(0, 0xffffffffffff));
$r = iaUsuarioExcluirExec($p, $me, $lote, $transferTo ?: null);
if (strpos((string)$r['texto'], '⚠️') === 0) sd_fail((string)$r['texto'], 502);

echo json_encode(['success' => true, 'texto' => $r['texto']], JSON_UNESCAPED_UNICODE);
