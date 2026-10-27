<?php
// =====================================================================
// Pontodot — PAUTA: o ping-pongue entre o humano e a IA no Estúdio.
//
// 1) O humano traz texto + briefing (prompt livre) + pasta de imagens e faz a PEÇA PRINCIPAL (feed).
// 2) "derivar": a IA olha a peça (imagem + camadas), o kit, o texto, o briefing, as imagens e as lições
//    do cliente, e devolve um PLANO: carrossel, stories, roteiro de vídeo com boards. Tudo vira peça editável.
// 3) O humano edita, escreve feedback, manda de volta (nova rodada). Feedback vira lição.
// 4) "aprovar": specs aprovados viram exemplo da próxima pauta do cliente.
//
// Ações: pauta_listar · pauta_salvar · pauta_abrir · derivar · aprovar · licoes · licao_salvar · kit_status
// Precisa de SD_ANTHROPIC_KEY (modelo: SD_ESTUDIO_MODEL, padrão claude-sonnet-5-5).
// =====================================================================
require_once __DIR__ . '/sd-auth.php';
require_once __DIR__ . '/pd-lib.php';
@set_time_limit(280); @ini_set('memory_limit', '768M');
$u = sd_current_user(); if (!in_array($u['role'], ['admin', 'team'], true)) sd_fail('Só a equipe.', 403);
$b = sd_body(); $acao = (string)($b['acao'] ?? 'pauta_listar');
const ES_CACHE = __DIR__ . '/.cache-midia';
function paPonteOk() { return function_exists('ponte') && defined('SD_PONTE_URL') && defined('SD_PONTE_KEY') && SD_PONTE_KEY; }
function paAssina($id) { return substr(hash_hmac('sha256', 'midia:' . $id, SD_SECRET_KEY), 0, 24); }

// --------------------------------------------------------------- kit: está completo?
function paKit($clientId) {
    $k = sd_supabase('/rest/v1/brand_kits?client_id=eq.' . urlencode($clientId) . '&select=colors,fonts,logos,elements,notes')['body'][0] ?? ['colors' => [], 'fonts' => [], 'logos' => [], 'elements' => []];
    $faltam = [];
    if (count(array_filter((array)$k['colors'], fn($c) => !empty($c['hex']))) < 2) $faltam[] = 'cores (mínimo 2)';
    if (count(array_filter((array)$k['fonts'], fn($f) => !empty($f['name']))) < 1) $faltam[] = 'fonte principal';
    if (count(array_filter((array)($k['logos'] ?? []), fn($l) => !empty($l['file_url']))) < 1) $faltam[] = 'logo';
    return [$k, ['completo' => !$faltam, 'faltam' => $faltam]];
}
// --------------------------------------------------------------- miniatura (b64) de um arquivo do Drive, pelo cache do Estúdio
function paThumbB64($id, $w = 480) {
    if (!is_dir(ES_CACHE)) { @mkdir(ES_CACHE, 0755, true); @file_put_contents(ES_CACHE . '/.htaccess', "Deny from all\n"); }
    $orig = ES_CACHE . '/' . $id; $red = $orig . '-' . $w;
    if (!is_file($red)) {
        if (!is_file($orig)) { if (!paPonteOk()) return null; try { $r = ponte('baixar', ['fileId' => $id]); } catch (Exception $e) { return null; } $by = base64_decode((string)($r['b64'] ?? ''), true); if (!$by) return null; @file_put_contents($orig, $by); @file_put_contents($orig . '.tipo', (string)($r['mime'] ?? 'image/jpeg')); }
        if (!function_exists('imagecreatefromstring')) return null; $im = @imagecreatefromstring((string)file_get_contents($orig)); if (!$im) return null;
        $ow = imagesx($im); $oh = imagesy($im); $nw = min($w, $ow); $nh = (int)round($oh * $nw / $ow); $n = imagecreatetruecolor($nw, $nh); imagecopyresampled($n, $im, 0, 0, 0, 0, $nw, $nh, $ow, $oh); imagejpeg($n, $red, 80); imagedestroy($n); imagedestroy($im);
    }
    return ['b64' => base64_encode((string)file_get_contents($red)), 'mime' => 'image/jpeg'];
}
// --------------------------------------------------------------- resumo das camadas da peça (o que a IA "lê" além da imagem)
function paCamadas($doc, $w, $h) {
    $pg = isset($doc['paginas']) ? ($doc['paginas'][0] ?? []) : $doc; $out = []; $imgs = [];
    foreach ((array)($pg['objects'] ?? []) as $i => $o) {
        $t = $o['type'] ?? ''; $sx = (float)($o['scaleX'] ?? 1); $sy = (float)($o['scaleY'] ?? 1);
        $ww = round(((float)($o['width'] ?? 0)) * $sx / $w * 100); $hh = round(((float)($o['height'] ?? 0)) * $sy / $h * 100);
        $x = round(((float)($o['left'] ?? 0)) / $w * 100); $y = round(((float)($o['top'] ?? 0)) / $h * 100);
        $fill = is_string($o['fill'] ?? null) ? $o['fill'] : 'gradiente';
        if ($t === 'textbox') $out[] = sprintf('texto "%s" · %s %s · %dpx · cor %s · x%d y%d w%d · alinhado %s', mb_substr((string)($o['text'] ?? ''), 0, 80), $o['fontFamily'] ?? '', $o['fontWeight'] ?? '', (int)($o['fontSize'] ?? 0), $fill, $x, $y, $ww, $o['textAlign'] ?? 'left');
        elseif ($t === 'image') { $n = (string)($o['name'] ?? ('imagem ' . ($i + 1))); $imgs[] = ['ref' => 'principal:' . $i, 'nome' => $n, 'src' => (string)($o['pdFonte'] ?: ($o['src'] ?? ''))]; $out[] = sprintf('imagem "%s" (ref principal:%d) · x%d y%d w%d h%d', $n, $i, $x, $y, $ww, $hh); }
        else $out[] = sprintf('%s "%s" · cor %s · x%d y%d w%d h%d · opacidade %s', $t, (string)($o['name'] ?? ''), $fill, $x, $y, $ww, $hh, (string)($o['opacity'] ?? 1));
    }
    return [$out, $imgs, is_string($pg['background'] ?? null) ? $pg['background'] : '#ffffff'];
}
function paClaude($system, $content, $max = 16000) {
    if (!defined('SD_ANTHROPIC_KEY') || !SD_ANTHROPIC_KEY) throw new Exception('Sem SD_ANTHROPIC_KEY no servidor: a IA do Estúdio não tem chave.');
    $model = defined('SD_ESTUDIO_MODEL') ? SD_ESTUDIO_MODEL : 'claude-sonnet-5-5';
    $base = defined('PD_ANTHROPIC_BASE') ? rtrim(PD_ANTHROPIC_BASE, '/') : 'https://api.anthropic.com';
    $ch = curl_init($base . '/v1/messages');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 260,
        CURLOPT_HTTPHEADER => ['x-api-key: ' . SD_ANTHROPIC_KEY, 'anthropic-version: 2023-06-01', 'content-type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['model' => $model, 'max_tokens' => $max, 'system' => $system, 'messages' => [['role' => 'user', 'content' => $content]]])]);
    $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    if ($code !== 200) throw new Exception('A IA respondeu HTTP ' . $code . ($err ? ' · ' . $err : '') . ' · ' . mb_substr((string)$raw, 0, 300));
    $j = json_decode((string)$raw, true); $t = ''; foreach ((array)($j['content'] ?? []) as $x) if (($x['type'] ?? '') === 'text') $t .= $x['text'];
    $us = $j['usage'] ?? []; $custo = (($us['input_tokens'] ?? 0) * 3 + ($us['output_tokens'] ?? 0) * 15) / 1e6;
    return [trim($t), $custo, $us];
}
function paJson($t) { $t = trim($t); if (preg_match('/```(?:json)?\s*(.*?)```/s', $t, $m)) $t = $m[1]; $a = strpos($t, '{'); $z = strrpos($t, '}'); if ($a === false || $z === false) return null; return json_decode(substr($t, $a, $z - $a + 1), true); }

// =============================================================== ações
if ($acao === 'kit_status') { [, $st] = paKit((string)($b['client_id'] ?? '')); echo json_encode($st, JSON_UNESCAPED_UNICODE); exit; }
if ($acao === 'pauta_listar') {
    $q = '/rest/v1/pd_pautas?select=id,client_id,nome,status,rodada,kit_status,principal_id,mudado_em,clients(name)&order=mudado_em.desc&limit=60'; if (!empty($b['client_id'])) $q .= '&client_id=eq.' . urlencode((string)$b['client_id']);
    $r = sd_supabase($q); if (($r['code'] ?? 500) >= 300) sd_fail('Rode o SQL v117 (pauta) no Supabase.', 500); echo json_encode(['pautas' => $r['body'] ?? []], JSON_UNESCAPED_UNICODE); exit;
}
if ($acao === 'pauta_salvar') {
    $id = (string)($b['id'] ?? ''); if (empty($b['client_id']) && !$id) sd_fail('Toda pauta é de um cliente.');
    $d = ['mudado_em' => gmdate('c')]; foreach (['nome' => 120, 'texto' => 20000, 'briefing' => 6000] as $k => $n) if (isset($b[$k])) $d[$k] = mb_substr((string)$b[$k], 0, $n);
    if (array_key_exists('pasta_drive', $b)) { $p = (string)$b['pasta_drive']; if (preg_match('~folders/([\w\-]+)~', $p, $m)) $p = $m[1]; $d['pasta_drive'] = $p ?: null; }
    if (array_key_exists('principal_id', $b)) $d['principal_id'] = $b['principal_id'] ?: null;
    if (!$id) { $d['client_id'] = (string)$b['client_id']; $d['criado_por'] = $u['id']; $d['nome'] = $d['nome'] ?? 'Pauta'; $r = sd_supabase('/rest/v1/pd_pautas', 'POST', $d, ['Prefer: return=representation']); }
    else $r = sd_supabase('/rest/v1/pd_pautas?id=eq.' . urlencode($id), 'PATCH', $d, ['Prefer: return=representation']);
    if (empty($r['body'][0])) sd_fail('Não consegui salvar a pauta: ' . ($r['raw'] ?? ''), 500);
    if (!empty($b['principal_id'])) sd_supabase('/rest/v1/pd_pecas?id=eq.' . urlencode((string)$b['principal_id']), 'PATCH', ['pauta_id' => $r['body'][0]['id'], 'papel' => 'principal']);
    echo json_encode(['ok' => true, 'pauta' => $r['body'][0]], JSON_UNESCAPED_UNICODE); exit;
}
if ($acao === 'pauta_abrir') {
    $id = (string)($b['id'] ?? ''); $p = sd_supabase('/rest/v1/pd_pautas?id=eq.' . urlencode($id) . '&select=*,clients(name)')['body'][0] ?? null; if (!$p) sd_fail('Pauta não encontrada.', 404);
    $pc = sd_supabase('/rest/v1/pd_pecas?pauta_id=eq.' . urlencode($id) . '&arquivada=eq.false&select=id,nome,formato,thumb_url,papel,derivada_tipo,ordem,roteiro,mudado_em&order=ordem.asc,criado_em.asc')['body'] ?? [];
    [, $st] = paKit($p['client_id']); echo json_encode(['pauta' => $p, 'pecas' => $pc, 'kit' => $st], JSON_UNESCAPED_UNICODE); exit;
}
if ($acao === 'licoes') { $cid = (string)($b['client_id'] ?? ''); $r = sd_supabase('/rest/v1/pd_estudio_licoes?ativa=eq.true&or=(client_id.eq.' . urlencode($cid) . ',client_id.is.null)&select=id,client_id,tipo,texto,em&order=em.desc&limit=60'); echo json_encode(['licoes' => $r['body'] ?? []], JSON_UNESCAPED_UNICODE); exit; }
if ($acao === 'licao_salvar') {
    if (!empty($b['id']) && isset($b['ativa'])) { sd_supabase('/rest/v1/pd_estudio_licoes?id=eq.' . urlencode((string)$b['id']), 'PATCH', ['ativa' => (bool)$b['ativa']]); echo json_encode(['ok' => true]); exit; }
    $t = mb_substr(trim((string)($b['texto'] ?? '')), 0, 2000); if (!$t) sd_fail('Escreva a regra.');
    sd_supabase('/rest/v1/pd_estudio_licoes', 'POST', ['client_id' => ($b['client_id'] ?? null) ?: null, 'tipo' => 'regra', 'texto' => $t, 'por' => $u['id']]); echo json_encode(['ok' => true]); exit;
}

if ($acao === 'derivar') {   // a jogada da IA
    $id = (string)($b['id'] ?? ''); $p = sd_supabase('/rest/v1/pd_pautas?id=eq.' . urlencode($id) . '&select=*,clients(name)')['body'][0] ?? null; if (!$p) sd_fail('Pauta não encontrada.', 404);
    if (empty($p['principal_id'])) sd_fail('Faça e salve a peça principal (feed) antes do play.');
    $pr = sd_supabase('/rest/v1/pd_pecas?id=eq.' . urlencode($p['principal_id']) . '&select=id,nome,formato,doc,thumb_url')['body'][0] ?? null; if (!$pr) sd_fail('Peça principal não encontrada.', 404);
    $png = (string)($b['principal_png'] ?? ''); if (strpos($png, ',') !== false) $png = substr($png, strpos($png, ',') + 1); if (!$png) sd_fail('Mande a imagem da peça principal (o Estúdio faz isso sozinho).');
    $feedback = mb_substr(trim((string)($b['feedback'] ?? '')), 0, 4000);
    [$kit, $kitSt] = paKit($p['client_id']);
    $rodada = (int)$p['rodada'] + 1; $hist = is_array($p['historico']) ? $p['historico'] : [];
    if ($feedback) { $hist[] = ['rodada' => $rodada, 'feedback' => $feedback, 'em' => gmdate('c'), 'por' => $u['name']]; sd_supabase('/rest/v1/pd_estudio_licoes', 'POST', ['client_id' => $p['client_id'], 'pauta_id' => $id, 'tipo' => 'feedback', 'texto' => $feedback, 'por' => $u['id']]); }
    sd_supabase('/rest/v1/pd_pautas?id=eq.' . urlencode($id), 'PATCH', ['status' => 'derivando', 'rodada' => $rodada, 'historico' => $hist, 'kit_status' => $kitSt, 'erro' => null, 'mudado_em' => gmdate('c')]);
    // imagens disponíveis: pasta da pauta (ou cliente › MÍDIA)
    $imgs = []; $thumbs = [];
    try { if (paPonteOk()) { $pasta = $p['pasta_drive'] ?: null;
        if (!$pasta) { $c = sd_supabase('/rest/v1/clients?id=eq.' . urlencode($p['client_id']) . '&select=name,drive_id')['body'][0] ?? []; if (!empty($c['drive_id'])) { $g = ponte('pasta_garantir', ['pai_id' => $c['drive_id'], 'nome' => 'MÍDIA', 'criar' => false]); $pasta = $g['id'] ?? null; } }
        if ($pasta) { $l = ponte('listar', ['folderId' => $pasta]); foreach ((array)($l['arquivos'] ?? []) as $f) { $m = (string)($f['mime'] ?? ''); if (stripos($m, 'image/') !== 0 || stripos($m, 'svg') !== false) continue; $imgs[] = ['id' => $f['id'], 'nome' => $f['name']]; if (count($thumbs) < 12) { $t = paThumbB64($f['id'], 420); if ($t) $thumbs[] = ['id' => $f['id'], 'nome' => $f['name']] + $t; } } } } } catch (Exception $e) { /* sem pasta: a IA usa as imagens da principal */ }
    [$camadas, $imgsPrincipal, $fundo] = paCamadas($pr['doc'], (int)$pr['formato']['w'], (int)$pr['formato']['h']);
    $lic = sd_supabase('/rest/v1/pd_estudio_licoes?ativa=eq.true&or=(client_id.eq.' . urlencode($p['client_id']) . ',client_id.is.null)&select=tipo,texto&order=em.desc&limit=40')['body'] ?? [];
    $ex = sd_supabase('/rest/v1/pd_pautas?client_id=eq.' . urlencode($p['client_id']) . '&status=in.(aprovada,produzida)&id=neq.' . urlencode($id) . '&select=aprovado&order=mudado_em.desc&limit=1')['body'][0]['aprovado'] ?? null;
    // derivadas atuais (o humano pode ter editado): resumo das camadas de cada uma
    $atuais = sd_supabase('/rest/v1/pd_pecas?pauta_id=eq.' . urlencode($id) . '&papel=eq.derivada&arquivada=eq.false&select=nome,derivada_tipo,formato,doc,spec&order=ordem.asc')['body'] ?? [];
    $resumoAtuais = []; foreach ($atuais as $a) { [$cs] = paCamadas($a['doc'], (int)$a['formato']['w'], (int)$a['formato']['h']); $resumoAtuais[] = ($a['derivada_tipo'] ?: 'peça') . ' "' . $a['nome'] . '" (página 1): ' . implode(' | ', array_slice($cs, 0, 14)); }

    $cores = implode(', ', array_map(fn($c) => ($c['name'] ?? '') . ' ' . ($c['hex'] ?? ''), array_filter((array)$kit['colors'], fn($c) => !empty($c['hex']))));
    $fontes = implode(', ', array_map(fn($f) => $f['name'], array_filter((array)$kit['fonts'], fn($f) => !empty($f['name']))));
    $logos = array_keys(array_filter((array)($kit['logos'] ?? []), fn($l) => !empty($l['file_url'])));
    $system = <<<TXT
Você é a diretora de arte da Pontodot. Recebe UMA peça principal de feed feita por um humano e deriva o resto do conteúdo da pauta, no mesmo sistema visual, sem inventar outro estilo. Você NÃO escreve texto novo que não esteja no texto da pauta ou na peça principal: você distribui, hierarquiza e corta o texto que recebeu. Você responde SÓ com JSON válido, sem comentários, no formato:
{"raciocinio":"3 linhas no máximo: o que você viu na principal e o que repetiu nas derivações",
 "derivacoes":[
  {"tipo":"carrossel","titulo":"nome curto","paginas":[PAGINA,...]},
  {"tipo":"stories","titulo":"...","paginas":[PAGINA]},
  {"tipo":"video","titulo":"...","roteiro":{"estilo":"frase","legenda_posicao":"baixo-centro|centro|cima","capa":PAGINA,"boards":[{"pagina":PAGINA,"fala":"texto da locução/legenda","duracao_s":4},...4 boards],"encerramento":PAGINA}}
 ]}
PAGINA = {"fundo":"#hex","camadas":[CAMADA,...]} — camadas de baixo para cima. Medidas em PORCENTAGEM da largura (x, w, tamanho) e da altura (y, h) da peça.
CAMADA = {"tipo":"texto","texto":"...","fonte":"nome","peso":"400|700|900","tamanho":7,"cor":"#hex","x":6,"y":12,"w":88,"alinhar":"left|center|right","entrelinha":1.1}
       | {"tipo":"imagem","imagem":"ID da galeria OU principal:N","x":0,"y":0,"w":100,"h":55,"ajuste":"cobrir"}
       | {"tipo":"forma","forma":"rect|circle","cor":"#hex","x":0,"y":60,"w":100,"h":40,"opacidade":0.9,"cantos":0}
       | {"tipo":"gradiente","direcao":"baixo|cima","cor":"#000000","x":0,"y":0,"w":100,"h":100,"forca":0.75}
       | {"tipo":"logo","x":6,"y":90,"w":16}
Regras: carrossel com 1080×1350 e de 4 a 7 páginas (capa forte, miolo com uma ideia por página, última com chamada); 3 stories 1080×1920 (respeite área livre: nada de texto nos 13% de cima e nos 18% de baixo); vídeo em 1080×1920 com capa, 4 boards e encerramento. Use SÓ as fontes do kit (ou as da principal) e SÓ as cores do kit (ou as da principal). Nunca deixe uma palavra sozinha na última linha de um título: encurte ou quebre antes. Tamanhos de texto em % da largura: título 7–10, subtítulo 4–5, corpo 2.6–3.4. Mantenha margens (x≥6, w≤88) salvo imagens e formas de fundo. Use as imagens da galeria pelo ID; se não houver, use as da principal (principal:N). Quando o kit estiver incompleto, siga a principal e marque no raciocínio o que ficou provisório.
TXT;
    $txt = "CLIENTE: {$p['clients']['name']}\nPAUTA: {$p['nome']}\nRODADA: $rodada\n\nBRIEFING (o que o humano pediu):\n" . ($p['briefing'] ?: '(sem briefing)') . "\n\nTEXTO DA PAUTA (só este texto pode aparecer nas peças):\n" . ($p['texto'] ?: '(sem texto além do que está na peça principal)') .
        "\n\nKIT DE MARCA: cores: " . ($cores ?: 'nenhuma') . " · fontes: " . ($fontes ?: 'nenhuma') . " · logos: " . (implode(', ', $logos) ?: 'nenhum') . ($kitSt['completo'] ? '' : ' · KIT INCOMPLETO (faltam: ' . implode(', ', $kitSt['faltam']) . ')') .
        "\n\nPEÇA PRINCIPAL ({$pr['formato']['w']}×{$pr['formato']['h']}, fundo $fundo) — camadas:\n- " . implode("\n- ", $camadas) .
        "\n\nIMAGENS DISPONÍVEIS NA GALERIA (use pelo ID):\n" . ($imgs ? implode("\n", array_map(fn($i) => $i['id'] . ' · ' . $i['nome'], array_slice($imgs, 0, 60))) : '(pasta vazia: use as imagens da principal)') .
        "\n\nIMAGENS DA PRINCIPAL: " . ($imgsPrincipal ? implode('; ', array_map(fn($i) => $i['ref'] . ' = ' . $i['nome'], $imgsPrincipal)) : 'nenhuma') .
        ($lic ? "\n\nLIÇÕES DESTE CLIENTE (obrigatórias):\n- " . implode("\n- ", array_map(fn($l) => '[' . $l['tipo'] . '] ' . $l['texto'], $lic)) : '') .
        ($ex ? "\n\nEXEMPLO APROVADO NA PAUTA ANTERIOR DESTE CLIENTE (repita a lógica):\n" . mb_substr(json_encode($ex, JSON_UNESCAPED_UNICODE), 0, 6000) : '') .
        ($resumoAtuais ? "\n\nDERIVAÇÕES ATUAIS (o humano pode ter mexido; o que ele mudou é lei):\n- " . implode("\n- ", $resumoAtuais) : '') .
        ($feedback ? "\n\nFEEDBACK DO HUMANO NESTA RODADA (manda em tudo):\n$feedback" : '') .
        "\n\nA imagem a seguir é a peça principal. Depois dela, miniaturas da galeria, cada uma precedida do seu ID.";
    $content = [['type' => 'text', 'text' => $txt], ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/png', 'data' => $png]]];
    foreach ($thumbs as $t) { $content[] = ['type' => 'text', 'text' => 'ID ' . $t['id'] . ' · ' . $t['nome']]; $content[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $t['mime'], 'data' => $t['b64']]]; }
    try { [$resp, $custo] = paClaude($system, $content); $plano = paJson($resp); if (!$plano || empty($plano['derivacoes'])) throw new Exception('A IA não devolveu o plano em JSON. Início da resposta: ' . mb_substr($resp, 0, 200)); }
    catch (Exception $e) { sd_supabase('/rest/v1/pd_pautas?id=eq.' . urlencode($id), 'PATCH', ['status' => 'erro', 'erro' => $e->getMessage(), 'mudado_em' => gmdate('c')]); sd_fail($e->getMessage(), 500); }
    sd_supabase('/rest/v1/pd_pautas?id=eq.' . urlencode($id), 'PATCH', ['status' => 'revisao', 'plano' => $plano, 'custo_usd' => (float)$p['custo_usd'] + $custo, 'mudado_em' => gmdate('c')]);
    // as derivadas antigas desta pauta são arquivadas: o Estúdio cria as novas a partir do plano
    sd_supabase('/rest/v1/pd_pecas?pauta_id=eq.' . urlencode($id) . '&papel=eq.derivada', 'PATCH', ['arquivada' => true]);
    $galeria = []; foreach ($imgs as $i) $galeria[$i['id']] = '/pd-estudio.php?img=' . rawurlencode($i['id']) . '&k=' . paAssina($i['id']) . '&w=2400';
    echo json_encode(['ok' => true, 'rodada' => $rodada, 'plano' => $plano, 'galeria' => $galeria, 'kit' => $kitSt, 'custo' => round($custo, 4)], JSON_UNESCAPED_UNICODE); exit;
}
if ($acao === 'aprovar') {
    $id = (string)($b['id'] ?? ''); $p = sd_supabase('/rest/v1/pd_pautas?id=eq.' . urlencode($id) . '&select=id,client_id,plano,rodada')['body'][0] ?? null; if (!$p) sd_fail('Pauta não encontrada.', 404);
    $specs = sd_supabase('/rest/v1/pd_pecas?pauta_id=eq.' . urlencode($id) . '&papel=eq.derivada&arquivada=eq.false&select=nome,derivada_tipo,spec,roteiro&order=ordem.asc')['body'] ?? [];
    $apr = ['rodada' => (int)$p['rodada'], 'derivacoes' => array_map(fn($s) => ['tipo' => $s['derivada_tipo'], 'titulo' => $s['nome'], 'spec' => $s['spec'], 'roteiro' => $s['roteiro']], $specs), 'raciocinio' => $p['plano']['raciocinio'] ?? ''];
    sd_supabase('/rest/v1/pd_pautas?id=eq.' . urlencode($id), 'PATCH', ['status' => 'aprovada', 'aprovado' => $apr, 'mudado_em' => gmdate('c')]);
    sd_supabase('/rest/v1/pd_estudio_licoes', 'POST', ['client_id' => $p['client_id'], 'pauta_id' => $id, 'tipo' => 'aprovacao', 'texto' => 'Pauta aprovada na rodada ' . $p['rodada'] . ' com ' . count($specs) . ' derivações (' . implode(', ', array_unique(array_column($specs, 'derivada_tipo'))) . '). Repetir a lógica.', 'por' => $u['id']]);
    echo json_encode(['ok' => true]); exit;
}
if ($acao === 'produzida') { sd_supabase('/rest/v1/pd_pautas?id=eq.' . urlencode((string)($b['id'] ?? '')), 'PATCH', ['status' => 'produzida', 'mudado_em' => gmdate('c')]); echo json_encode(['ok' => true]); exit; }
sd_fail('Ação desconhecida.');
