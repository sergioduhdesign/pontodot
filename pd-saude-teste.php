<?php
// Pontodot — "TESTAR AGORA": confere de verdade, na hora, se cada peça da plataforma responde. Só admin.
// Cada teste devolve {nome, ok, ms, detalhe}. Nada aqui manda mensagem a ninguém nem gasta quase nada (a IA é 1 chamada de 5 tokens).
require_once __DIR__ . '/sd-auth.php';
require_once __DIR__ . '/pd-lib.php';
header('Content-Type: application/json; charset=utf-8');
$u = sd_current_user(); if (($u['role'] ?? '') !== 'admin') sd_fail('Só o admin.', 403);
@set_time_limit(60);
$R = [];
$tempo = function ($nome, $f) use (&$R) { $t = microtime(true); try { [$ok, $det] = $f(); } catch (Throwable $e) { $ok = false; $det = $e->getMessage(); } $R[] = ['nome' => $nome, 'ok' => (bool)$ok, 'ms' => (int)round((microtime(true) - $t) * 1000), 'detalhe' => (string)$det]; };
$http = function ($url, $hdr = [], $corpo = null, $to = 12) { $ch = curl_init($url); $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $to, CURLOPT_HTTPHEADER => $hdr]; if ($corpo !== null) { $o[CURLOPT_POST] = true; $o[CURLOPT_POSTFIELDS] = $corpo; } curl_setopt_array($ch, $o); $r = curl_exec($ch); $c = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $e = curl_error($ch); curl_close($ch); return [$c, json_decode((string)$r, true), (string)$r, $e]; };

$tempo('Banco de dados (Supabase)', function () {
    $r = sd_supabase('/rest/v1/profiles?select=id&limit=1'); $ok = ($r['code'] ?? 0) === 200; return [$ok, $ok ? 'responde' : 'HTTP ' . ($r['code'] ?? 0) . ' ' . mb_substr((string)($r['raw'] ?? ''), 0, 160)]; });

$tempo('Central de erros (tabela pd_erros)', function () {
    $r = sd_supabase('/rest/v1/pd_erros?select=id&limit=1'); $ok = ($r['code'] ?? 0) === 200; return [$ok, $ok ? 'tabela existe' : 'falta rodar o SQL migration_v150_erros.sql no Supabase']; });

$tempo('IA (Anthropic)', function () use ($http) {
    if (!defined('SD_ANTHROPIC_KEY') || SD_ANTHROPIC_KEY === '') return [false, 'sem chave SD_ANTHROPIC_KEY no servidor'];
    $base = defined('PD_ANTHROPIC_BASE') ? rtrim(PD_ANTHROPIC_BASE, '/') : 'https://api.anthropic.com'; $model = defined('SD_IA_CHAT_MODEL') ? SD_IA_CHAT_MODEL : 'claude-sonnet-4-5';
    [$c, $j, $raw, $e] = $http($base . '/v1/messages', ['x-api-key: ' . SD_ANTHROPIC_KEY, 'anthropic-version: 2023-06-01', 'content-type: application/json'], json_encode(['model' => $model, 'max_tokens' => 5, 'messages' => [['role' => 'user', 'content' => 'ok']]]), 25);
    if ($c === 200) return [true, 'responde (modelo ' . $model . ')'];
    $m = $j['error']['message'] ?? ($e ?: mb_substr($raw, 0, 160)); $dica = stripos($m, 'credit') !== false ? ' — SEM CRÉDITO: recarregue em console.anthropic.com' : ($c === 401 ? ' — chave inválida' : ($c === 429 ? ' — limite de uso' : ''));
    return [false, 'HTTP ' . $c . ' ' . $m . $dica]; });

$tempo('WhatsApp (número oficial)', function () use ($http) {
    if (!defined('SD_ZAP_TOKEN') || !defined('SD_ZAP_PHONE_ID')) return [false, 'faltam SD_ZAP_TOKEN / SD_ZAP_PHONE_ID no servidor'];
    $base = (defined('PD_META_BASE') ? PD_META_BASE : 'https://graph.facebook.com/') . 'v21.0';
    [$c, $j, $raw, $e] = $http($base . '/' . rawurlencode(SD_ZAP_PHONE_ID) . '?fields=display_phone_number,verified_name,quality_rating,status', ['Authorization: Bearer ' . SD_ZAP_TOKEN]);
    if ($c === 200 && !empty($j['display_phone_number'])) return [true, ($j['verified_name'] ?? '') . ' · ' . $j['display_phone_number'] . ' · qualidade ' . ($j['quality_rating'] ?? '?') . ' · ' . ($j['status'] ?? '')];
    $m = $j['error']['message'] ?? ($e ?: mb_substr($raw, 0, 160)); $dica = (($j['error']['code'] ?? 0) == 190) ? ' — TOKEN EXPIRADO: gere outro no Meta (usuário do sistema)' : '';
    return [false, 'HTTP ' . $c . ' ' . $m . $dica]; });

$tempo('WhatsApp chegando (webhook)', function () {
    $r = sd_supabase('/rest/v1/pd_batimento?chave=eq.zap_entrada&select=ultima_em&limit=1'); $q = $r['body'][0]['ultima_em'] ?? null;
    if (!$q) return [false, 'nunca chegou nada pelo webhook desde que este painel existe — mande uma mensagem pro número e teste de novo'];
    $min = (int)round((time() - strtotime($q)) / 60); $ok = $min < 1440;
    return [$ok, 'última entrada há ' . ($min < 60 ? $min . ' min' : round($min / 60, 1) . ' h') . ($ok ? '' : ' — faz mais de 1 dia sem chegar nada')]; });

$tempo('Relógio da casa (cron do servidor)', function () {
    $r = sd_supabase('/rest/v1/pd_batimento?chave=eq.pd-worker&select=ultima_em&limit=1'); $q = $r['body'][0]['ultima_em'] ?? null;
    if (!$q) return [false, 'nunca bateu — o cron do cPanel não está rodando pd-worker.php'];
    $min = (int)round((time() - strtotime($q)) / 60); return [$min <= 15, 'rodou há ' . $min . ' min' . ($min <= 15 ? '' : ' — PARADO (esperado a cada 5 min)')]; });

$tempo('Ponte do Drive', function () use ($http) {
    if (!defined('SD_PONTE_URL') || SD_PONTE_URL === '') return [false, 'sem SD_PONTE_URL no servidor'];
    [$c, , , $e] = $http(SD_PONTE_URL, [], null, 8); return [$c > 0 && $c < 500, $c ? 'responde (HTTP ' . $c . ')' : ('não alcança: ' . $e)]; });

$tempo('Servidor (PHP e disco)', function () {
    $liv = @disk_free_space(__DIR__); $tot = @disk_total_space(__DIR__); $pct = ($liv && $tot) ? round($liv / $tot * 100) : null;
    return [$pct === null || $pct > 8, 'PHP ' . PHP_VERSION . ' · memória ' . ini_get('memory_limit') . ($pct !== null ? ' · disco livre ' . $pct . '% (' . round($liv / 1073741824, 1) . ' GB)' : '')]; });

echo json_encode(['testes' => $R, 'em' => gmdate('c')], JSON_UNESCAPED_UNICODE);
