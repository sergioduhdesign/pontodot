<?php
// Kiro — a IA da grana (PD Wallet). Só administrador.
// POST { pergunta, historico:[{r:'u'|'k', t}], contexto:"números resumidos da Wallet" } -> { reply }
require_once __DIR__ . '/sd-auth.php';
require_once __DIR__ . '/pd-lib.php';
require_once __DIR__ . '/pd-aula-lib.php';
header('Content-Type: application/json; charset=utf-8');
@set_time_limit(120);
$u = sd_current_user();
if (($u['role'] ?? '') !== 'admin') sd_fail('A Kiro conversa só com o administrador.', 403);
$b = sd_body();
$pergunta = trim((string)($b['pergunta'] ?? '')); if ($pergunta === '') sd_fail('Pergunta vazia.');
$ctx = mb_substr((string)($b['contexto'] ?? ''), 0, 16000);
$hist = ''; foreach (array_slice((array)($b['historico'] ?? []), -10) as $h) { $h = (array)$h; $hist .= (($h['r'] ?? '') === 'u' ? 'SÉRGIO: ' : 'KIRO: ') . mb_substr((string)($h['t'] ?? ''), 0, 2500) . "\n\n"; }
$p = pdPersonagens()['kiro'] ?? ['Kiro', 'Vela', 'Cartógrafo de ideias', 'Estrategista', 'Organiza contexto, dependências e critérios de decisão.'];
$ficha = function_exists('pdFichaCatum') ? pdFichaCatum('kiro') : '';
$sistema = "Você é Kiro " . $p[1] . ", " . mb_strtolower($p[2]) . ", a IA da grana de Sérgio Duh (SD Agência, branding para o mercado imobiliário; sócio da Stratesign). " . $p[4] . "\n" .
"Seu papel: conselheira financeira ESTRATÉGICA para as duas carteiras dele — Casa e Empresa (a Empresa paga uma retirada que alimenta a Casa).\n" .
"Regras: responda em português do Brasil, direta e econômica, sem enrolação. Comece pela conclusão; depois no máximo 3–5 pontos curtos com números. Use SOMENTE os números do contexto; se faltar dado, diga qual falta em vez de inventar. Mostre a conta quando recomendar um valor (ex.: 'sobram ~R$ X por mês → investir Y'). Aponte riscos (concentração em poucos clientes, parcelas à frente, meses negativos, gasto fixo alto). Dê opções com prós e contras, não ordens. Sobre investimentos, fale de categorias e estratégia (reserva de emergência, renda fixa, prazos, liquidez, previdência, reinvestir no negócio) sem prometer retorno nem indicar ativo específico. Impostos e questões contábeis: aponte a dúvida e mande confirmar com o contador (Orlando). Formato: texto corrido curto, com **negrito** só nos números-chave; listas curtas com hífen quando ajudar." . $ficha . "\n\nNÚMEROS ATUAIS DA PD WALLET (R$; mês | entradas | saídas | saldo inicial | retirada | saldo final):\n" . $ctx;
$modelo = defined('SD_GRANA_MODEL') ? SD_GRANA_MODEL : null;
[$txt, $custo, $err] = pdClaude($sistema, ($hist !== '' ? "CONVERSA ATÉ AQUI:\n" . $hist : '') . "SÉRGIO: " . $pergunta, 1800, $modelo);
if ($err || !$txt) sd_fail('A Kiro não conseguiu responder agora (' . ($err ?: 'sem texto') . ').', 502);
echo json_encode(['reply' => $txt, 'custo' => $custo], JSON_UNESCAPED_UNICODE);
