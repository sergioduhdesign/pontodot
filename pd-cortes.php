<?php
// Pontodot — CORTES INTELIGENTES (vídeo longo → shorts verticais com legenda palavra por palavra).
// Fluxo: a equipe sobe o vídeo (direto no Storage) ou cola um link → Oficina baixa, extrai o áudio e pede a transcrição aqui (OpenAI Whisper, com tempo de cada palavra)
//        → a IA da casa (Claude) escolhe os melhores trechos, com nota → a equipe ajusta → Oficina corta, enquadra em 9:16 e queima a legenda.
// Lado da Oficina (chave SD_OFICINA_KEY): transcrever_parte · drive_parte.   Lado da equipe: upload_url · sugerir · projetos · projeto · salvar_cortes.
define('PD_SO_FUNCOES', true);
require_once __DIR__ . '/sd-auth.php';
require_once __DIR__ . '/pd-lib.php';
require_once __DIR__ . '/pd-estudio-ia.php';   // paClaude, paJson, paJsonConserta (o guard PD_SO_FUNCOES só carrega as funções)
header('Content-Type: application/json; charset=utf-8');
@set_time_limit(280);
$b = sd_body(); $acao = (string)($b['acao'] ?? '');
function ctSup($path, $m = 'GET', $body = null, $h = []) { return sd_supabase($path, $m, $body, $h); }
function ctLinkLeitura($path, $seg = 604800) { $r = ctSup('/storage/v1/object/sign/oficina/' . implode('/', array_map('rawurlencode', explode('/', $path))), 'POST', ['expiresIn' => $seg], ['Authorization: Bearer ' . SD_SECRET_KEY]); return !empty($r['body']['signedURL']) ? SD_SUPABASE_URL . '/storage/v1' . $r['body']['signedURL'] : null; }

function ctWhisper($b64, $ext, $mime, $idioma) {   // áudio → palavras com tempo (OpenAI Whisper)
    if (!defined('SD_OPENAI_KEY') || !SD_OPENAI_KEY) sd_fail('Sem SD_OPENAI_KEY no servidor.', 500);
    $raw = base64_decode($b64, true); if (!$raw || strlen($raw) < 1000) sd_fail('Áudio vazio.');
    $tmp = tempnam(sys_get_temp_dir(), 'ct') . '.' . $ext; file_put_contents($tmp, $raw);
    $post = ['file' => new CURLFile($tmp, $mime, 'parte.' . $ext), 'model' => 'whisper-1', 'response_format' => 'verbose_json', 'timestamp_granularities[]' => 'word'];
    $lang = preg_replace('/[^a-z]/', '', strtolower($idioma)); if ($lang && $lang !== 'auto') $post['language'] = substr($lang, 0, 2);
    $ch = curl_init('https://api.openai.com/v1/audio/transcriptions'); curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 240, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . SD_OPENAI_KEY], CURLOPT_POSTFIELDS => $post]);
    $out = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch); @unlink($tmp);
    if ($code !== 200) sd_fail('Transcrição respondeu HTTP ' . $code . ($err ? ' · ' . $err : '') . ' · ' . mb_substr((string)$out, 0, 200), 502);
    $j = json_decode((string)$out, true) ?: []; $pal = [];
    foreach ((array)($j['words'] ?? []) as $w) { $t = trim((string)($w['word'] ?? '')); if ($t === '') continue; $pal[] = [$t, round((float)$w['start'], 2), round((float)$w['end'], 2)]; }
    return ['palavras' => $pal, 'idioma' => $j['language'] ?? null, 'duracao' => $j['duration'] ?? null];
}
// ---------------------------------------------------------------- lado da Oficina
if (!empty($b['key'])) {
    if (!defined('SD_OFICINA_KEY') || !hash_equals((string)SD_OFICINA_KEY, (string)$b['key'])) sd_fail('Chave da oficina inválida.', 403);
    if ($acao === 'transcrever_parte') { echo json_encode(ctWhisper((string)($b['b64'] ?? ''), 'mp3', 'audio/mpeg', (string)($b['idioma'] ?? '')), JSON_UNESCAPED_UNICODE); exit; }
    if ($acao === 'drive_parte') {   // pedaço de um arquivo do Drive (a oficina não tem acesso ao Drive; a ponte tem)
        $r = ponte('baixar_parte', ['fileId' => (string)($b['file_id'] ?? ''), 'inicio' => (int)($b['inicio'] ?? 0), 'tamanho' => 15728640]);
        echo json_encode(['b64' => $r['b64'] ?? '', 'total' => $r['total'] ?? 0, 'fim' => !empty($r['fim']), 'nome' => $r['nome'] ?? '', 'mime' => $r['mime'] ?? ''], JSON_UNESCAPED_UNICODE); exit;
    }
    sd_fail('Ação desconhecida.');
}

// ---------------------------------------------------------------- lado da equipe
$u = sd_current_user(); if (!in_array($u['role'], ['admin', 'team'], true)) sd_fail('Só a equipe.', 403);

if ($acao === 'transcrever_trecho') { echo json_encode(ctWhisper((string)($b['b64'] ?? ''), 'wav', 'audio/wav', (string)($b['idioma'] ?? '')), JSON_UNESCAPED_UNICODE); exit; }   // trecho curto (WAV) vindo do navegador
if ($acao === 'upload_url') {   // o navegador sobe o vídeo direto no Storage (sem passar pelo PHP): devolve onde PUT e o link de leitura da Oficina
    $nome = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string)iconv('UTF-8', 'ASCII//TRANSLIT', (string)($b['nome'] ?? 'video.mp4'))) ?: 'video.mp4';
    $path = 'cortes/' . date('Ymd') . '/' . bin2hex(random_bytes(5)) . '-' . mb_substr($nome, 0, 80);
    $r = ctSup('/storage/v1/object/upload/sign/oficina/' . implode('/', array_map('rawurlencode', explode('/', $path))), 'POST', new stdClass(), ['Authorization: Bearer ' . SD_SECRET_KEY]);
    if (empty($r['body']['url'])) sd_fail('Não consegui preparar o envio (o bucket "oficina" existe? rode o SQL v115).', 500);
    echo json_encode(['put_url' => SD_SUPABASE_URL . '/storage/v1' . $r['body']['url'], 'path' => $path, 'get_url' => ctLinkLeitura($path)], JSON_UNESCAPED_UNICODE); exit;
}
if ($acao === 'upload_partes') {   // vídeo de qualquer tamanho: o navegador manda em pedaços de ~16 MB, cada um num arquivo; a Oficina junta
    $n = max(1, min(400, (int)($b['n'] ?? 1))); $nome = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string)iconv('UTF-8', 'ASCII//TRANSLIT', (string)($b['nome'] ?? 'video.mp4'))) ?: 'video.mp4';
    $base = 'cortes/' . date('Ymd') . '/' . bin2hex(random_bytes(5)); $partes = [];
    for ($i = 0; $i < $n; $i++) { $path = $base . '/p' . str_pad((string)$i, 4, '0', STR_PAD_LEFT) . '-' . mb_substr($nome, 0, 60);
        $r = ctSup('/storage/v1/object/upload/sign/oficina/' . implode('/', array_map('rawurlencode', explode('/', $path))), 'POST', new stdClass(), ['Authorization: Bearer ' . SD_SECRET_KEY]);
        if (empty($r['body']['url'])) sd_fail('Não consegui preparar o envio (bucket "oficina"?).', 500); $partes[] = ['put_url' => SD_SUPABASE_URL . '/storage/v1' . $r['body']['url'], 'path' => $path]; }
    echo json_encode(['partes' => $partes], JSON_UNESCAPED_UNICODE); exit;
}
function ctJob($id) { $t = ctSup('/rest/v1/pd_trabalhos?id=eq.' . urlencode($id) . '&select=*')['body'][0] ?? null; if (!$t) sd_fail('Projeto não encontrado.', 404); return $t; }
if ($acao === 'projetos') {
    $q = '/rest/v1/pd_trabalhos?modulo=eq.video&entrada->>tipo=eq.cortes_analise&select=id,titulo,status,erro,criado_em,fim_em,client_id,entrada,saida,clients(name)&order=criado_em.desc&limit=30';
    if (!empty($b['client_id'])) $q .= '&client_id=eq.' . urlencode((string)$b['client_id']);
    $r = ctSup($q); $out = []; foreach (($r['body'] ?? []) as $t) { $res = $t['saida']['resultado'] ?? []; $out[] = ['id' => $t['id'], 'titulo' => $t['titulo'], 'status' => $t['status'], 'erro' => $t['erro'], 'cliente' => $t['clients']['name'] ?? '', 'client_id' => $t['client_id'], 'criado_em' => $t['criado_em'], 'duracao' => $res['duracao'] ?? null, 'cortes' => count((array)($t['entrada']['cortes'] ?? []))]; }
    echo json_encode(['projetos' => $out], JSON_UNESCAPED_UNICODE); exit;
}
if ($acao === 'projeto') {   // tudo que a tela precisa pra reabrir: palavras, vídeo de prévia (link novo), cortes, fonte pra renderizar
    $t = ctJob((string)($b['id'] ?? '')); $res = $t['saida']['resultado'] ?? []; $arqs = $t['saida']['arquivos'] ?? []; $prox = null; foreach ($arqs as $a) if (preg_match('/proxy/', (string)($a['nome'] ?? ''))) $prox = !empty($a['path']) ? ctLinkLeitura($a['path'], 86400) : null;
    $fonte = $t['entrada']['fonte'] ?? []; if (($fonte['tipo'] ?? '') === 'partes') { foreach ($fonte['partes'] as $k => $pp) $fonte['partes'][$k]['url'] = ctLinkLeitura($pp['path']); } if (($fonte['tipo'] ?? '') === 'upload' && !empty($fonte['path'])) $fonte['url'] = ctLinkLeitura($fonte['path']);
    $log = ctSup('/rest/v1/pd_oficina_log?trabalho_id=eq.' . urlencode($t['id']) . '&select=detalhe,em&order=em.asc&limit=60')['body'] ?? [];
    echo json_encode(['id' => $t['id'], 'titulo' => $t['titulo'], 'status' => $t['status'], 'erro' => $t['erro'], 'client_id' => $t['client_id'], 'task_id' => $t['task_id'], 'opcoes' => $t['entrada']['opcoes'] ?? [], 'fonte' => $fonte, 'palavras' => $res['palavras'] ?? [], 'duracao' => $res['duracao'] ?? null, 'idioma' => $res['idioma'] ?? null, 'proxy_url' => $prox, 'cortes' => $t['entrada']['cortes'] ?? [], 'log' => array_map(fn($l) => $l['detalhe'], $log)], JSON_UNESCAPED_UNICODE); exit;
}
if ($acao === 'render_status') {   // andamento da exportação + links dos cortes prontos
    $t = ctJob((string)($b['id'] ?? '')); $arqs = []; foreach ((array)($t['saida']['arquivos'] ?? []) as $a) if (!empty($a['path'])) $arqs[] = ['nome' => $a['nome'], 'bytes' => $a['bytes'] ?? 0, 'url' => ctLinkLeitura($a['path'])];
    $log = ctSup('/rest/v1/pd_oficina_log?trabalho_id=eq.' . urlencode($t['id']) . '&select=detalhe,em&order=em.asc&limit=60')['body'] ?? [];
    echo json_encode(['status' => $t['status'], 'erro' => $t['erro'], 'arquivos' => $arqs, 'log' => array_map(fn($l) => $l['detalhe'], $log)], JSON_UNESCAPED_UNICODE); exit;
}
if ($acao === 'salvar_cortes') {
    $t = ctJob((string)($b['id'] ?? '')); $e = (array)$t['entrada']; $e['cortes'] = array_slice((array)($b['cortes'] ?? []), 0, 40); ctSup('/rest/v1/pd_trabalhos?id=eq.' . urlencode($t['id']), 'PATCH', ['entrada' => $e]); echo json_encode(['ok' => true]); exit;
}
if ($acao === 'sugerir') {   // a IA da casa escolhe os melhores trechos da transcrição
    $t = ctJob((string)($b['id'] ?? '')); $res = $t['saida']['resultado'] ?? []; $pal = (array)($res['palavras'] ?? []); if (count($pal) < 20) sd_fail('A transcrição ainda não está pronta (ou o vídeo quase não tem fala).');
    $n = max(1, min(12, (int)($b['n'] ?? 6))); $dmin = max(8, (int)($b['dur_min'] ?? 20)); $dmax = max($dmin + 5, min(180, (int)($b['dur_max'] ?? 60))); $foco = mb_substr(trim((string)($b['foco'] ?? '')), 0, 400);
    $linhas = []; $cur = []; $ini = null; $fimPrev = 0; $flush = function () use (&$linhas, &$cur, &$ini, &$fimPrev) { if ($cur) { $linhas[] = sprintf('[%.1f-%.1f] %s', $ini, $fimPrev, implode(' ', $cur)); } $cur = []; $ini = null; };
    foreach ($pal as $w) { if ($ini === null) $ini = $w[1]; elseif ($w[1] - $fimPrev > 0.8 || count($cur) >= 28) { $flush(); $ini = $w[1]; } $cur[] = $w[0]; $fimPrev = $w[2]; if (preg_match('/[.!?]$/u', $w[0])) $flush(); }
    $flush(); $texto = implode("\n", $linhas); if (mb_strlen($texto) > 140000) $texto = mb_substr($texto, 0, 140000);
    $c = ctSup('/rest/v1/clients?id=eq.' . urlencode((string)$t['client_id']) . '&select=name,segment')['body'][0] ?? [];
    $system = "Você é editor de vídeos curtos (Reels, Stories, Shorts) de uma agência de branding com foco em mercado imobiliário. Recebe a transcrição com tempos de um vídeo longo e escolhe os trechos que funcionam sozinhos. Responde SÓ com JSON válido, sem comentários.\nCritérios: (1) os primeiros 3 segundos prendem (pergunta, afirmação forte, promessa, contraste); (2) ideia completa — começa no início de uma frase e termina no fim de outra; (3) entrega valor sem depender do resto do vídeo; (4) duração entre {$dmin} e {$dmax} segundos; (5) sem sobreposição entre cortes; (6) prefira trechos com emoção, número, prova ou história. Nunca invente fala: use só o que está na transcrição.\nFormato: {\"cortes\":[{\"ini\":<segundo inicial>,\"fim\":<segundo final>,\"titulo\":\"até 60 caracteres, em português, sem clickbait mentiroso\",\"gancho\":\"a frase de abertura do corte\",\"motivo\":\"por que esse trecho funciona, em 1 frase\",\"nota\":<0-100, potencial de retenção>}]}";
    $txt = 'CLIENTE: ' . ($c['name'] ?? '') . (!empty($c['segment']) ? ' · ' . $c['segment'] : '') . "\nQUANTOS CORTES: até $n\nDURAÇÃO: de $dmin a $dmax segundos" . ($foco ? "\nFOCO PEDIDO PELA EQUIPE: $foco" : '') . "\n\nTRANSCRIÇÃO ([início-fim em segundos] fala):\n$texto";
    try { [$resp, $custo] = paClaude($system, [['type' => 'text', 'text' => $txt]], 8000); } catch (Exception $e) { sd_fail($e->getMessage(), 502); }
    $j = paJson($resp); if (!$j || empty($j['cortes'])) { $j2 = paJson(paJsonConserta($resp)); if ($j2 && !empty($j2['cortes'])) $j = $j2; } if (!$j || empty($j['cortes'])) sd_fail('A IA não devolveu cortes em JSON. Tente de novo.', 502);
    // encaixa nas palavras reais: começa numa palavra, termina numa palavra, respeita a duração
    $dur = (float)($res['duracao'] ?? end($pal)[2]); $cortes = [];
    foreach ($j['cortes'] as $c0) { $ini0 = (float)($c0['ini'] ?? 0); $fim0 = (float)($c0['fim'] ?? 0); if ($fim0 <= $ini0) continue; $a = null; $z = null;
        foreach ($pal as $i => $w) { if ($a === null && $w[1] >= $ini0 - 0.25) $a = $i; if ($w[2] <= $fim0 + 0.25) $z = $i; } if ($a === null || $z === null || $z <= $a) continue;
        $s = $pal[$a][1]; $e = $pal[$z][2]; while ($e - $s > $dmax && $z > $a + 3) { $z--; $e = $pal[$z][2]; }
        $ok = true; foreach ($cortes as $x) if ($s < $x['fim'] && $e > $x['ini']) $ok = false; if (!$ok || $e - $s < 5) continue;
        $cortes[] = ['id' => bin2hex(random_bytes(4)), 'ini' => round(max(0, $s - 0.1), 2), 'fim' => round(min($dur, $e + 0.3), 2), 'titulo' => mb_substr((string)($c0['titulo'] ?? 'Corte'), 0, 90), 'gancho' => mb_substr((string)($c0['gancho'] ?? ''), 0, 240), 'motivo' => mb_substr((string)($c0['motivo'] ?? ''), 0, 240), 'nota' => max(0, min(100, (int)($c0['nota'] ?? 70))), 'sel' => true]; }
    usort($cortes, fn($p, $q) => $q['nota'] <=> $p['nota']); $cortes = array_slice($cortes, 0, $n); if (!$cortes) sd_fail('Nenhum trecho da IA coube na transcrição. Tente de novo.', 502);
    $e = (array)$t['entrada']; $e['cortes'] = $cortes; ctSup('/rest/v1/pd_trabalhos?id=eq.' . urlencode($t['id']), 'PATCH', ['entrada' => $e]);
    echo json_encode(['cortes' => $cortes, 'custo_usd' => round($custo, 4)], JSON_UNESCAPED_UNICODE); exit;
}
sd_fail('Ação desconhecida.');
