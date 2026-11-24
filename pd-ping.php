<?php
// Pontodot — PULSO para o vigia de fora (GitHub Actions: .github/workflows/vigia.yml). Protegido pela mesma SD_CRON_KEY dos crons.
// Devolve só números (minutos desde o último batimento de cada cron) — nada sensível.
require_once __DIR__ . '/sd-auth.php';
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
if (!defined('SD_CRON_KEY') || SD_CRON_KEY === '' || SD_CRON_KEY === 'SUA_CRON_KEY_AQUI') { http_response_code(500); echo '{"error":"SD_CRON_KEY nao configurada"}'; exit; }
if (!hash_equals((string)SD_CRON_KEY, (string)($_GET['key'] ?? ''))) { http_response_code(403); echo '{"error":"chave"}'; exit; }
// ?arquivos=1 -> conta os arquivos da conta inteira (o plano Turbo do HostGator trava o FTP/deploy acima de 500.000). Cache de 30 min.
if (!empty($_GET['arquivos'])) {
    @set_time_limit(150); $cf = __DIR__ . '/../sd-private/arquivos-conta.json'; $home = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
    $c = is_file($cf) ? json_decode((string)@file_get_contents($cf), true) : null;
    if (!is_array($c) || (time() - (int)($c['t'] ?? 0)) > 1800) {
        $n = null; $metodo = '';
        if (function_exists('exec') && stripos((string)ini_get('disable_functions'), 'exec') === false) { $o = []; @exec('find ' . escapeshellarg($home) . ' -xdev 2>/dev/null | wc -l', $o); if (isset($o[0]) && ctype_digit(trim($o[0]))) { $n = (int)trim($o[0]); $metodo = 'find'; } }
        if ($n === null) { $n = 0; $metodo = 'php'; try { $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($home, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST, RecursiveIteratorIterator::CATCH_GET_CHILD); foreach ($it as $_) $n++; } catch (Throwable $e) {} }
        $c = ['t' => time(), 'arquivos' => $n, 'metodo' => $metodo]; @file_put_contents($cf, json_encode($c));
    }
    echo json_encode(['ok' => true, 'arquivos' => (int)$c['arquivos'], 'limite' => 500000, 'metodo' => $c['metodo'] ?? '', 'idade_min' => (int)round((time() - (int)$c['t']) / 60)]); exit;
}
require_once __DIR__ . '/pd-saude-lib.php'; pdBatimento('vigia');   // o vigia de fora (GitHub) esteve aqui agora: a caixa Saúde mostra
$r = sd_supabase('/rest/v1/pd_batimento?select=chave,ultima_em'); $banco = ($r['code'] ?? 0) === 200;
$m = []; foreach ((array)($r['body'] ?? []) as $b) if (is_array($b) && !empty($b['ultima_em'])) $m[$b['chave']] = (int)round((time() - strtotime($b['ultima_em'])) / 60);
echo json_encode(['ok' => $banco, 'banco' => $banco, 'worker_min' => $m['pd-worker'] ?? null, 'publicador_min' => $m['social-publish-worker'] ?? null, 'zap_entrada_min' => $m['zap_entrada'] ?? null, 'em' => gmdate('c')]);
