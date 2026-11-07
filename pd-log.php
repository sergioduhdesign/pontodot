<?php
// Pontodot — LEITOR SEGURO do error_log (só o final, agrupado). Protegido pela SD_CRON_KEY. Texto puro, não mexe em nada.
require_once __DIR__ . '/sd-auth.php';
header('Content-Type: text/plain; charset=utf-8'); header('Cache-Control: no-store');
if (!defined('SD_CRON_KEY') || SD_CRON_KEY === '' || !hash_equals((string)SD_CRON_KEY, (string)($_GET['key'] ?? ''))) { http_response_code(403); echo 'chave'; exit; }
$wh = __DIR__ . '/.cache-pd/wa-hits.log';
echo "=== CHAMADAS QUE CHEGARAM NO WEBHOOK (wa-hits.log, últimas 15) ===\n";
if (is_file($wh)) { $l = array_filter(preg_split('/\r?\n/', (string)file_get_contents($wh))); foreach (array_slice($l, -15) as $x) echo $x . "\n"; } else echo "(nenhuma chamada registrada ainda)\n";
echo "\n";
$f = __DIR__ . '/error_log';
if (!is_file($f)) { echo "sem error_log nesta pasta\n"; exit; }
$tam = filesize($f); $le = min($tam, 400 * 1024);
$h = fopen($f, 'rb'); fseek($h, $tam - $le); $txt = (string)fread($h, $le); fclose($h);
$ls = preg_split('/\r?\n/', $txt); if ($tam > $le) array_shift($ls);   // 1ª linha pode estar cortada
echo "error_log: " . round($tam / 1048576, 1) . " MB · lidas as últimas " . count($ls) . " linhas (≈" . round($le / 1024) . " KB)\n";
echo "primeira: " . substr((string)($ls[0] ?? ''), 0, 60) . "\núltima:   " . substr((string)end($ls), 0, 60) . "\n\n";
// agrupa pelo texto SEM data/hora e sem números variáveis
$g = [];
foreach ($ls as $l) { if (trim($l) === '') continue;
    $k = preg_replace('/^\[[^\]]+\]\s*/', '', $l); $k = preg_replace('/\d+/', '#', $k); $k = substr($k, 0, 170);
    $g[$k] = ($g[$k] ?? 0) + 1; }
arsort($g); echo "=== MAIS REPETIDOS ===\n"; $i = 0; foreach ($g as $k => $n) { echo str_pad((string)$n, 6, ' ', STR_PAD_LEFT) . "x  " . $k . "\n"; if (++$i >= 12) break; }
echo "\n=== LINHAS COM wa-webhook / zap / Meta (últimas 15) ===\n";
$z = array_values(array_filter($ls, fn($l) => preg_match('/wa-webhook|zap|whatsapp|meta/i', $l))); foreach (array_slice($z, -15) as $l) echo substr($l, 0, 300) . "\n";
echo "\n=== ÚLTIMAS 12 LINHAS ===\n"; foreach (array_slice($ls, -12) as $l) echo substr($l, 0, 300) . "\n";
