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
require_once __DIR__ . '/pd-lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') sd_fail('Método não permitido', 405);

$me = sd_require_role(['admin']);

$body      = sd_body();
$email     = trim($body['email'] ?? '');
$name      = trim($body['name'] ?? '');
$role      = $body['role'] ?? 'team';
$team_key  = $body['team_key'] ?? null;
$funcoes   = array_values(array_unique(array_filter(array_map('strval', (array)($body['funcoes'] ?? [])), fn($x) => isset(pdFuncoesCatalogo()[$x]))));
if ($team_key === null && $role !== 'client') $team_key = 'OUTROS';   // trocada abaixo por um responsável próprio quando há funções
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
// link com o endereço da Pontodot (o do Supabase fica escondido): a página valida o convite sozinha
$hashed    = $d['hashed_token'] ?? ($d['properties']['hashed_token'] ?? null);
if ($hashed) $link = sd_base() . '/setup.html?token_hash=' . rawurlencode($hashed) . '&type=invite';

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

// --- 2b. Funções (v132) e responsável próprio da pessoa ----------------
if ($role !== 'client' && $funcoes) {
    $tk = pdTeamKeyNova($name, $newUserId);
    $fx = sd_supabase('/rest/v1/profiles?id=eq.' . urlencode($newUserId), 'PATCH', ['funcoes' => '{' . implode(',', $funcoes) . '}', 'team_key' => $tk]);
    if (($fx['code'] ?? 500) >= 300) error_log('SD create-user: funções não gravadas (rode o SQL v132): ' . ($fx['raw'] ?? ''));
}

// --- 3. Email de convite ---------------------------------------------
$primeiro = explode(' ', $name)[0];
$subject = $primeiro . ', seu acesso à Pontodot está pronto';
$h = fn($t) => htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8');
$html = '<!doctype html><html><body style="margin:0;background:#0b0b0d;font-family:-apple-system,Segoe UI,Helvetica,Arial,sans-serif">'
  . '<div style="max-width:520px;margin:0 auto;padding:36px 24px;color:#f2f2f2">'
  . '<img src="' . $h(sd_base()) . '/assets/logo-pontodot-white.svg" alt="pontodot" height="30" style="display:block;margin-bottom:28px">'
  . '<h1 style="font-size:24px;font-weight:500;margin:0 0 14px;letter-spacing:-.01em">Oi, ' . $h($primeiro) . '! 👋</h1>'
  . '<p style="font-size:15px;line-height:1.6;color:#cfcfcf;margin:0 0 22px">Você foi convidado(a) para a <b style="color:#fff">Pontodot</b>, a plataforma onde a gente organiza o trabalho, as tarefas e as aprovações. Falta só criar a sua senha.</p>'
  . '<a href="' . $h($link) . '" style="display:inline-block;background:#fff;color:#0b0b0d;text-decoration:none;font-weight:600;font-size:15px;padding:13px 26px;border-radius:10px">Criar minha senha e entrar</a>'
  . '<p style="font-size:12.5px;line-height:1.6;color:#8a8a8a;margin:26px 0 0">O link vale por 24 horas e é só seu. Se o botão não abrir, copie e cole no navegador:<br><span style="word-break:break-all;color:#9a9a9a">' . $h($link) . '</span></p>'
  . '<p style="font-size:12px;color:#6b6b6b;margin:30px 0 0;border-top:1px solid #222;padding-top:16px">Pontodot · ' . $h(preg_replace('#^https?://#', '', sd_base())) . '</p>'
  . '</div></body></html>';
$texto = "Oi, $primeiro!\n\nVocê foi convidado(a) para a Pontodot, a plataforma onde a gente organiza o trabalho, as tarefas e as aprovações.\n\nCrie sua senha e entre:\n$link\n\nO link vale por 24 horas e é só seu.\n\nPontodot · " . sd_base();
$bnd = 'pd' . bin2hex(random_bytes(8));
$headers = "From: Pontodot <workflow@sergioduh.com>\r\n"
         . "Reply-To: contato@sergioduh.com\r\n"
         . "MIME-Version: 1.0\r\n"
         . "Content-Type: multipart/alternative; boundary=\"$bnd\"";
$message = "--$bnd\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($texto))
         . "--$bnd\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html))
         . "--$bnd--";

@mail($email, '=?UTF-8?B?' . base64_encode($subject) . '?=', $message, $headers);

echo json_encode(['success' => true, 'link' => $link, 'user_id' => $newUserId], JSON_UNESCAPED_UNICODE);
