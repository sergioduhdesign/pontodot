<?php
// =====================================================================
// SD Workflow — camada de autenticação dos endpoints
//
// Todo endpoint PHP inclui este arquivo. Ele:
//   1. Aplica CORS restrito (só o domínio do app)
//   2. Lê o access token da sessão enviado pelo app
//   3. Valida o token contra o Supabase (/auth/v1/user)
//   4. Carrega o profile (role, team_key, client_id) do banco
//   5. Disponibiliza sd_require_role() para travar por papel
//
// Sem token válido, o endpoint responde 401 e nada acontece.
// =====================================================================

require_once __DIR__ . '/../sd-private/sd-config.php';

// --- CORS restrito ---------------------------------------------------
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $SD_ALLOWED_ORIGINS, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// --- Helpers ---------------------------------------------------------

function sd_fail($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Chamada HTTP ao Supabase usando a chave secreta.
 * IMPORTANTE: as chaves novas (sb_secret_...) NÃO são JWT e por isso
 * vão apenas no cabeçalho `apikey` — nunca em `Authorization: Bearer`.
 */
function sd_supabase($path, $method = 'GET', $body = null, $extraHeaders = []) {
    $ch = curl_init();
    $headers = array_merge([
        'apikey: ' . SD_SECRET_KEY,
        'Content-Type: application/json',
    ], $extraHeaders);

    curl_setopt_array($ch, [
        CURLOPT_URL            => SD_SUPABASE_URL . $path,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 20,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body));
    }
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['code' => $code, 'body' => json_decode($raw, true), 'raw' => $raw];
}

/** Lê o corpo JSON da requisição. */
function sd_body() {
    static $cached = null;
    if ($cached === null) {
        $cached = json_decode(file_get_contents('php://input'), true) ?: [];
    }
    return $cached;
}

/**
 * Valida o access token do usuário e devolve o profile dele.
 * O token vem no cabeçalho `Authorization: Bearer <access_token>`,
 * que é o token de SESSÃO do usuário (não a chave secreta).
 */
function sd_current_user() {
    static $cached = null;
    if ($cached !== null) return $cached;

    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (!$auth && function_exists('apache_request_headers')) {
        $h = array_change_key_case(apache_request_headers(), CASE_LOWER);
        $auth = $h['authorization'] ?? '';
    }
    if (!preg_match('/^Bearer\s+(.+)$/i', trim($auth), $m)) {
        sd_fail('Sessão ausente. Faça login novamente.', 401);
    }
    $token = $m[1];

    // 1) O Supabase valida a assinatura e a validade do token
    $r = sd_supabase('/auth/v1/user', 'GET', null, ['Authorization: Bearer ' . $token]);
    if ($r['code'] !== 200 || empty($r['body']['id'])) {
        sd_fail('Sessão inválida ou expirada. Faça login novamente.', 401);
    }
    $userId = $r['body']['id'];

    // 2) Carregamos o papel a partir do banco (nunca do que o cliente enviou)
    $p = sd_supabase(
        '/rest/v1/profiles?id=eq.' . urlencode($userId) .
        '&select=id,name,email,role,team_key,client_id,active'
    );
    if ($p['code'] !== 200 || empty($p['body'][0])) {
        sd_fail('Perfil não encontrado.', 403);
    }
    $profile = $p['body'][0];

    if (isset($profile['active']) && $profile['active'] === false) {
        sd_fail('Usuário desativado.', 403);
    }

    return $cached = $profile;
}

/** Exige que o usuário logado tenha um dos papéis informados. */
function sd_require_role(array $roles) {
    $u = sd_current_user();
    if (!in_array($u['role'], $roles, true)) {
        sd_fail('Você não tem permissão para esta ação.', 403);
    }
    return $u;
}
