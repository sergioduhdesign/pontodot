<?php
// =====================================================================
// Pontodot — SALA DE AULA: o motor
// O PROFESSOR (GPT via SD_OPENAI_KEY; sem a chave, o Claude assume o papel) dá o exercício
// e corrige. A ALUNA é a IA da casa (Claude): produz a arte em SVG. Cada rodada fica guardada.
// Nota boa vira LIÇÃO proposta; o gestor valida; lição aprovada passa a valer na produção.
// Tudo respeita o teto de gasto diário da incubação (pd_incubacao).
// =====================================================================
require_once __DIR__ . '/sd-auth.php';

function pdAulaSup($path, $m = 'GET', $b = null, $x = []) { return sd_supabase($path, $m, $b, $x); }

/** Chamada ao Claude. Devolve [texto, custo_usd]. */
function pdClaude($system, $user, $max = 3500, $model = null) {
    if (!defined('SD_ANTHROPIC_KEY') || !SD_ANTHROPIC_KEY) return [null, 0, 'sem chave da Anthropic'];
    $model = $model ?: (defined('SD_ALUNA_MODEL') ? SD_ALUNA_MODEL : 'claude-sonnet-4-5');
    $base = defined('PD_ANTHROPIC_BASE') ? rtrim(PD_ANTHROPIC_BASE, '/') : 'https://api.anthropic.com';
    $ch = curl_init($base . '/v1/messages');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120,
        CURLOPT_HTTPHEADER => ['x-api-key: ' . SD_ANTHROPIC_KEY, 'anthropic-version: 2023-06-01', 'content-type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['model' => $model, 'max_tokens' => $max, 'system' => $system, 'messages' => [['role' => 'user', 'content' => $user]]])]);
    $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code !== 200) return [null, 0, 'Anthropic HTTP ' . $code];
    $j = json_decode((string)$raw, true); $t = '';
    foreach ((array)($j['content'] ?? []) as $b) if (($b['type'] ?? '') === 'text') $t .= $b['text'];
    $u = $j['usage'] ?? []; $custo = (($u['input_tokens'] ?? 0) * 3 + ($u['output_tokens'] ?? 0) * 15) / 1e6;
    return [trim($t), $custo, null];
}

/** O professor: GPT se houver chave; senão Claude. Aceita PNG (base64) para o professor VER a arte. */
function pdProfessor($system, $user, $pngB64 = null) {
    if (defined('SD_OPENAI_KEY') && SD_OPENAI_KEY) {
        $model = defined('SD_PROFESSOR_MODEL') ? SD_PROFESSOR_MODEL : 'gpt-4.1';
        $content = [['type' => 'text', 'text' => $user]];
        if ($pngB64) $content[] = ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,' . $pngB64]];
        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . SD_OPENAI_KEY, 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode(['model' => $model, 'response_format' => ['type' => 'json_object'], 'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $content]]])]);
        $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($code === 200) { $j = json_decode((string)$raw, true); $u = $j['usage'] ?? [];
            return [trim((string)($j['choices'][0]['message']['content'] ?? '')), (($u['prompt_tokens'] ?? 0) * 2 + ($u['completion_tokens'] ?? 0) * 8) / 1e6, null, 'GPT']; }
        error_log('professor GPT HTTP ' . $code);   // cai para o Claude
        $GLOBALS['pd_gpt_erro'] = 'GPT recusou (HTTP ' . $code . ($code === 401 ? ': chave inválida' : ($code === 429 ? ': sem saldo/limite' : ($code === 404 ? ': modelo inexistente' : ''))) . ')';
    }
    [$t, $c, $e] = pdClaude($system . ' Responda SOMENTE com JSON válido.', $user, 2500);
    return [$t, $c, $e, 'Claude (' . ($GLOBALS['pd_gpt_erro'] ?? 'sem chave OpenAI') . ')'];
}
function pdJson($t) { if (preg_match('/\{.*\}/s', (string)$t, $m)) $t = $m[0]; $j = json_decode((string)$t, true); return is_array($j) ? $j : null; }

/** SVG limpo: sem script, sem evento, sem referência externa. */
function pdSvgLimpo($t) {
    if (!preg_match('/<svg\b.*<\/svg>/si', (string)$t, $m)) return null;
    $s = $m[0];
    $s = preg_replace('#<script\b.*?</script>#si', '', $s); $s = preg_replace('#<foreignObject\b.*?</foreignObject>#si', '', $s);
    $s = preg_replace('/\son\w+\s*=\s*("[^"]*"|\'[^\']*\')/i', '', $s);
    $s = preg_replace('/(xlink:)?href\s*=\s*("(?!#)[^"]*"|\'(?!#)[^\']*\')/i', '', $s);
    $s = preg_replace('#@import[^;]+;#i', '', $s);
    return mb_substr($s, 0, 60000);
}
/** PNG em base64, se o servidor tiver Imagick (o professor VÊ a arte). */
function pdSvgPng($svg) {
    if (!class_exists('Imagick')) return null;
    try { $im = new Imagick(); $im->setBackgroundColor(new ImagickPixel('white')); $im->readImageBlob($svg); $im->setImageFormat('png'); $im->resizeImage(900, 0, Imagick::FILTER_LANCZOS, 1); return base64_encode($im->getImageBlob()); } catch (Throwable $e) { return null; }
}

// ---------------------------------------------------------------- orçamento
function pdIncub() {
    $r = pdAulaSup('/rest/v1/pd_incubacao?id=eq.1&select=*'); $c = $r['body'][0] ?? null; if (!$c) return null;
    if (($c['dia_do_gasto'] ?? '') !== date('Y-m-d')) { pdAulaSup('/rest/v1/pd_incubacao?id=eq.1', 'PATCH', ['gasto_hoje' => 0, 'dia_do_gasto' => date('Y-m-d')]); $c['gasto_hoje'] = 0; }
    return $c;
}
function pdIncubSoma($usd) { if ($usd <= 0) return; $c = pdIncub(); if ($c) pdAulaSup('/rest/v1/pd_incubacao?id=eq.1', 'PATCH', ['gasto_hoje' => round((float)$c['gasto_hoje'] + $usd, 4), 'atualizada_em' => gmdate('c')]); }
function pdIncubTemTeto() { $c = pdIncub(); return !$c || (float)$c['gasto_hoje'] < (float)$c['teto_gasto_dia']; }

// ---------------------------------------------------------------- lições aplicadas
/** As lições APROVADAS desta IA (e as comuns) para a habilidade. Texto pronto para entrar no prompt. */
function pdLicoesTexto($iaUserId, $skillId = null) {
    $q = '/rest/v1/pd_licoes?status=eq.aprovada&or=(comum.eq.true' . ($iaUserId ? ',ia_user_id.eq.' . urlencode($iaUserId) : '') . ')' . ($skillId ? '&skill_id=eq.' . urlencode($skillId) : '') . '&select=skill_id,regra,exemplo_bom,exemplo_ruim&order=criada_em.desc&limit=25';
    $r = pdAulaSup($q); if (($r['code'] ?? 500) >= 300 || empty($r['body'])) return '';
    $o = "LIÇÕES JÁ APROVADAS (obrigatório aplicar):\n";
    foreach ($r['body'] as $l) { $o .= '- ' . $l['regra'] . ($l['exemplo_bom'] ? ' (bom: ' . mb_substr($l['exemplo_bom'], 0, 160) . ')' : '') . ($l['exemplo_ruim'] ? ' (evitar: ' . mb_substr($l['exemplo_ruim'], 0, 160) . ')' : '') . "\n"; }
    return $o;
}

// ---------------------------------------------------------------- a aluna produz a arte
function pdArteProduzir($iaUserId, $personagem, $skillId, $marca, $pedido, $anterior = null, $feedback = null) {
    $licoes = pdLicoesTexto($iaUserId, null);
    $sys = 'Você é ' . ($personagem ?: 'a IA de design da casa') . ', designer sênior de uma agência de branding do mercado imobiliário. Você produz a arte como UM SVG autocontido. '
        . 'REGRAS: use SOMENTE as cores e fontes da marca informada; foto/imagem = retângulo placeholder com o rótulo "FOTO"; sem links externos, sem script; texto em português do Brasil; '
        . 'não invente preço, metragem nem dado do empreendimento; respeite formato (viewBox) e margens. Responda SOMENTE com o código <svg>…</svg>, nada antes nem depois.'
        . ($licoes ? "\n\n" . $licoes : '');
    $u = "MARCA (dados):\n" . json_encode($marca, JSON_UNESCAPED_UNICODE) . "\n\nPEDIDO:\n" . (is_string($pedido) ? $pedido : json_encode($pedido, JSON_UNESCAPED_UNICODE));
    if ($anterior && $feedback) $u .= "\n\nSUA VERSÃO ANTERIOR:\n" . mb_substr($anterior, 0, 20000) . "\n\nCORREÇÃO DO PROFESSOR (aplique tudo):\n" . $feedback;
    [$t, $custo, $err] = pdClaude($sys, $u, 6000);
    pdIncubSoma($custo);
    return [pdSvgLimpo($t), $err];
}

// ---------------------------------------------------------------- a aula, passo a passo
function pdAulaCriar($iaUserId, $personagem, $skillId, $modo = 'aula') {
    $r = pdAulaSup('/rest/v1/pd_aulas', 'POST', ['ia_user_id' => $iaUserId, 'personagem' => $personagem ?: 'ia', 'skill_id' => $skillId, 'modo' => $modo, 'dia' => date('Y-m-d', time() - 3 * 3600)], ['Prefer: return=representation']);
    return $r['body'][0] ?? null;
}
function pdAulaPasso($id, $forcarInterno = false) {
    $a = pdAulaSup('/rest/v1/pd_aulas?id=eq.' . urlencode($id) . '&select=*')['body'][0] ?? null; if (!$a) return ['ok' => false, 'msg' => 'aula não encontrada'];
    if (!pdIncubTemTeto()) return ['ok' => false, 'msg' => 'teto de gasto do dia atingido'];
    $cfg = pdIncub() ?: ['nota_minima' => 85];
    $sk = pdAulaSup('/rest/v1/pd_habilidades?skill_id=eq.' . urlencode($a['skill_id']) . '&ativa=eq.true&order=versao.desc&limit=1&select=*')['body'][0] ?? null;
    if (!$sk) return ['ok' => false, 'msg' => 'habilidade não cadastrada (rode o SQL v111)'];
    $patch = fn($d) => pdAulaSup('/rest/v1/pd_aulas?id=eq.' . urlencode($id), 'PATCH', $d);

    if ($a['status'] === 'aberta') {
        [$t, $c, $e, $prof] = pdProfessor('Você é o PROFESSOR de design de uma escola de IAs de uma agência imobiliária. Crie UM exercício realista para a habilidade abaixo, com um cliente FICTÍCIO (nunca um cliente real). '
            . 'JSON: {"cliente_ficticio":{"nome":"","segmento":"","paleta":[{"nome":"","hex":"#rrggbb"}],"fontes":["",""],"tom":""},"brief":"o que a peça comunica, com texto exato do título e da chamada","formato":"ex.: 1080x1080","criterios":["critérios observáveis"]}',
            'HABILIDADE: ' . json_encode(['nome' => $sk['nome'], 'finalidade' => $sk['finalidade'], 'metodo' => $sk['metodo'], 'criterios' => $sk['criterios']], JSON_UNESCAPED_UNICODE));
        pdIncubSoma($c); $ex = pdJson($t); if (!$ex) return ['ok' => false, 'msg' => 'professor não montou o exercício' . ($e ? ': ' . $e : '')];
        $ex['professor'] = $prof; $patch(['exercicio' => $ex, 'status' => 'em_exercicio', 'rodada' => 1]); return ['ok' => true, 'msg' => 'Exercício dado (' . $prof . ').'];
    }
    if ($a['status'] === 'em_exercicio') {
        $ex = $a['exercicio']; $ent = (array)$a['entregas']; $cor = (array)$a['correcoes'];
        $ant = $ent ? end($ent)['svg'] ?? null : null; $fb = null; if ($cor) { $uc = end($cor); $fb = ($uc['feedback'] ?? '') . (!empty($uc['humano']) ? "\n\nCOMENTÁRIO DO GESTOR (prioridade máxima, aplique antes de tudo):\n" . $uc['humano'] : ''); }
        [$svg, $err] = pdArteProduzir($a['ia_user_id'], $a['personagem'], $a['skill_id'], $ex['cliente_ficticio'] ?? [], ['formato' => $ex['formato'] ?? '1080x1080', 'brief' => $ex['brief'] ?? '', 'criterios' => $ex['criterios'] ?? []], $ant, $fb);
        if (!$svg) return ['ok' => false, 'msg' => 'a aluna não entregou um SVG válido' . ($err ? ': ' . $err : '')];
        $ent[] = ['rodada' => (int)$a['rodada'], 'svg' => $svg, 'em' => gmdate('c')];
        $patch(['entregas' => $ent, 'status' => 'em_correcao']); return ['ok' => true, 'msg' => 'Rodada ' . $a['rodada'] . ': a aluna entregou.'];
    }
    if ($a['status'] === 'em_correcao') {
        // professor EXTERNO = o seu GPT (o Dot): chamado por e-mail + pasta no Drive, como a produção
        if (!$forcarInterno && ($cfg['professor_modo'] ?? 'api') === 'externo') return pdProfessorPasso($a, $sk, $cfg);
        $ex = $a['exercicio']; $ent = (array)$a['entregas']; $ult = end($ent);
        [$t, $c, $e, $prof] = pdProfessor('Você é o PROFESSOR. Corrija a arte (SVG) da aluna com rigor, pelos critérios. JSON: {"nota":0-100,"aprovado":true|false,"feedback":"correção objetiva e acionável, em tópicos","pontos_fortes":"","licao":{"regra":"uma regra geral e reutilizável que a aluna deve levar para sempre","exemplo_bom":"","exemplo_ruim":""}}. Nota >= ' . (int)$cfg['nota_minima'] . ' só se atender TODOS os critérios.',
            "EXERCÍCIO:\n" . json_encode($ex, JSON_UNESCAPED_UNICODE) . "\n\nCRITÉRIOS DA HABILIDADE:\n" . json_encode($sk['criterios'], JSON_UNESCAPED_UNICODE) . "\n\nSVG DA ALUNA (rodada " . $a['rodada'] . "):\n" . mb_substr($ult['svg'], 0, 30000), pdSvgPng($ult['svg']));
        pdIncubSoma($c); $j = pdJson($t); if (!$j) return ['ok' => false, 'msg' => 'professor não devolveu a correção' . ($e ? ': ' . $e : '')];
        return pdAulaAplicarCorrecao($a, $j, $prof, $cfg);
    }
    return ['ok' => false, 'msg' => 'esta aula está em "' . $a['status'] . '" — nada a fazer agora'];
}



// ---------------------------------------------------------------- professor EXTERNO por chamado (o Dot)
// Mesmo circuito da produção: pasta no Drive (a verdade) + e-mail (a campainha) para a caixa da IA.
// O GPT lê a pasta, escreve SAIDA/CORRECAO.json e marca TERMINEI na célula B2 da planilha de controle.
// O Pontodot varre isso a cada rodada do relógio e aplica a correção sozinho.
function pdPonteOk() { return defined('SD_PONTE_URL') && defined('SD_PONTE_KEY') && SD_PONTE_KEY; }
function pdProfessorTexto($a, $ex, $sk, $cfg, $chamado) {
    $cor = (array)$a['correcoes']; $L = [];
    $L[] = 'ISTO NÃO É PRODUÇÃO: é uma AULA da escola de IAs. Você é o PROFESSOR. Ignore os passos 2–4 da planilha (são de produção).';
    $L[] = 'Chamado: ' . $chamado;
    $L[] = 'Aluna: ' . ($a['personagem'] ?: 'IA da casa') . ' · Habilidade: ' . ($sk['nome'] ?? $a['skill_id']) . ' · Rodada ' . $a['rodada'] . ' de ' . $a['max_rodadas'];
    $L[] = '';
    $L[] = 'COMO CORRIGIR: use o SEU critério e o que você já sabe do gosto, do nível e dos padrões do Sérgio Duh (branding imobiliário). Corrija com rigor, como ensinaria uma designer sênior. Não elogie por educação: nota alta só se a arte atende TODOS os critérios.';
    $L[] = '';
    $L[] = 'EXERCÍCIO (cliente fictício):';
    $L[] = json_encode($ex, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $L[] = '';
    $L[] = 'CRITÉRIOS DA HABILIDADE:';
    foreach ((array)($sk['criterios'] ?? []) as $c) $L[] = '- ' . $c;
    $L[] = '';
    $L[] = 'A ARTE DA ALUNA está na pasta ENTRADA: entrega-R' . $a['rodada'] . '.png (para VER) e entrega-R' . $a['rodada'] . '.svg (o código). exercicio.json tem o mesmo enunciado.';
    if ($cor) {
        $L[] = '';
        $L[] = 'RODADAS ANTERIORES (já corrigidas; confira se a aluna aplicou o que foi pedido):';
        foreach ($cor as $c) { $L[] = '- Rodada ' . ($c['rodada'] ?? '?') . ' · nota ' . round((float)($c['nota'] ?? 0)) . ': ' . mb_substr((string)($c['feedback'] ?? ''), 0, 600) . (!empty($c['humano']) ? ' | COMENTÁRIO DO SÉRGIO (prioridade máxima): ' . mb_substr((string)$c['humano'], 0, 600) : ''); }
    }
    $L[] = '';
    $L[] = 'ENTREGA DA SUA CORREÇÃO: grave na pasta SAIDA o arquivo CORRECAO.json (só o JSON, nada além) neste formato:';
    $L[] = '{"nota": 0-100, "aprovado": true|false, "feedback": "correção objetiva e acionável, em tópicos", "pontos_fortes": "o que está bom", "licao": {"regra": "UMA regra geral e reutilizável que a aluna deve levar para sempre", "exemplo_bom": "", "exemplo_ruim": ""}}';
    $L[] = 'Nota mínima para aprovar: ' . (int)($cfg['nota_minima'] ?? 85) . '. A "licao" é o ensino mais importante: é o que passa a valer para a IA da casa quando o Sérgio aprovar.';
    $L[] = '';
    $L[] = 'AO TERMINAR: escreva TERMINEI ' . $chamado . ' na célula B2 da planilha SD_CONTROLE (nesta pasta). O Pontodot lê e aplica sozinho.';
    $L[] = 'Só corrija: não produza a arte pela aluna, não envie nada a ninguém e não use dados de cliente real.';
    $L[] = 'Sem acesso à pasta ou à planilha: mande e-mail para sergio.duh.design@gmail.com (SEM o +sdia) com o assunto [SD-IA BLOQUEIO] ' . $chamado . ' e o motivo.';
    return implode("\n", $L);
}
function pdProfessorEnviar($a, $sk, $cfg, $reenvio = false) {
    if (!pdPonteOk()) return ['ok' => false, 'msg' => 'A ponte do Drive não está configurada (SD_PONTE_URL / SD_PONTE_KEY).'];
    if (!function_exists('ponte') || !function_exists('emailIA')) require_once __DIR__ . '/pd-lib.php';
    $ex = $a['exercicio']; $ent = (array)$a['entregas']; $ult = end($ent); $ch0 = (array)($a['chamado'] ?? []);
    try {
        if (!empty($ch0['folderId'])) { $p = $ch0; }   // reenvio/nova rodada: mesma pasta
        else {
            $nome = 'AULA · ' . ($a['personagem'] ?: 'ia') . ' · ' . $a['skill_id'] . ' · ' . substr($a['id'], 0, 8);
            $p = ponte('preparar', ['folderId' => null, 'parentId' => (defined('SD_ESCOLA_DRIVE_ID') && SD_ESCOLA_DRIVE_ID) ? SD_ESCOLA_DRIVE_ID : null, 'nome' => mb_substr($nome, 0, 120),
                'briefing' => ['tarefa' => 'AULA · ' . $a['skill_id'], 'cliente' => '(fictício) ' . (($ex['cliente_ficticio'] ?? [])['nome'] ?? ''), 'projeto' => ($sk['nome'] ?? $a['skill_id']),
                               'pedido' => "ISTO É UMA AULA, não produção: você é o PROFESSOR. Leia o 00_BRIEFING_IA.txt desta pasta e corrija a arte da aluna. Ignore os passos 2–4 abaixo.", 'referencia' => '']]);
        }
        $chamado = 'AULA-' . substr($a['id'], 0, 8) . '-R' . $a['rodada'] . '-' . date('ymdHis');
        $texto = pdProfessorTexto($a, $ex, $sk, $cfg, $chamado);
        $g = fn($pasta, $nome, $bytes, $mime) => ponte('gravar', ['folderId' => $pasta, 'nome' => $nome, 'b64' => base64_encode($bytes), 'mime' => $mime]);
        $g($p['entradaId'], 'exercicio.json', json_encode($ex, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), 'text/plain');
        $g($p['entradaId'], 'entrega-R' . $a['rodada'] . '.svg', (string)($ult['svg'] ?? ''), 'image/svg+xml');
        $png = pdSvgPng((string)($ult['svg'] ?? '')); if ($png) $g($p['entradaId'], 'entrega-R' . $a['rodada'] . '.png', base64_decode($png), 'image/png');
        $g($p['folderId'], '00_BRIEFING_IA.txt', "\xEF\xBB\xBF" . $texto, 'text/plain');
        $g($p['folderId'], '00_CHAMADO_IA.txt', "\xEF\xBB\xBF" . $texto, 'text/plain');
        ponte('status', ['controleId' => $p['controleId'], 'status' => 'CORRIGIR-AULA', 'rodada' => $a['rodada'], 'obs' => $reenvio ? 'reenvio' : 'chamado enviado']);
        $assunto = '[SD-IA] AULA · CORRIGIR · ' . ($a['personagem'] ?: 'ia') . ' · ' . $a['skill_id'] . ' · R' . $a['rodada'];
        $corpo = $texto . "\n\nPasta: " . ($p['folderUrl'] ?? '') . "\nSD Workflow · " . sd_base();
        $ok = @mail(emailIA(), '=?UTF-8?B?' . base64_encode($assunto) . '?=', $corpo,
            "From: SD Workflow <workflow@sergioduh.com>\r\nReply-To: workflow@sergioduh.com\r\nX-SD-Chamado: " . $chamado . "\r\nContent-Type: text/plain; charset=UTF-8");
        $cham = ['chamado' => $chamado, 'folderId' => $p['folderId'], 'folderUrl' => $p['folderUrl'] ?? '', 'entradaId' => $p['entradaId'], 'saidaId' => $p['saidaId'], 'controleId' => $p['controleId'],
                 'rodada' => (int)$a['rodada'], 'enviado_em' => $ch0 && !empty($ch0['enviado_em']) && (int)($ch0['rodada'] ?? 0) === (int)$a['rodada'] ? $ch0['enviado_em'] : gmdate('c'),
                 'ultimo_envio' => gmdate('c'), 'envios' => ((int)($ch0['envios'] ?? 0)) + 1, 'email' => (bool)$ok, 'erro' => null];
        pdAulaSup('/rest/v1/pd_aulas?id=eq.' . urlencode($a['id']), 'PATCH', ['chamado' => $cham]);
        return ['ok' => true, 'msg' => ($reenvio ? 'Chamado reenviado' : 'Chamado enviado') . ' ao seu GPT (e-mail + pasta no Drive). Aguardando a correção.'];
    } catch (Throwable $e) { error_log('professor chamado: ' . $e->getMessage()); return ['ok' => false, 'msg' => 'Não consegui chamar o seu GPT: ' . $e->getMessage()]; }
}
/** Um passo do professor externo: manda o chamado, ou confere se o GPT já respondeu. */
function pdProfessorPasso($a, $sk, $cfg) {
    $ch = (array)($a['chamado'] ?? []);
    if (empty($ch['folderId']) || (int)($ch['rodada'] ?? 0) !== (int)$a['rodada']) return pdProfessorEnviar($a, $sk, $cfg);
    try {
        $st = strtoupper(trim((string)(ponte('ler_status', ['controleId' => $ch['controleId']])['status'] ?? '')));
        if (strpos($st, 'TERMINEI') !== 0) {
            $h = (time() - strtotime($ch['ultimo_envio'] ?? $ch['enviado_em'] ?? 'now')) / 3600; $reh = (float)($cfg['reenvio_horas'] ?? 4);
            if ($h >= $reh && (int)($ch['envios'] ?? 1) < 3) return pdProfessorEnviar($a, $sk, $cfg, true);
            return ['ok' => false, 'msg' => 'Aguardando o seu GPT corrigir (chamado há ' . ($h < 1 ? round($h * 60) . ' min' : round($h, 1) . ' h') . ').'];
        }
        $arq = ponte('listar', ['folderId' => $ch['saidaId']])['arquivos'] ?? []; $f = null;
        foreach ($arq as $x) if (preg_match('/^correc/i', $x['name'])) { if (!$f || $x['modified'] > $f['modified']) $f = $x; }
        if (!$f) { ponte('status', ['controleId' => $ch['controleId'], 'status' => 'FALTA CORRECAO.json na SAIDA — grave o arquivo e escreva TERMINEI de novo', 'rodada' => $a['rodada']]); return ['ok' => false, 'msg' => 'O GPT marcou TERMINEI, mas não achei CORRECAO.json na SAIDA.']; }
        $b = ponte('baixar', ['fileId' => $f['id']]); $j = pdJson(base64_decode((string)($b['b64'] ?? '')));
        if (!$j || !isset($j['nota'])) { ponte('status', ['controleId' => $ch['controleId'], 'status' => 'CORRECAO ILEGIVEL — regrave o JSON no formato pedido e escreva TERMINEI', 'rodada' => $a['rodada']]); return ['ok' => false, 'msg' => 'Não consegui ler a correção do GPT (JSON inválido ou sem "nota"). Pedi para ele regravar.']; }
        $r = pdAulaAplicarCorrecao($a, $j, 'Seu GPT', $cfg);
        ponte('status', ['controleId' => $ch['controleId'], 'status' => 'RECEBIDO PELO SD', 'rodada' => $a['rodada'], 'obs' => 'nota ' . round((float)$j['nota'])]);
        return $r;
    } catch (Throwable $e) { error_log('professor varrer: ' . $e->getMessage()); return ['ok' => false, 'msg' => 'Falha ao conferir a resposta do GPT: ' . $e->getMessage()]; }
}
/** Relógio: confere todas as provas que estão com o seu GPT (roda mesmo com o treino automático desligado). */
function pdProfessorVarrer() {
    $cfg = pdIncub() ?: []; if (($cfg['professor_modo'] ?? 'api') !== 'externo' || !pdPonteOk()) return null;
    $as = pdAulaSup('/rest/v1/pd_aulas?status=eq.em_correcao&select=*&limit=20')['body'] ?? []; $out = [];
    foreach ((array)$as as $a) {
        if (empty($a['chamado']['folderId'])) continue;   // ainda não foi chamado: quem manda é o passo normal
        $sk = pdAulaSup('/rest/v1/pd_habilidades?skill_id=eq.' . urlencode($a['skill_id']) . '&ativa=eq.true&order=versao.desc&limit=1&select=*')['body'][0] ?? [];
        $r = pdProfessorPasso($a, $sk, $cfg); if (!empty($r['ok'])) $out[] = $r['msg'];
    }
    return $out ? implode(' | ', $out) : null;
}

/** Aplica a correção do professor (interno ou o seu GPT externo): nota, lição, próximo estado. */
function pdAulaAplicarCorrecao($a, $j, $prof, $cfg = null) {
    $id = $a['id']; $cfg = $cfg ?: (pdIncub() ?: ['nota_minima' => 85]); $cor = (array)$a['correcoes'];
    $patch = fn($d) => pdAulaSup('/rest/v1/pd_aulas?id=eq.' . urlencode($id), 'PATCH', $d);
    $nota = max(0, min(100, (float)($j['nota'] ?? 0)));
    $cor[] = ['rodada' => (int)$a['rodada'], 'nota' => $nota, 'feedback' => (string)($j['feedback'] ?? ''), 'fortes' => (string)($j['pontos_fortes'] ?? ''), 'professor' => $prof, 'em' => gmdate('c')];
    pdAulaSup('/rest/v1/pd_notas', 'POST', ['ia_user_id' => $a['ia_user_id'], 'personagem' => $a['personagem'], 'skill_id' => $a['skill_id'], 'fonte' => 'professor', 'nota' => $nota, 'aula_id' => $id]);
    if ($nota >= (float)$cfg['nota_minima']) {
        $l = $j['licao'] ?? null;
        pdAulaSup('/rest/v1/pd_licoes?aula_id=eq.' . urlencode($id) . '&status=eq.proposta', 'DELETE');
        if (is_array($l) && !empty($l['regra'])) pdAulaSup('/rest/v1/pd_licoes', 'POST', ['ia_user_id' => $a['ia_user_id'], 'personagem' => $a['personagem'], 'skill_id' => $a['skill_id'], 'regra' => mb_substr($l['regra'], 0, 600), 'exemplo_bom' => mb_substr((string)($l['exemplo_bom'] ?? ''), 0, 400), 'exemplo_ruim' => mb_substr((string)($l['exemplo_ruim'] ?? ''), 0, 400), 'aula_id' => $id]);
        $patch(['correcoes' => $cor, 'status' => 'validacao_humana', 'nota_final' => $nota]); return ['ok' => true, 'msg' => 'Nota ' . $nota . ' (' . $prof . ') — aprovada pelo professor; aguarda a sua validação.'];
    }
    if ((int)$a['rodada'] >= (int)$a['max_rodadas']) { $patch(['correcoes' => $cor, 'status' => 'reprovada', 'nota_final' => $nota]); return ['ok' => true, 'msg' => 'Nota ' . $nota . ' — esgotou as rodadas; reprovada.']; }
    $patch(['correcoes' => $cor, 'status' => 'em_exercicio', 'rodada' => (int)$a['rodada'] + 1]); return ['ok' => true, 'msg' => 'Nota ' . $nota . ' (' . $prof . ') — voltou para a aluna refazer (rodada ' . ((int)$a['rodada'] + 1) . ').'];
}

/** Gestor decide: 'aprovar' (entrega boa) · 'revisar' (comentário e a aluna refaz) · 'reprovar'. */
function pdAulaValidar($id, $decisao, $parecer, $porId) {
    if ($decisao === true) $decisao = 'aprovar'; if ($decisao === false) $decisao = 'reprovar';
    $a = pdAulaSup('/rest/v1/pd_aulas?id=eq.' . urlencode($id) . '&select=*')['body'][0] ?? null; if (!$a) return false;
    if ($decisao === 'revisar') {
        $cor = (array)$a['correcoes']; if ($cor) { $k = count($cor) - 1; $cor[$k]['humano'] = mb_substr((string)$parecer, 0, 800); }
        pdAulaSup('/rest/v1/pd_aulas?id=eq.' . urlencode($id), 'PATCH', ['correcoes' => $cor, 'status' => 'em_exercicio', 'rodada' => (int)$a['rodada'] + 1, 'max_rodadas' => max((int)$a['max_rodadas'], (int)$a['rodada'] + 1), 'nota_final' => null]);
        return true;
    }
    $aprovar = $decisao === 'aprovar';
    pdAulaSup('/rest/v1/pd_aulas?id=eq.' . urlencode($id), 'PATCH', ['status' => $aprovar ? 'validada' : 'reprovada', 'parecer_humano' => mb_substr((string)$parecer, 0, 800), 'validada_por' => $porId, 'validada_em' => gmdate('c')]);
    pdAulaSup('/rest/v1/pd_licoes?aula_id=eq.' . urlencode($id) . '&status=eq.proposta', 'PATCH', $aprovar ? ['status' => 'aprovada', 'aprovada_por' => $porId, 'aprovada_em' => gmdate('c')] : ['status' => 'recusada', 'motivo' => mb_substr((string)$parecer, 0, 300)]);
    if ($aprovar && $a['nota_final'] !== null) pdAulaSup('/rest/v1/pd_notas', 'POST', ['ia_user_id' => $a['ia_user_id'], 'personagem' => $a['personagem'], 'skill_id' => $a['skill_id'], 'fonte' => 'humano', 'nota' => $a['nota_final'], 'aula_id' => $id]);
    pdFormacaoAtualiza($a['ia_user_id'], $a['skill_id']);
    return true;
}

// ---------------------------------------------------------------- formação: barra de evolução
/** Provas fechadas (validada = entrega boa; reprovada = ruim), em ordem. */
function pdFormacaoSituacao($iaId, $skillId, $cfg = null) {
    $cfg = $cfg ?: (pdIncub() ?: []); $boasPara = (int)($cfg['boas_para_apto'] ?? 3);
    $r = pdAulaSup('/rest/v1/pd_aulas?ia_user_id=eq.' . urlencode($iaId) . '&skill_id=eq.' . urlencode($skillId) . '&status=in.(validada,reprovada)&order=criada_em.asc&select=status&limit=400')['body'] ?? [];
    $fechadas = count($r); $boas = 0; foreach ($r as $x) $boas += $x['status'] === 'validada' ? 1 : 0;
    $seq = 0; foreach (array_reverse($r) as $x) { if ($x['status'] === 'validada') $seq++; else break; }
    return ['hist' => array_map(fn($x) => $x['status'] === 'validada' ? 1 : 0, $r), 'fechadas' => $fechadas, 'boas' => $boas, 'seguidas' => $seq, 'boas_para_apto' => $boasPara, 'apta' => $seq >= $boasPara];
}
/** Depois de cada decisão do gestor: marca a IA como apta (ou tira, se voltou a errar). */
function pdFormacaoAtualiza($iaId, $skillId) {
    $f = pdAulaSup('/rest/v1/pd_formacoes?ia_user_id=eq.' . urlencode($iaId) . '&skill_id=eq.' . urlencode($skillId) . '&select=id,apta_em')['body'][0] ?? null; if (!$f) return;
    $sit = pdFormacaoSituacao($iaId, $skillId);
    pdAulaSup('/rest/v1/pd_formacoes?id=eq.' . urlencode($f['id']), 'PATCH', ['apta_em' => $sit['apta'] ? ($f['apta_em'] ?: gmdate('c')) : null]);
}
function pdFormacaoMatricula($iaId, $personagem, $skillId) {
    $r = pdAulaSup('/rest/v1/pd_formacoes?on_conflict=ia_user_id,skill_id', 'POST', ['ia_user_id' => $iaId, 'personagem' => $personagem ?: 'ia', 'skill_id' => $skillId, 'ativa' => true], ['Prefer: resolution=merge-duplicates,return=representation']);
    return $r['body'][0] ?? null;
}

/** Relógio: uma PROVA por dia para cada formação em andamento (em sandbox, com teto de gasto). */
function pdIncubacaoRelogio() {
    $v = pdProfessorVarrer();   // respostas do seu GPT entram mesmo com o treino automático desligado
    $c = pdIncub(); if (!$c || empty($c['ativa'])) return $v ?: 'desligada';
    if (!pdIncubTemTeto()) return 'teto do dia atingido';
    $msg = $v ? [$v] : []; $meta = (int)($c['provas_meta'] ?? 30); $hoje = date('Y-m-d', time() - 3 * 3600);
    $abertas = pdAulaSup('/rest/v1/pd_aulas?status=in.(aberta,em_exercicio,em_correcao,validacao_humana)&select=ia_user_id,skill_id,status,id')['body'] ?? [];
    $ocupado = []; foreach ($abertas as $a) $ocupado[$a['ia_user_id'] . '|' . $a['skill_id']] = 1;
    $forms = pdAulaSup('/rest/v1/pd_formacoes?ativa=eq.true&apta_em=is.null&select=*&limit=60')['body'] ?? [];
    foreach ($forms as $f) {
        $k = $f['ia_user_id'] . '|' . $f['skill_id']; if (isset($ocupado[$k])) continue;
        $sit = pdFormacaoSituacao($f['ia_user_id'], $f['skill_id'], $c); if ($sit['fechadas'] >= (int)($f['meta_provas'] ?: $meta)) continue;
        $ja = pdAulaSup('/rest/v1/pd_aulas?ia_user_id=eq.' . urlencode($f['ia_user_id']) . '&skill_id=eq.' . urlencode($f['skill_id']) . '&dia=eq.' . $hoje . '&select=id&limit=1')['body'] ?? [];
        if ($ja) continue;
        $n = pdAulaCriar($f['ia_user_id'], $f['personagem'], $f['skill_id'], 'prova'); if ($n) { $msg[] = 'prova do dia aberta (' . $f['skill_id'] . ')'; $abertas[] = ['id' => $n['id'], 'status' => 'aberta', 'ia_user_id' => $f['ia_user_id'], 'skill_id' => $f['skill_id']]; }
        if (count($msg) >= 3) break;   // no máximo 3 provas novas por rodada do relógio
    }
    // as provas em andamento avançam (a de “aguarda você” espera o gestor)
    $n = 0; foreach ($abertas as $a) { if ($a['status'] === 'validacao_humana') continue; for ($i = 0; $i < 3; $i++) { $r = pdAulaPasso($a['id']); $msg[] = $r['msg']; if (!$r['ok']) break; } if (++$n >= 3) break; }
    return $msg ? implode(' | ', $msg) : 'nada a treinar';
}
