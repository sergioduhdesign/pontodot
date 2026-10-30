<?php
require_once __DIR__ . '/sd-base.php';
// =====================================================================
// SD Workflow — criação de usuário (convite por link mágico)
//
// Só ADMIN pode chamar. Corrige o bug histórico da linha 75:
// /auth/v1/admin/generate_link devolve o usuário no NÍVEL DE CIMA
// ($data['id']), não em $data['user']['id'] — por isso o profile
// nunca era criado.
// =====================================================================

require_once __DIR__ . '/sd-auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') sd_fail('Método não permitido', 405);

$me = sd_require_role(['admin']);

$body      = sd_body();
$email     = trim($body['email'] ?? '');
$name      = trim($body['name'] ?? '');
$role      = $body['role'] ?? 'team';
$team_key  = $body['team_key'] ?? null;
$client_id = $body['client_id'] ?? null;

if (!$email || !$name)                          sd_fail('Email e nome são obrigatórios.');
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) sd_fail('Email inválido.');
if (!in_array($role, ['admin','team','client','agent'], true)) sd_fail('Perfil inválido.');
if ($role === 'client' && !$client_id)          sd_fail('Escolha a empresa que este usuário representa.');
if ($role !== 'client')                         $client_id = null;
if ($team_key !== null && !preg_match('/^[A-Z][A-Z0-9]{1,19}$/', (string)$team_key)) {
    sd_fail('Chave de setor inválida.');
}

// --- 1. Gerar o link de convite --------------------------------------
$gen = sd_supabase('/auth/v1/admin/generate_link', 'POST', [
    'type'  => 'invite',
    'email' => $email,
    'options' => [
        'redirectTo' => sd_base() . '/setup.html',
        'data' => ['name' => $name, 'role' => $role, 'team_key' => $team_key],
    ],
]);

if ($gen['code'] !== 200 || isset($gen['body']['error'])) {
    sd_fail($gen['body']['msg'] ?? $gen['body']['message'] ?? $gen['body']['error'] ?? 'Erro ao gerar link', 400);
}

$d = $gen['body'];

// ⚠️ AQUI ESTAVA O BUG: o código antigo lia $d['user']['id'].
// O GoTrue devolve o objeto User no TOPO, junto de action_link.
$newUserId = $d['id'] ?? ($d['user']['id'] ?? null);
$link      = $d['action_link'] ?? ($d['properties']['action_link'] ?? null);

if (!$newUserId) sd_fail('O Supabase não devolveu o ID do usuário criado.', 502);

// --- 2. Criar/atualizar o profile ------------------------------------
$prof = sd_supabase('/rest/v1/profiles', 'POST', [
    'id'        => $newUserId,
    'name'      => $name,
    'email'     => $email,
    'role'      => $role,
    'team_key'  => $team_key,
    'client_id' => $client_id,
    'active'    => true,
], ['Prefer: resolution=merge-duplicates']);

if ($prof['code'] >= 300) {
    error_log('SD create-user: falha ao gravar profile: ' . $prof['raw']);
    sd_fail('Usuário criado no Auth, mas o perfil não foi salvo. Avise o desenvolvedor.', 502);
}

// --- 3. Email de convite ---------------------------------------------
$subject = 'Seu acesso ao Pontodot — workflow';
$message = "Ola, $name!\n\n"
         . "Voce foi convidado(a) para acessar o Pontodot — workflow.\n\n"
         . "Clique no link abaixo para criar sua senha e entrar na plataforma:\n\n"
         . "$link\n\n"
         . "Este link e valido por 24 horas.\n\n"
         . "Pontodot\n" . sd_base();
$headers = "From: workflow@sergioduh.com\r\n"
         . "Reply-To: contato@sergioduh.com\r\n"
         . "Content-Type: text/plain; charset=UTF-8";

@mail($email, $subject, $message, $headers);

echo json_encode(['success' => true, 'link' => $link, 'user_id' => $newUserId], JSON_UNESCAPED_UNICODE);
