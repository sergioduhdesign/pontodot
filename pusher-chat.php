<?php
// =====================================================================
// SD Workflow — Pusher Channels (chat)
//
// MUDANÇA IMPORTANTE: os canais agora são PRIVADOS (prefixo "private-").
// Antes eram públicos, o que permitia a qualquer pessoa com a chave
// pública do Pusher assinar o canal de qualquer usuário e ler o chat.
//
// action=auth : assina o canal SOMENTE se o usuário logado tiver direito
// action=send : publica no canal SOMENTE se o usuário logado tiver direito
// =====================================================================

require_once __DIR__ . '/sd-auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') sd_fail('Método não permitido', 405);

$me     = sd_current_user();
$body   = sd_body();
$action = $body['action'] ?? '';

/**
 * Decide se $me pode usar $channel.
 *  - private-chat-user-<uuid>  : só o dono do canal ou quem escreve para ele
 *  - private-chat-group-sd     : qualquer usuário autenticado (o cliente
 *                                participa do grupo, conforme regra atual)
 */
function sd_channel_allowed($channel, $me, $forSending) {
    if ($channel === 'private-chat-group-sd') {
        return true;
    }
    if (preg_match('/^private-chat-user-([0-9a-fA-F-]{36})$/', $channel, $m)) {
        $target = $m[1];
        // Assinar: só o próprio canal.
        if (!$forSending) return $target === $me['id'];
        // Enviar: qualquer usuário autenticado pode escrever para outro
        // (o conteúdo já é validado pelo RLS da tabela `messages`).
        return true;
    }
    return false;
}

// ---------------------------------------------------------------------
if ($action === 'auth') {
    $socketId = $body['socket_id']    ?? '';
    $channel  = $body['channel_name'] ?? '';

    if (!preg_match('/^\d+\.\d+$/', $socketId)) sd_fail('socket_id inválido.');
    if (!sd_channel_allowed($channel, $me, false)) {
        sd_fail('Você não tem acesso a este canal.', 403);
    }

    $signature = hash_hmac('sha256', $socketId . ':' . $channel, SD_PUSHER_SECRET);
    echo json_encode(['auth' => SD_PUSHER_KEY . ':' . $signature]);
    exit;
}

// ---------------------------------------------------------------------
if ($action === 'send') {
    $channel = $body['channel'] ?? '';
    $event   = 'message';                 // travado: nada de evento arbitrário
    $data    = $body['data'] ?? [];

    if (!sd_channel_allowed($channel, $me, true)) {
        sd_fail('Você não pode publicar neste canal.', 403);
    }

    // O remetente é sempre quem está logado — não aceitamos o que veio no body.
    $payloadData = [
        'id'          => $data['id'] ?? null,
        'sender_id'   => $me['id'],
        'sender_name' => $me['name'],
        'body'        => mb_substr((string)($data['body'] ?? ''), 0, 2000),
        'created_at'  => date('c'),
    ];

    $path      = '/apps/' . SD_PUSHER_APP_ID . '/events';
    $timestamp = time();
    $payload   = json_encode([
        'name'    => $event,
        'channel' => $channel,
        'data'    => json_encode($payloadData, JSON_UNESCAPED_UNICODE),
    ], JSON_UNESCAPED_UNICODE);
    $md5body   = md5($payload);

    $query = http_build_query([
        'auth_key'       => SD_PUSHER_KEY,
        'auth_timestamp' => $timestamp,
        'auth_version'   => '1.0',
        'body_md5'       => $md5body,
    ]);
    $authSig = hash_hmac('sha256', "POST\n$path\n$query", SD_PUSHER_SECRET);

    $url = 'https://api-' . SD_PUSHER_CLUSTER . '.pusher.com' . $path
         . '?' . $query . '&auth_signature=' . $authSig;

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200) error_log('SD pusher-chat send: ' . $code . ' ' . $res);

    echo json_encode(['success' => $code === 200]);
    exit;
}

sd_fail('Ação inválida.');
