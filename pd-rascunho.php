<?php
// Rascunho guiado de tarefa nova: a IA propõe assuntos e títulos COM BASE no que a PD já sabe do cliente
// (tarefas recentes, kit de marca). Não gera imagem: só sugere texto. Falhou? o app usa as sugestões locais.
require_once __DIR__ . '/sd-auth.php';
$u = sd_current_user();
if (!in_array($u['role'], ['admin', 'team'], true)) sd_fail('Só a equipe.', 403);
if (!defined('SD_ANTHROPIC_KEY') || !SD_ANTHROPIC_KEY) sd_fail('Sem chave da IA.', 503);
$b = sd_body();
$cid = (string)($b['client_id'] ?? ''); $tipo = mb_substr((string)($b['tipo'] ?? 'peça'), 0, 40);
if (!preg_match('/^[0-9a-f-]{36}$/i', $cid)) sd_fail('Cliente inválido.');
$cli = sd_supabase('/rest/v1/clients?id=eq.' . $cid . '&select=name')['body'][0]['name'] ?? '';
$tk = sd_supabase('/rest/v1/tasks?client_id=eq.' . $cid . '&select=action,project&order=created_at.desc&limit=15')['body'] ?? [];
$kit = sd_supabase('/rest/v1/brand_kits?client_id=eq.' . $cid . '&select=notes')['body'][0]['notes'] ?? '';
$recentes = []; foreach ((array)$tk as $t) if (is_array($t)) $recentes[] = ($t['project'] ?? '') . ' · ' . ($t['action'] ?? '');
$sys = 'Você apoia uma agência de branding do mercado imobiliário. Dado o cliente, o tipo de peça e o histórico de tarefas dele, proponha 4 assuntos DIFERENTES e úteis para a próxima peça, '
     . 'coerentes com o que o cliente já vem fazendo (sem repetir o que acabou de ser feito). Cada um com um título curto de até 7 palavras. '
     . 'Responda SOMENTE JSON: {"sugestoes":[{"assunto":"...","titulo":"..."}]}. Pt-BR. Não invente dados do empreendimento (preço, metragem).';
$msg = json_encode(['cliente' => $cli, 'tipo_de_peca' => $tipo, 'tarefas_recentes' => $recentes, 'notas_da_marca' => mb_substr((string)$kit, 0, 600)], JSON_UNESCAPED_UNICODE);
$base = defined('PD_ANTHROPIC_BASE') ? rtrim(PD_ANTHROPIC_BASE, '/') : 'https://api.anthropic.com';
$ch = curl_init($base . '/v1/messages');
curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40,
    CURLOPT_HTTPHEADER => ['x-api-key: ' . SD_ANTHROPIC_KEY, 'anthropic-version: 2023-06-01', 'content-type: application/json'],
    CURLOPT_POSTFIELDS => json_encode(['model' => 'claude-haiku-4-5', 'max_tokens' => 600, 'system' => $sys, 'messages' => [['role' => 'user', 'content' => $msg]]])]);
$raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
if ($code !== 200) sd_fail('A IA não respondeu agora.', 502);
$t = ''; foreach ((array)(json_decode((string)$raw, true)['content'] ?? []) as $c) if (($c['type'] ?? '') === 'text') $t .= $c['text'];
if (preg_match('/\{.*\}/s', $t, $m)) $t = $m[0];
$j = json_decode($t, true);
if (empty($j['sugestoes'])) sd_fail('Sem sugestões.', 502);
echo json_encode(['sugestoes' => array_slice($j['sugestoes'], 0, 4)], JSON_UNESCAPED_UNICODE);
