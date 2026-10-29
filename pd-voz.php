<?php
// Pontodot — LOCUÇÃO premium, na hora (OpenAI gpt-4o-mini-tts, pt-BR natural). Precisa de SD_OPENAI_KEY no sd-config.php.
// Entrega: MP3 no Storage (bucket "pecas", público) + cópia em cliente › MÍDIA › ÁUDIO no Drive + registro em pd_trabalhos (módulo voz, pronto)
// pra aparecer em Saída de materiais e na tela de Locução. A oficina (Kokoro) continua como alternativa gratuita.
require_once __DIR__ . '/sd-auth.php';
require_once __DIR__ . '/pd-lib.php';
header('Content-Type: application/json; charset=utf-8');
@set_time_limit(180);
$u = sd_current_user(); if (!in_array($u['role'], ['admin', 'team'], true)) sd_fail('Só a equipe.', 403);
$b = sd_body(); $acao = (string)($b['acao'] ?? 'gerar');
$VOZES = ['dora' => ['nova', 'feminina · clara, institucional'], 'alex' => ['ash', 'masculina · jovem, direta'], 'santa' => ['onyx', 'masculina · grave, narrador'], 'lia' => ['coral', 'feminina · quente, próxima'], 'sol' => ['sage', 'feminina · calma, premium'], 'rui' => ['echo', 'masculina · neutra, comercial']];
if ($acao === 'vozes') { echo json_encode(['premium' => defined('SD_OPENAI_KEY') && SD_OPENAI_KEY, 'vozes' => array_map(fn($k, $v) => ['id' => $k, 'nome' => ucfirst($k), 'desc' => $v[1]], array_keys($VOZES), $VOZES)], JSON_UNESCAPED_UNICODE); exit; }
if ($acao !== 'gerar') sd_fail('Ação desconhecida.');
if (!defined('SD_OPENAI_KEY') || !SD_OPENAI_KEY) sd_fail('Sem SD_OPENAI_KEY no servidor: a locução premium não tem chave. Use a voz da oficina.');
$texto = trim((string)($b['texto'] ?? '')); if ($texto === '') sd_fail('Sem texto.'); if (mb_strlen($texto) > 4000) sd_fail('Até 4.000 caracteres por locução (divida em partes).');
$cid = (string)($b['client_id'] ?? ''); if (!$cid) sd_fail('Escolha o cliente.');
$vk = strtolower((string)($b['voz'] ?? 'dora')); $voz = $VOZES[$vk] ?? $VOZES['dora']; $vel = max(.7, min(1.4, (float)($b['velocidade'] ?? 1)));
$tom = trim((string)($b['tom'] ?? '')); $nome = preg_replace('/[^\w\-. ]+/u', '-', (string)($b['nome'] ?? 'locucao')) ?: 'locucao';
$instr = 'Locução publicitária em português do Brasil, natural, dicção clara, sem sotaque estrangeiro, pausas respeitando a pontuação' . ($vel < .95 ? ', ritmo calmo' : ($vel > 1.1 ? ', ritmo ágil' : '')) . ($tom ? '. Tom: ' . mb_substr($tom, 0, 200) : '. Tom: confiante e próximo, como um narrador de marca premium') . '. Palavras em MAIÚSCULAS ganham ênfase. Linha em branco = pausa curta.';
// 1) gera
$ch = curl_init('https://api.openai.com/v1/audio/speech');
curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 150, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . SD_OPENAI_KEY, 'Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode(['model' => 'gpt-4o-mini-tts', 'voice' => $voz[0], 'input' => $texto, 'instructions' => $instr, 'response_format' => 'mp3', 'speed' => $vel])]);
$mp3 = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
if ($code !== 200 || !$mp3 || strlen($mp3) < 1000) sd_fail('A voz premium respondeu HTTP ' . $code . ($err ? ' · ' . $err : '') . ' · ' . mb_substr((string)$mp3, 0, 200), 502);
// 2) guarda no Storage (bucket pecas, público)
$arq = $nome . '-' . date('Ymd-His') . '.mp3'; $path = 'voz/' . $cid . '/' . $arq;
$ch = curl_init(SD_SUPABASE_URL . '/storage/v1/object/pecas/' . implode('/', array_map('rawurlencode', explode('/', $path))));
curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_POSTFIELDS => $mp3, CURLOPT_HTTPHEADER => ['apikey: ' . SD_SECRET_KEY, 'Authorization: Bearer ' . SD_SECRET_KEY, 'Content-Type: audio/mpeg', 'x-upsert: true']]);
curl_exec($ch); $c2 = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch); if ($c2 >= 300) sd_fail('Não consegui guardar o MP3 (bucket pecas). HTTP ' . $c2, 500);
$url = SD_SUPABASE_URL . '/storage/v1/object/public/pecas/' . implode('/', array_map('rawurlencode', explode('/', $path)));
// 3) cópia no Drive: cliente › MÍDIA › ÁUDIO
$drive = null; $pastaUrl = null;
try { if (function_exists('ponte') && defined('SD_PONTE_URL') && SD_PONTE_URL) { $c = sd_supabase('/rest/v1/clients?id=eq.' . urlencode($cid) . '&select=name,drive_id')['body'][0] ?? []; $raiz = $c['drive_id'] ?? null;
    if (!$raiz && $c && pdDriveClientes()) { $g0 = ponte('pasta_garantir', ['pai_id' => pdDriveClientes(), 'nome' => $c['name'], 'criar' => true]); $raiz = $g0['id'] ?? null; }
    if ($raiz) { $m = ponte('pasta_garantir', ['pai_id' => $raiz, 'nome' => 'MÍDIA', 'criar' => true]); $a = ponte('pasta_garantir', ['pai_id' => $m['id'], 'nome' => 'ÁUDIO', 'criar' => true]); $g = ponte('gravar', ['folderId' => $a['id'], 'nome' => $arq, 'mime' => 'audio/mpeg', 'b64' => base64_encode($mp3), 'substituir' => false]); $drive = $g['url'] ?? null; $pastaUrl = 'https://drive.google.com/drive/folders/' . $a['id']; } } } catch (Throwable $e) {}
// 4) registra como trabalho pronto (aparece em Saída de materiais e na tela de Locução)
$dur = round(strlen($mp3) / 24000, 1);   // ~192 kbps → estimativa
$saida = ['arquivos' => [['nome' => $arq, 'path' => $path, 'bytes' => strlen($mp3), 'mime' => 'audio/mpeg', 'url' => $url, 'drive_url' => $drive, 'drive_pasta' => $pastaUrl, 'em' => gmdate('c')]], 'resultado' => ['voz' => $vk, 'motor' => 'premium', 'velocidade' => $vel, 'duracao_s' => $dur, 'caracteres' => mb_strlen($texto)]];
$tr = sd_supabase('/rest/v1/pd_trabalhos', 'POST', ['modulo' => 'voz', 'titulo' => 'Locução · ' . $nome, 'entrada' => ['nome' => $nome, 'texto' => $texto, 'voz' => $vk, 'velocidade' => $vel, 'motor' => 'premium', 'pedido' => 'Locução premium (' . $vk . ')'], 'status' => 'pronto', 'prioridade' => 5, 'client_id' => $cid, 'task_id' => ($b['task_id'] ?? null) ?: null, 'pedido_por' => $u['id'], 'pedido_via' => 'estudio', 'inicio_em' => gmdate('c'), 'fim_em' => gmdate('c'), 'saida' => $saida], ['Prefer: return=representation']);
if (!empty($b['task_id'])) pdEvento((string)$b['task_id'], 'oficina', 'Locução pronta: ' . $nome, $vk . ' · ' . mb_strlen($texto) . ' caracteres' . ($drive ? ' · no Drive (MÍDIA › ÁUDIO)' : ''), $u['name'] ?? 'Equipe', 'humano');
echo json_encode(['ok' => true, 'url' => $url, 'drive_url' => $drive, 'trabalho' => $tr['body'][0] ?? null, 'duracao_s' => $dur], JSON_UNESCAPED_UNICODE); exit;
