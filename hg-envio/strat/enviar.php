<?php
// Stratesign 2026 — recebe a conversa (5 respostas + contato).
// Guarda cada envio em _briefings/ (pasta fechada ao público). Se existir _config.php
// com STRAT_DESTINO (e-mail), manda também por e-mail. Só responde ok quando gravou.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function sai($code, $arr) { http_response_code($code); echo json_encode($arr, JSON_UNESCAPED_UNICODE); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') sai(405, ['ok' => false, 'erro' => 'método']);
$bruto = file_get_contents('php://input', false, null, 0, 20000);
$d = json_decode($bruto, true);
if (!is_array($d)) sai(400, ['ok' => false, 'erro' => 'formato']);
if (trim((string)($d['site'] ?? '')) !== '') sai(200, ['ok' => true]);          // campo-isca preenchido: robô
$limpa = function ($s, $max) { $s = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', (string)$s)); return mb_substr($s, 0, $max); };
$nome = $limpa($d['nome'] ?? '', 120); $contato = $limpa($d['contato'] ?? '', 160); $empresa = $limpa($d['empresa'] ?? '', 160);
$resp = array_slice(array_map(function ($x) use ($limpa) { return $limpa($x, 1500); }, (array)($d['respostas'] ?? [])), 0, 5);
$perg = array_slice(array_map(function ($x) use ($limpa) { return $limpa($x, 300); }, (array)($d['perguntas'] ?? [])), 0, 5);
if (mb_strlen($nome) < 2 || mb_strlen($contato) < 6 || count($resp) !== 5) sai(422, ['ok' => false, 'erro' => 'faltam dados']);
$dir = __DIR__ . '/_briefings';
if (!is_dir($dir)) @mkdir($dir, 0755, true);
if (!is_file($dir . '/.htaccess')) @file_put_contents($dir . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n");
// no máximo 6 envios por hora por endereço
$ip = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|strat2026');
$trava = $dir . '/.t-' . substr($ip, 0, 24); $agora = time();
$vezes = array_values(array_filter(array_map('intval', @file($trava, FILE_IGNORE_NEW_LINES) ?: []), function ($t) use ($agora) { return $agora - $t < 3600; }));
if (count($vezes) >= 6) sai(429, ['ok' => false, 'erro' => 'muitos envios, tente mais tarde']);
$vezes[] = $agora; @file_put_contents($trava, implode("\n", $vezes));
$reg = ['quando' => date('c'), 'nome' => $nome, 'contato' => $contato, 'empresa' => $empresa, 'perguntas' => $perg, 'respostas' => $resp, 'origem' => $limpa($_SERVER['HTTP_REFERER'] ?? '', 200)];
$arq = $dir . '/briefing-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.json';
if (@file_put_contents($arq, json_encode($reg, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) === false) sai(500, ['ok' => false, 'erro' => 'não gravou']);
if (is_file(__DIR__ . '/_config.php')) { @include __DIR__ . '/_config.php'; }
if (defined('STRAT_DESTINO') && filter_var(STRAT_DESTINO, FILTER_VALIDATE_EMAIL)) {
    $txt = "Nova conversa pelo site\n\nNome: $nome\nContato: $contato\nEmpresa: $empresa\n\n";
    foreach ($resp as $i => $r) $txt .= ($i + 1) . '. ' . ($perg[$i] ?? '') . "\n" . $r . "\n\n";
    @mail(STRAT_DESTINO, '=?UTF-8?B?' . base64_encode('Site Stratesign: conversa de ' . $nome) . '?=', $txt, "Content-Type: text/plain; charset=utf-8\r\nFrom: site@" . preg_replace('/^www\./', '', $_SERVER['HTTP_HOST'] ?? 'stratesign.com.br'));
}
sai(200, ['ok' => true]);
