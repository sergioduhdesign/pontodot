<?php
// Funções da equipe: o admin (ou quem tem a função "gestao_equipe") escolhe as funções de cada pessoa.
// POST { acao: catalogo | salvar{user_id,funcoes[]} | acesso{user_id,ativo} }
require_once __DIR__ . '/sd-auth.php';
require_once __DIR__ . '/pd-lib.php';
header('Content-Type: application/json; charset=utf-8');
$me = sd_current_user(); $b = sd_body(); $acao = (string)($b['acao'] ?? 'catalogo');
$eu = sd_supabase('/rest/v1/profiles?id=eq.' . urlencode($me['id']) . '&select=funcoes')['body'][0]['funcoes'] ?? [];
$gestor = $me['role'] === 'admin' || in_array('gestao_equipe', (array)$eu, true);
if (!$gestor) sd_fail('Só admin ou gestão de equipe.', 403);
$cat = pdFuncoesCatalogo();
if ($acao === 'catalogo') { echo json_encode(['ok' => true, 'catalogo' => $cat, 'eu_admin' => $me['role'] === 'admin'], JSON_UNESCAPED_UNICODE); exit; }
$uid = (string)($b['user_id'] ?? ''); if ($uid === '') sd_fail('user_id obrigatório.');
$alvo = sd_supabase('/rest/v1/profiles?id=eq.' . urlencode($uid) . '&select=id,name,role,team_key,funcoes')['body'][0] ?? null;
if (!$alvo) sd_fail('Pessoa não encontrada.', 404);
if ($acao === 'salvar') {
    if ($alvo['role'] === 'client') sd_fail('Função é só para a equipe.');
    $f = array_values(array_unique(array_filter(array_map('strval', (array)($b['funcoes'] ?? [])), fn($x) => isset($cat[$x]))));
    // só o admin mexe em "gestao_equipe" (gestor não promove gestor)
    $tinha = in_array('gestao_equipe', (array)($alvo['funcoes'] ?? []), true);
    if ($me['role'] !== 'admin') { $f = array_values(array_diff($f, ['gestao_equipe'])); if ($tinha) $f[] = 'gestao_equipe'; }
    $upd = ['funcoes' => '{' . implode(',', $f) . '}'];
    // quem ainda não tem um "responsável" próprio ganha um (aparece no quadro com o nome dele)
    if (!empty($f) && (empty($alvo['team_key']) || $alvo['team_key'] === 'OUTROS')) $upd['team_key'] = pdTeamKeyNova($alvo['name'], $alvo['id']);
    $r = sd_supabase('/rest/v1/profiles?id=eq.' . urlencode($uid), 'PATCH', $upd);
    if (($r['code'] ?? 500) >= 300) sd_fail('Rode o SQL v132 no Supabase.', 500);
    echo json_encode(['ok' => true, 'funcoes' => $f, 'team_key' => $upd['team_key'] ?? $alvo['team_key']], JSON_UNESCAPED_UNICODE); exit;
}
if ($acao === 'acesso') {
    if ($uid === $me['id']) sd_fail('Você não pode mudar o próprio acesso.', 403);
    if ($alvo['role'] === 'admin' && $me['role'] !== 'admin') sd_fail('Só admin mexe no acesso de admin.', 403);
    $ativo = !empty($b['ativo']);
    sd_supabase('/rest/v1/profiles?id=eq.' . urlencode($uid), 'PATCH', ['active' => $ativo]);
    $g = sd_supabase('/auth/v1/admin/users/' . urlencode($uid), 'PUT', ['ban_duration' => $ativo ? 'none' : '876000h']);
    echo json_encode(['ok' => true, 'ativo' => $ativo, 'auth' => ($g['code'] ?? 0) < 300]); exit;
}
sd_fail('Ação desconhecida.');
