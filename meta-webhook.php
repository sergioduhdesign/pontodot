<?php
// =====================================================================
// Pontodot — A META AVISA: chegou lead
//
// Alguém preencheu um formulário de anúncio numa Página conectada. A Meta
// chama este endereço na hora. A gente:
//   1. confere que foi a Meta mesmo (assinatura com o segredo do app);
//   2. busca o lead completo com a chave da Página;
//   3. guarda em meta_leads (o mesmo lead nunca entra duas vezes);
//   4. avisa a equipe no celular/computador (aviso de prazo já ligado).
//
// GET  = a Meta conferindo o endereço (uma vez, na configuração)
// POST = os avisos de verdade
//
// Precisa no sd-config.php:  define('SD_META_WEBHOOK_VERIFY', '...');
// (uma senha qualquer que VOCÊ inventa — a mesma vai no painel da Meta)
// =====================================================================

require_once __DIR__ . '/meta-ads-lib.php';

// ------------------------------------------------- 1) verificação (GET)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $modo = $_GET['hub_mode'] ?? ''; $tok = (string)($_GET['hub_verify_token'] ?? ''); $desafio = (string)($_GET['hub_challenge'] ?? '');
    if ($modo === 'subscribe' && defined('SD_META_WEBHOOK_VERIFY') && SD_META_WEBHOOK_VERIFY !== ''
        && hash_equals(SD_META_WEBHOOK_VERIFY, $tok) && preg_match('/^[A-Za-z0-9_\-]{1,200}$/', $desafio)) {
        header('Content-Type: text/plain'); echo $desafio; exit;
    }
    http_response_code(403); echo 'nao'; exit;
}

// ------------------------------------------------ 2) é a Meta? (POST)
$cru = file_get_contents('php://input');
$assin = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
$esperada = 'sha256=' . hash_hmac('sha256', (string)$cru, SD_META_APP_SECRET);
if (!$assin || !hash_equals($esperada, $assin)) { http_response_code(403); echo 'assinatura'; exit; }

// Responde 200 logo: a Meta reenvia se demorar. O trabalho segue depois.
$corpo = json_decode((string)$cru, true);
http_response_code(200); header('Content-Type: application/json'); echo '{"ok":true}';
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();

if (!is_array($corpo) || ($corpo['object'] ?? '') !== 'page') exit;

// ------------------------------------------------ 3) busca e guarda
$novos = [];
foreach ((array)($corpo['entry'] ?? []) as $ent) {
    foreach ((array)($ent['changes'] ?? []) as $mud) {
        if (($mud['field'] ?? '') !== 'leadgen') continue;
        $v = (array)($mud['value'] ?? []);
        $leadId = preg_replace('/\D/', '', (string)($v['leadgen_id'] ?? ''));
        $pageId = preg_replace('/\D/', '', (string)($v['page_id'] ?? ($ent['id'] ?? '')));
        if (!$leadId || !$pageId) continue;

        // a Página precisa estar conectada: é dela a chave e o cliente
        $c = pd_supa('/rest/v1/social_accounts?platform=eq.facebook&external_account_id=eq.' . urlencode($pageId)
            . '&select=id,client_id,account_name');
        $conta = $c['body'][0] ?? null;
        $tok = null;
        if ($conta) {
            $tk = pd_supa('/rest/v1/social_account_tokens?social_account_id=eq.' . urlencode($conta['id']) . '&select=access_token');
            $tok = $tk['body'][0]['access_token'] ?? null;
        }
        if (!$conta || !$tok) { error_log("Pontodot leads - Página $pageId sem conexão; lead $leadId não buscado"); continue; }

        $r = pd_meta('GET', '/' . $leadId, ['fields' => 'created_time,field_data,ad_id,ad_name,campaign_name,form_id'], $tok, 20);
        if ($r['code'] !== 200) { error_log("Pontodot leads - lead $leadId: " . pd_meta_erro($r)); continue; }
        $L = $r['body'];

        $campos = []; foreach ((array)($L['field_data'] ?? []) as $f) {
            if (!is_array($f)) continue;
            $nomeCampo = mb_substr((string)($f['name'] ?? ''), 0, 80);
            $campos[$nomeCampo] = mb_substr(implode(', ', array_map('strval', (array)($f['values'] ?? []))), 0, 500);
        }
        $acha = function (array $chaves) use ($campos) {
            foreach ($campos as $k => $val) foreach ($chaves as $ch) if (mb_strtolower($k) === $ch) return $val;
            return null;
        };
        $nome = $acha(['full_name', 'nome_completo', 'nome', 'name'])
            ?: trim(($acha(['first_name', 'primeiro_nome']) ?? '') . ' ' . ($acha(['last_name', 'sobrenome']) ?? '')) ?: null;

        $ins = pd_supa('/rest/v1/meta_leads', 'POST', [
            'id' => $leadId, 'page_id' => $pageId, 'form_id' => (string)($L['form_id'] ?? ($v['form_id'] ?? '')),
            'ad_id' => (string)($L['ad_id'] ?? ($v['ad_id'] ?? '')), 'ad_nome' => $L['ad_name'] ?? null,
            'campanha_nome' => $L['campaign_name'] ?? null, 'nome' => $nome,
            'email' => $acha(['email', 'e-mail']), 'telefone' => $acha(['phone_number', 'telefone', 'phone', 'celular', 'whatsapp']),
            'campos' => $campos, 'client_id' => $conta['client_id'] ?? null,
            'criado_na_meta' => isset($L['created_time']) ? gmdate('c', strtotime($L['created_time'])) : null,
        ], ['Prefer: resolution=ignore-duplicates,return=representation']);
        // devolveu linha = é novo (duplicado volta vazio e não avisa de novo)
        if ($ins['code'] < 300 && !empty($ins['body'][0]['id'])) $novos[] = ['nome' => $nome, 'pagina' => $conta['account_name'] ?? 'Página'];
        elseif ($ins['code'] >= 300) error_log("Pontodot leads - gravar $leadId: HTTP " . $ins['code']);
    }
}

// ------------------------------------------------ 4) avisa a equipe
if ($novos && is_file(__DIR__ . '/push-lib.php')) {
    // a push-lib usa sd_supabase(); aqui não tem sessão de usuário, então empresta a do servidor
    if (!function_exists('sd_supabase')) { function sd_supabase($p, $m = 'GET', $b = null, $e = []) { return pd_supa($p, $m, $b, $e) + ['raw' => null]; } }
    require_once __DIR__ . '/push-lib.php';
    if (function_exists('sd_push_usuario')) {
        $eq = pd_supa('/rest/v1/profiles?role=in.(admin,team)&select=id');
        $t = count($novos) === 1 ? 'Lead novo · ' . $novos[0]['pagina'] : count($novos) . ' leads novos';
        $c = count($novos) === 1 ? (($novos[0]['nome'] ?: 'Alguém') . ' acabou de preencher o formulário.') : 'Chegaram agora pelos anúncios.';
        foreach ((array)($eq['body'] ?? []) as $p) if (!empty($p['id'])) @sd_push_usuario($p['id'], $t, $c, '/index.html#leads', 'pd-lead');
    }
}
