<?php
// Pontodot — o navegador avisa o servidor quando uma tela quebra (erro de JavaScript). Cai na Central de erros (Saúde). Só quem está logado.
require_once __DIR__ . '/sd-auth.php';
header('Content-Type: application/json; charset=utf-8');
$u = sd_current_user(); $b = sd_body();
$GLOBALS['PD_ERRO_QUEM'] = (string)($u['email'] ?? $u['id'] ?? '');
$msg = trim((string)($b['msg'] ?? '')); if ($msg === '') { echo '{"ok":true}'; exit; }
if (preg_match('/ResizeObserver loop|Script error\.?$|Non-Error promise rejection/i', $msg)) { echo '{"ok":true}'; exit; }   // ruído de navegador, não é defeito nosso
pdErro('js', $msg, mb_substr((string)($b['onde'] ?? ''), 0, 160), ['linha' => $b['linha'] ?? null, 'pilha' => mb_substr((string)($b['pilha'] ?? ''), 0, 800), 'versao' => mb_substr((string)($b['versao'] ?? ''), 0, 40), 'tela' => mb_substr((string)($b['tela'] ?? ''), 0, 120), 'agente' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 120)]);
echo '{"ok":true}';
