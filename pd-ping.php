<?php
// Pontodot — PULSO para o vigia de fora (GitHub Actions: .github/workflows/vigia.yml). Protegido pela mesma SD_CRON_KEY dos crons.
// Devolve só números (minutos desde o último batimento de cada cron) — nada sensível.
require_once __DIR__ . '/sd-auth.php';
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
if (!defined('SD_CRON_KEY') || SD_CRON_KEY === '' || SD_CRON_KEY === 'SUA_CRON_KEY_AQUI') { http_response_code(500); echo '{"error":"SD_CRON_KEY nao configurada"}'; exit; }
if (!hash_equals((string)SD_CRON_KEY, (string)($_GET['key'] ?? ''))) { http_response_code(403); echo '{"error":"chave"}'; exit; }
require_once __DIR__ . '/pd-saude-lib.php'; pdBatimento('vigia');   // o vigia de fora (GitHub) esteve aqui agora: a caixa Saúde mostra
$r = sd_supabase('/rest/v1/pd_batimento?select=chave,ultima_em'); $banco = ($r['code'] ?? 0) === 200;
$m = []; foreach ((array)($r['body'] ?? []) as $b) if (is_array($b) && !empty($b['ultima_em'])) $m[$b['chave']] = (int)round((time() - strtotime($b['ultima_em'])) / 60);
echo json_encode(['ok' => $banco, 'banco' => $banco, 'worker_min' => $m['pd-worker'] ?? null, 'publicador_min' => $m['social-publish-worker'] ?? null, 'zap_entrada_min' => $m['zap_entrada'] ?? null, 'em' => gmdate('c')]);
