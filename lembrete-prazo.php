<?php
// =====================================================================
// SD Workflow — aviso de prazo em 24h
//
// Roda uma vez por dia (Cron Job do cPanel, sugestão: 8h da manhã).
// Acha toda tarefa com prazo interno pra AMANHÃ que ainda não foi
// entregue, e avisa o responsável agora — antes do fim do expediente,
// não de surpresa quando já é tarde.
// =====================================================================

require_once __DIR__ . '/../sd-private/sd-config.php';

if (!defined('SD_CRON_KEY') || SD_CRON_KEY === 'SUA_CRON_KEY_AQUI') {
    http_response_code(500);
    die('SD_CRON_KEY nao configurada no sd-config.php.');
}
if (($_GET['key'] ?? '') !== SD_CRON_KEY) {
    http_response_code(403);
    die('Chave invalida.');
}

header('Content-Type: text/plain; charset=utf-8');

function supa($path, $method, $body = null, $extra = []) {
    $ch = curl_init();
    $headers = array_merge(['apikey: ' . SD_SECRET_KEY, 'Content-Type: application/json'], $extra);
    curl_setopt_array($ch, [
        CURLOPT_URL => SD_SUPABASE_URL . $path, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => json_decode($raw, true), 'raw' => $raw];
}

echo "SD Workflow — aviso de prazo — " . date('c') . "\n";
echo str_repeat('-', 60) . "\n";

// ---------------------------------------------------------------
// PASSO 0: acordar tarefas PROGRAMADAS que chegaram na data.
// agendada_para <= hoje → zera o campo (a tarefa passa a aparecer
// normalmente no app) e avisa o responsável que ela nasceu agora.
// ---------------------------------------------------------------
$hoje = date('Y-m-d');
$rp = supa('/rest/v1/tasks?agendada_para=lte.' . $hoje
    . '&select=id,action,resp,clients(name)', 'GET');
$programadas = ($rp['code'] === 200) ? ($rp['body'] ?? []) : [];
echo count($programadas) . " tarefa(s) programada(s) acordando hoje.\n";

foreach ($programadas as $t) {
    supa('/rest/v1/tasks?id=eq.' . urlencode($t['id']), 'PATCH', ['agendada_para' => null]);
    $p = supa('/rest/v1/profiles?team_key=eq.' . urlencode($t['resp'])
        . '&role=eq.team&active=eq.true&select=id', 'GET');
    foreach (($p['body'] ?? []) as $u) {
        supa('/rest/v1/notifications', 'POST', [
            'user_id' => $u['id'], 'type' => 'tarefa_acordou',
            'title' => '⏰ Tarefa programada chegou: ' . $t['action'],
            'body' => $t['clients']['name'] ?? null, 'link' => 'task:' . $t['id'],
        ]);
    }
    echo "-> acordou: {$t['action']}\n";
}

$amanha = date('Y-m-d', strtotime('+1 day'));

$r = supa('/rest/v1/tasks'
    . '?date_int=eq.' . $amanha
    . '&status=neq.ENTREGUE'
    . '&select=id,action,resp,client_id,clients(name)', 'GET');

if ($r['code'] !== 200) {
    echo "Erro ao consultar tarefas: HTTP {$r['code']}\n" . $r['raw'] . "\n";
    exit;
}

$tarefas = $r['body'] ?? [];
echo count($tarefas) . " tarefa(s) com prazo pra amanhã ($amanha).\n\n";

if (!count($tarefas)) { echo "Nada a avisar.\n"; exit; }

foreach ($tarefas as $t) {
    // resp é uma "chave de time" (SERGIO/JOY/LUANA/JUAN/CLIENTE/OUTROS),
    // não o id de usuário — busca todo mundo daquele time pra avisar.
    $p = supa('/rest/v1/profiles?team_key=eq.' . urlencode($t['resp'])
        . '&role=eq.team&active=eq.true&select=id', 'GET');
    $userIds = array_map(fn($x) => $x['id'], $p['body'] ?? []);

    $cliente = $t['clients']['name'] ?? '';
    $titulo = 'Prazo amanhã: ' . $t['action'];
    $corpo = $cliente ? "Cliente: $cliente" : null;

    foreach ($userIds as $uid) {
        supa('/rest/v1/notifications', 'POST', [
            'user_id' => $uid, 'type' => 'prazo_24h',
            'title' => $titulo, 'body' => $corpo, 'link' => 'task:' . $t['id'],
        ]);
    }
    echo "-> {$t['action']} — avisado(s) " . count($userIds) . " responsável(is)\n";
}

echo "\nConcluido.\n";
