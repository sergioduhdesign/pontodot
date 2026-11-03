<?php
// Cadastro de pessoa (convite por link) — usado pelo create-user.php (tela) e pela IA da casa (chat/zap).
// Devolve ['user_id'=>..., 'link'=>..., 'team_key'=>...] ou lança Exception com a mensagem pra pessoa.
function pdUsuarioCriar(array $body, array $me) {

$email     = trim($body['email'] ?? '');
$name      = trim($body['name'] ?? '');
$role      = $body['role'] ?? 'team';
$team_key  = $body['team_key'] ?? null;
$funcoes   = array_values(array_unique(array_filter(array_map('strval', (array)($body['funcoes'] ?? [])), fn($x) => isset(pdFuncoesCatalogo()[$x]))));
if ($team_key === null && $role !== 'client') $team_key = 'OUTROS';   // trocada abaixo por um responsável próprio quando há funções
$client_id = $body['client_id'] ?? null;

if (!$email || !$name)                          throw new Exception('Email e nome são obrigatórios.');
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception('Email inválido.');
if (!in_array($role, ['admin','team','client','agent'], true)) throw new Exception('Perfil inválido.');
if ($role === 'client' && !$client_id)          throw new Exception('Escolha a empresa que este usuário representa.');
if ($role !== 'client')                         $client_id = null;
if ($team_key !== null && !preg_match('/^[A-Z][A-Z0-9]{1,19}$/', (string)$team_key)) {
    throw new Exception('Chave de setor inválida.');
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
    throw new Exception($gen['body']['msg'] ?? $gen['body']['message'] ?? $gen['body']['error'] ?? 'Erro ao gerar link');
}

$d = $gen['body'];

// ⚠️ AQUI ESTAVA O BUG: o código antigo lia $d['user']['id'].
// O GoTrue devolve o objeto User no TOPO, junto de action_link.
$newUserId = $d['id'] ?? ($d['user']['id'] ?? null);
$link      = $d['action_link'] ?? ($d['properties']['action_link'] ?? null);
// link com o endereço da Pontodot (o do Supabase fica escondido): a página valida o convite sozinha
$hashed    = $d['hashed_token'] ?? ($d['properties']['hashed_token'] ?? null);
if ($hashed) $link = sd_base() . '/setup.html?token_hash=' . rawurlencode($hashed) . '&type=invite';

if (!$newUserId) throw new Exception('O Supabase não devolveu o ID do usuário criado.');

// --- 2. Criar/atualizar o profile ------------------------------------
if ($team_key && $team_key !== 'OUTROS') pdTeamMemberGarante($team_key);   // profiles.team_key é o mesmo enum: garante antes de gravar
$prof = sd_supabase('/rest/v1/profiles', 'POST', [
    'id'        => $newUserId,
    'name'      => $name,
    'email'     => $email,
    'role'      => $role,
    'team_key'  => $team_key,
    'client_id' => $client_id,
    'active'    => true,
], ['Prefer: resolution=merge-duplicates']);

if ($prof['code'] < 300 && $team_key && $team_key !== 'OUTROS') pdTeamMemberGarante($team_key);
if ($prof['code'] >= 300) {
    error_log('SD create-user: falha ao gravar profile: ' . $prof['raw']);
    throw new Exception('Usuário criado no Auth, mas o perfil não foi salvo. Avise o desenvolvedor.');
}

// --- 2b. Funções (v132) e responsável próprio da pessoa ----------------
if ($role !== 'client') {
    $tk = pdTeamKeyNova($name, $newUserId); pdTeamMemberGarante($tk);
    $fx = sd_supabase('/rest/v1/profiles?id=eq.' . urlencode($newUserId), 'PATCH', ['funcoes' => '{' . implode(',', $funcoes) . '}', 'team_key' => $tk]);
    if (($fx['code'] ?? 500) >= 300) error_log('SD create-user: funções não gravadas (rode o SQL v132): ' . ($fx['raw'] ?? ''));
}

// --- 3. Email de convite ---------------------------------------------
$emailOk = pdConviteEmail($email, $name, $link);


    // --- 2c. Permissões: pessoa nova NÃO entra vazia. Perfil pedido, ou "Produção" pra equipe (admin já tem tudo). ---
    $permTxt = '';
    if ($role === 'team') {
        $tok = $GLOBALS['PD_IA_TOKEN'] ?? '';
        if (!$tok) { $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''); if (!$auth && function_exists('apache_request_headers')) { $hh = array_change_key_case(apache_request_headers(), CASE_LOWER); $auth = $hh['authorization'] ?? ''; } if (preg_match('/^Bearer\s+(.+)$/i', trim($auth), $mm)) $tok = $mm[1]; }
        $quer = trim((string)($body['perfil_permissoes'] ?? '')) ?: 'Produção';
        $pre = (array)(sd_supabase('/rest/v1/permission_presets?papel=eq.team&select=nome,permissoes&order=ordem')['body'] ?? []);
        $norm = fn($x) => strtolower(trim((string)@iconv('UTF-8', 'ASCII//TRANSLIT', (string)$x)));
        $ach = null; foreach ($pre as $x) if ($norm($x['nome'] ?? '') === $norm($quer) || strpos($norm($x['nome'] ?? ''), $norm($quer)) !== false) { $ach = $x; break; }
        if (!$ach && $pre) $ach = $pre[0];
        if ($ach) { [$okP, $msgP] = pdPermissoesServidor($newUserId, array_values((array)($ach['permissoes'] ?? [])), $me['id'] ?? null);
            $permTxt = $okP ? ('perfil ' . $ach['nome']) : ''; if (!$okP) error_log('create-user: permissões padrão não gravadas: ' . $msgP); }
    }
    if ($role !== 'client' && !empty($tk)) $team_key = $tk;
    return ['user_id' => $newUserId, 'link' => $link, 'team_key' => $team_key, 'email' => $email, 'name' => $name, 'permissoes' => $permTxt, 'email_enviado' => $emailOk];
}

// E-mail de convite (capa Catum + logo). Devolve true se o servidor aceitou.
// Configuração do convite (painel admin → "E-mail de convite"): texto, capa, empresa e site. Com padrão quando vazio.
function pdConviteConfig() {
    $st = sd_supabase('/rest/v1/app_settings?id=eq.1&select=convite_texto,convite_capa,empresa_nome,empresa_site')['body'][0] ?? [];
    $empresa = trim((string)($st['empresa_nome'] ?? '')) ?: 'SD Studio Design';
    $site    = trim((string)($st['empresa_site'] ?? '')) ?: 'sergioduh.com';
    $capa    = trim((string)($st['convite_capa'] ?? '')) ?: 'capa-2-jardim-1086.jpg';
    $texto   = trim((string)($st['convite_texto'] ?? '')) ?: "Você foi convidado(a) para a PD, a plataforma de trabalho da Pontodot.ai Escola Agêntica.\n\nA {empresa} gerencia os trabalhos da equipe por aqui — por isso você recebeu este link. Para começar, falta só criar o seu acesso no link abaixo.";
    return ['empresa' => $empresa, 'site' => $site, 'capa' => $capa, 'texto' => $texto];
}
function pdConviteEmail($email, $name, $link) {
    $primeiro = explode(' ', $name)[0];
    $cfg = pdConviteConfig();
    $subject = $primeiro . ', seu acesso à PD está pronto';
    $h = fn($t) => htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8');
    $txt = str_replace(['{nome}', '{empresa}', '{site}'], [$primeiro, $cfg['empresa'], $cfg['site']], $cfg['texto']);
    $paras = array_values(array_filter(array_map('trim', preg_split('/\n\s*\n/u', $txt))));
    $pHtml = implode('', array_map(fn($x) => '<p style="font-size:15px;line-height:1.6;color:#cfcfcf;margin:0 0 16px">' . nl2br($h($x)) . '</p>', $paras));
    $siteUrl = preg_match('#^https?://#', $cfg['site']) ? $cfg['site'] : 'https://' . $cfg['site'];
    $siteTxt = preg_replace('#^https?://(www\.)?#', '', $cfg['site']);
    $html = '<!doctype html><html><body style="margin:0;background:#0b0b0d;font-family:-apple-system,Segoe UI,Helvetica,Arial,sans-serif">'
      . '<div style="max-width:560px;margin:0 auto;padding:28px 0 36px;color:#f2f2f2">'
      . '<div style="border-radius:18px;overflow:hidden;background:#141416">'
      . '<img src="' . $h(sd_base()) . '/pd/escola/' . $h($cfg['capa']) . '" alt="" width="560" style="display:block;width:100%;height:auto">'
      . '<div style="padding:26px 28px 30px">'
      . '<img src="' . $h(sd_base()) . '/assets/email-logo-pontodot.png" alt="pontodot" width="44" height="44" style="display:block;width:44px;height:44px;margin-bottom:20px">'
      . '<h1 style="font-size:24px;font-weight:500;margin:0 0 14px;letter-spacing:-.01em">Oi, ' . $h($primeiro) . '! 👋</h1>'
      . $pHtml
      . '<a href="' . $h($link) . '" style="display:inline-block;background:#fff;color:#0b0b0d;text-decoration:none;font-weight:600;font-size:15px;padding:13px 26px;border-radius:10px;margin-top:6px">Criar meu acesso</a>'
      . '<p style="font-size:12.5px;line-height:1.6;color:#8a8a8a;margin:26px 0 0">O link vale por 24 horas e é só seu. Se o botão não abrir, copie e cole no navegador:<br><span style="word-break:break-all;color:#9a9a9a">' . $h($link) . '</span></p>'
      . '<table width="100%" cellpadding="0" cellspacing="0" style="margin-top:30px;border-top:1px solid #222"><tr>'
      . '<td style="padding-top:16px;font-size:12px;color:#6b6b6b">Pontodot · a escola das IAs agênticas<br><a href="' . $h(sd_base()) . '" style="color:#8a8a8a;text-decoration:none">' . $h(preg_replace('#^https?://#', '', sd_base())) . '</a></td>'
      . '<td align="right" style="padding-top:16px;font-size:12px;color:#6b6b6b;text-align:right">' . $h($cfg['empresa']) . '<br><a href="' . $h($siteUrl) . '" style="color:#8a8a8a;text-decoration:none">' . $h($siteTxt) . '</a></td>'
      . '</tr></table>'
      . '</div></div></div></body></html>';
    $texto = "Oi, $primeiro!\n\n" . $txt . "\n\nCrie seu acesso:\n$link\n\nO link vale por 24 horas e é só seu.\n\nPontodot · " . sd_base() . "\n" . $cfg['empresa'] . " · " . $siteTxt;
    $bnd = 'pd' . bin2hex(random_bytes(8));
    $headers = "From: Pontodot <workflow@sergioduh.com>\r\n"
             . "Reply-To: contato@sergioduh.com\r\n"
             . "MIME-Version: 1.0\r\n"
             . "Content-Type: multipart/alternative; boundary=\"$bnd\"";
    $message = "--$bnd\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($texto))
             . "--$bnd\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html))
             . "--$bnd--";
    $emailOk = (bool)@mail($email, '=?UTF-8?B?' . base64_encode($subject) . '?=', $message, $headers);
    if (!$emailOk) error_log('create-user: mail() recusou o convite para ' . $email);
    return $emailOk;
}
// Novo link de acesso pra quem JÁ existe (e-mail não chegou, link venceu): recovery abre a mesma tela de criar senha.
function pdConviteLink($email, $name) {
    $gen = sd_supabase('/auth/v1/admin/generate_link', 'POST', ['type' => 'recovery', 'email' => $email, 'options' => ['redirectTo' => sd_base() . '/setup.html']]);
    if (($gen['code'] ?? 500) !== 200 || isset($gen['body']['error'])) return ['erro' => (string)($gen['body']['msg'] ?? $gen['body']['message'] ?? $gen['body']['error'] ?? 'HTTP ' . ($gen['code'] ?? '?'))];
    $d = $gen['body']; $hashed = $d['hashed_token'] ?? ($d['properties']['hashed_token'] ?? null);
    $link = $hashed ? sd_base() . '/setup.html?token_hash=' . rawurlencode($hashed) . '&type=recovery' : ($d['action_link'] ?? ($d['properties']['action_link'] ?? null));
    if (!$link) return ['erro' => 'sem link'];
    return ['link' => $link, 'email_enviado' => pdConviteEmail($email, $name, $link)];
}
