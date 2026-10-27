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

/** Professor por API (PILOTO, só quando escolhido de propósito): GPT cru, SEM a bagagem do Sérgio.
 *  Nunca cai para o Claude em silêncio: sem chave ou com falha, devolve erro. */
function pdProfessor($system, $user, $pngB64 = null) {
    if (!defined('SD_OPENAI_KEY') || !SD_OPENAI_KEY) return [null, 0, 'sem SD_OPENAI_KEY (o modo API não tem chave)', 'GPT cru (API)'];
    $model = defined('SD_PROFESSOR_MODEL') ? SD_PROFESSOR_MODEL : 'gpt-4.1';
    $content = [['type' => 'text', 'text' => $user]];
    if ($pngB64) $content[] = ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,' . $pngB64]];
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . SD_OPENAI_KEY, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['model' => $model, 'response_format' => ['type' => 'json_object'], 'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $content]]])]);
    $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code !== 200) return [null, 0, 'a OpenAI respondeu HTTP ' . $code, 'GPT cru (API)'];
    $j = json_decode((string)$raw, true); $u = $j['usage'] ?? [];
    return [trim((string)($j['choices'][0]['message']['content'] ?? '')), (($u['prompt_tokens'] ?? 0) * 2 + ($u['completion_tokens'] ?? 0) * 8) / 1e6, null, 'GPT cru (API · piloto)'];
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
    $base = '/rest/v1/pd_licoes?status=eq.aprovada&or=(comum.eq.true' . ($iaUserId ? ',ia_user_id.eq.' . urlencode($iaUserId) : '') . ')' . ($skillId ? '&skill_id=eq.' . urlencode($skillId) : '') . '&select=skill_id,regra,exemplo_bom,exemplo_ruim&order=criada_em.desc&limit=25';
    $r = pdAulaSup($base . '&escopo=in.(habilidade,universal)');       // lição específica de cliente não vira regra geral
    if (($r['code'] ?? 500) >= 300) $r = pdAulaSup($base);              // SQL v112 ainda não rodou: comportamento antigo
    if (($r['code'] ?? 500) >= 300 || empty($r['body'])) return '';
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
// PAPÉIS (nunca misturar):
//   PD ........ produz o exercício, a aluna (Claude) entrega, a PD guarda versões e reconhece a correção
//   dot ....... TRANSPORTE: leva a aula ao Projeto do ChatGPT e traz o parecer; não julga, não escreve parecer
//   professor . o GPT dentro do Projeto do ChatGPT do Sérgio (pareceres só dele)
//   Sérgio .... decisão final (aprovar / revisar / reprovar); só depois uma lição entra no repertório
function pdAulaCriar($iaUserId, $personagem, $skillId, $modo = 'prova') {
    $r = pdAulaSup('/rest/v1/pd_aulas', 'POST', ['ia_user_id' => $iaUserId, 'personagem' => $personagem ?: 'ia', 'skill_id' => $skillId, 'modo' => $modo, 'dia' => date('Y-m-d', time() - 3 * 3600)], ['Prefer: return=representation']);
    return $r['body'][0] ?? null;
}
function pdAulaPasso($id) {
    $a = pdAulaSup('/rest/v1/pd_aulas?id=eq.' . urlencode($id) . '&select=*')['body'][0] ?? null; if (!$a) return ['ok' => false, 'msg' => 'aula não encontrada'];
    $cfg = pdIncub() ?: ['nota_minima' => 85, 'professor_modo' => 'projeto_gpt'];
    $sk = pdAulaSup('/rest/v1/pd_habilidades?skill_id=eq.' . urlencode($a['skill_id']) . '&ativa=eq.true&order=versao.desc&limit=1&select=*')['body'][0] ?? null;
    if (!$sk) return ['ok' => false, 'msg' => 'habilidade não cadastrada (rode o SQL v111)'];
    $patch = fn($d) => pdAulaSup('/rest/v1/pd_aulas?id=eq.' . urlencode($id), 'PATCH', $d);
    $teto = fn() => pdIncubTemTeto();

    if ($a['status'] === 'aberta') {          // a PD produz o exercício (não é o professor)
        if (!$teto()) return ['ok' => false, 'msg' => 'teto de gasto do dia atingido'];
        [$t, $c, $e] = pdClaude('Você monta exercícios para a escola de IAs de uma agência imobiliária (você NÃO é o professor e não corrige). Crie UM exercício realista para a habilidade abaixo, com um cliente FICTÍCIO (nunca um cliente real). '
            . 'Responda SOMENTE com JSON: {"cliente_ficticio":{"nome":"","segmento":"","paleta":[{"nome":"","hex":"#rrggbb"}],"fontes":["",""],"tom":""},"brief":"o que a peça comunica, com texto exato do título e da chamada","formato":"ex.: 1080x1080","criterios":["critérios observáveis"]}',
            'HABILIDADE: ' . json_encode(['nome' => $sk['nome'], 'finalidade' => $sk['finalidade'], 'metodo' => $sk['metodo'], 'criterios' => $sk['criterios']], JSON_UNESCAPED_UNICODE), 2500);
        pdIncubSoma($c); $ex = pdJson($t); if (!$ex) return ['ok' => false, 'msg' => 'a PD não montou o exercício' . ($e ? ': ' . $e : '')];
        $ex['autor'] = 'PD'; $ex['skill_versao'] = $sk['versao'] ?? 1; $patch(['exercicio' => $ex, 'status' => 'em_exercicio', 'rodada' => 1]); return ['ok' => true, 'msg' => 'Exercício montado pela PD.'];
    }
    if ($a['status'] === 'em_exercicio') {    // a aluna entrega UMA versão por rodada (nunca duplica)
        $ex = $a['exercicio']; $ent = (array)$a['entregas']; $cor = (array)$a['correcoes'];
        if (count($ent) >= (int)$a['rodada']) { $patch(['status' => 'em_correcao']); return ['ok' => true, 'msg' => 'Rodada ' . $a['rodada'] . ' já tinha entrega; seguiu para a correção (sem versão duplicada).']; }
        if (!$teto()) return ['ok' => false, 'msg' => 'teto de gasto do dia atingido'];
        $ant = $ent ? end($ent)['svg'] ?? null : null; $fb = null; if ($cor) { $uc = end($cor); $fb = ($uc['feedback'] ?? '') . (!empty($uc['humano']) ? "\n\nCOMENTÁRIO DO GESTOR (prioridade máxima, aplique antes de tudo):\n" . $uc['humano'] : ''); }
        [$svg, $err] = pdArteProduzir($a['ia_user_id'], $a['personagem'], $a['skill_id'], $ex['cliente_ficticio'] ?? [], ['formato' => $ex['formato'] ?? '1080x1080', 'brief' => $ex['brief'] ?? '', 'criterios' => $ex['criterios'] ?? []], $ant, $fb);
        if (!$svg) return ['ok' => false, 'msg' => 'a aluna não entregou um SVG válido' . ($err ? ': ' . $err : '')];
        $ent[] = ['rodada' => (int)$a['rodada'], 'svg' => $svg, 'em' => gmdate('c')];
        $patch(['entregas' => $ent, 'status' => 'em_correcao']); return ['ok' => true, 'msg' => 'Rodada ' . $a['rodada'] . ': a aluna entregou.'];
    }
    if ($a['status'] === 'em_correcao') {
        if (($cfg['professor_modo'] ?? 'projeto_gpt') === 'api') {   // PILOTO explícito: GPT cru, sem a bagagem do Sérgio
            if (!$teto()) return ['ok' => false, 'msg' => 'teto de gasto do dia atingido'];
            $ex = $a['exercicio']; $ent = (array)$a['entregas']; $ult = end($ent);
            [$t, $c, $e, $prof] = pdProfessor('Você é o PROFESSOR. Corrija a arte (SVG) da aluna com rigor, pelos critérios. JSON: {"nota":0-100,"aprovado":true|false,"feedback":"correção objetiva e acionável, em tópicos","pontos_fortes":"","licao":{"regra":"uma regra geral e reutilizável","escopo":"habilidade","exemplo_bom":"","exemplo_ruim":""}}. Nota >= ' . (int)$cfg['nota_minima'] . ' só se atender TODOS os critérios.',
                "EXERCÍCIO:\n" . json_encode($ex, JSON_UNESCAPED_UNICODE) . "\n\nCRITÉRIOS DA HABILIDADE:\n" . json_encode($sk['criterios'], JSON_UNESCAPED_UNICODE) . "\n\nSVG DA ALUNA (rodada " . $a['rodada'] . "):\n" . mb_substr($ult['svg'], 0, 30000), pdSvgPng($ult['svg']));
            pdIncubSoma($c); $j = pdJson($t); if (!$j) return ['ok' => false, 'msg' => 'o professor (API) não devolveu a correção' . ($e ? ': ' . $e : '')];
            $j['_origem'] = ['papel' => 'professor', 'fonte' => 'api_gpt_cru', 'projeto' => '', 'transportado_por' => 'pd'];
            return pdAulaAplicarCorrecao($a, $j, $prof, $cfg);
        }
        return pdProfessorPasso($a, $sk, $cfg);   // professor = GPT do Projeto; o dot só transporta
    }
    return ['ok' => false, 'msg' => 'esta aula está em "' . $a['status'] . '" — nada a fazer agora'];
}

// ---------------------------------------------------------------- professor no PROJETO do ChatGPT (dot = transporte)
define('PD_CONTRATO_AULA', 'pd-aula/2'); define('PD_CONTRATO_CORRECAO', 'pd-correcao/2');
function pdPonteOk() { return defined('SD_PONTE_URL') && defined('SD_PONTE_KEY') && SD_PONTE_KEY; }
function pdProjetoProfessor($cfg) { return trim((string)($cfg['professor_projeto'] ?? '')) ?: 'Aprendendo a fazer arte de post'; }

/** Cada passagem é identificada: aula + rodada + chamado. */
function pdAulaJson($a, $sk, $cfg, $chamado) {
    $hist = []; $ent = (array)$a['entregas']; $cor = (array)$a['correcoes'];
    foreach ($ent as $e) { $r = (int)$e['rodada']; $c = null; foreach ($cor as $x) if ((int)($x['rodada'] ?? 0) === $r) $c = $x;
        $hist[] = ['rodada' => $r, 'arquivos' => ['entrega-R' . $r . '.png', 'entrega-R' . $r . '.svg'], 'entregue_em' => $e['em'] ?? null,
                   'parecer_do_professor' => $c ? ['nota' => $c['nota'] ?? null, 'feedback' => $c['feedback'] ?? '', 'texto_do_professor' => $c['texto_do_professor'] ?? '', 'origem' => $c['professor'] ?? ''] : null,
                   'decisao_do_sergio' => $c && !empty($c['humano']) ? $c['humano'] : null]; }
    return ['contrato' => PD_CONTRATO_AULA, 'aula_id' => $a['id'], 'rodada' => (int)$a['rodada'], 'max_rodadas' => (int)$a['max_rodadas'], 'chamado' => $chamado, 'modo' => $a['modo'],
        'aluna' => ['personagem' => $a['personagem'], 'ia_user_id' => $a['ia_user_id']],
        'habilidade' => ['skill_id' => $a['skill_id'], 'nome' => $sk['nome'] ?? '', 'versao' => $sk['versao'] ?? 1, 'finalidade' => $sk['finalidade'] ?? '', 'criterios' => $sk['criterios'] ?? []],
        'exercicio' => ['autor' => ($a['exercicio']['autor'] ?? 'PD')] + (array)$a['exercicio'],
        'versao_atual' => ['rodada' => (int)$a['rodada'], 'arquivos' => ['entrega-R' . $a['rodada'] . '.png', 'entrega-R' . $a['rodada'] . '.svg']],
        'historico' => $hist, 'nota_minima' => (float)($cfg['nota_minima'] ?? 85), 'professor' => ['onde' => 'Projeto do ChatGPT: ' . pdProjetoProfessor($cfg)]];
}
function pdModeloCorrecao($a, $chamado, $cfg) {
    return ['contrato' => PD_CONTRATO_CORRECAO, 'aula_id' => $a['id'], 'rodada' => (int)$a['rodada'], 'chamado' => $chamado,
        'origem' => ['papel' => 'professor', 'fonte' => 'chatgpt_projeto', 'projeto' => pdProjetoProfessor($cfg), 'transportado_por' => 'dot'],
        'parecer_do_professor' => [
            'texto_do_professor' => '<o parecer COMO O PROFESSOR ESCREVEU, copiado sem resumir e sem reescrever>',
            'nota' => 0, 'aprovado' => false,
            'problemas' => [['problema' => '<problema específico da peça>', 'motivo' => '<por que é um problema>', 'correcao' => '<como corrigir>']],
            'pontos_fortes' => ''],
        'licao_sugerida' => ['regra' => '<uma regra geral reutilizável, ou deixe vazio>', 'escopo' => 'habilidade|universal|cliente', 'exemplo_bom' => '', 'exemplo_ruim' => '']];
}
function pdProfessorTexto($a, $sk, $cfg, $chamado) {
    $proj = pdProjetoProfessor($cfg); $L = [];
    $L[] = 'ISTO NÃO É PRODUÇÃO: é uma AULA da escola de IAs. Ignore os passos 2–4 da planilha (são de produção).';
    $L[] = 'Chamado: ' . $chamado . ' · Aula: ' . $a['id'] . ' · Rodada ' . $a['rodada'] . ' de ' . $a['max_rodadas'] . ($a['modo'] === 'teste' ? ' · RODADA DE TESTE da ponte (sem efeito sobre a formação)' : '');
    $L[] = 'Aluna: ' . ($a['personagem'] ?: 'IA da casa') . ' · Habilidade: ' . ($sk['nome'] ?? $a['skill_id']);
    $L[] = '';
    $L[] = 'PAPÉIS — leia com atenção:';
    $L[] = '• Você (dot) é o TRANSPORTE. Leva esta aula ao Projeto do ChatGPT do Sérgio e traz o parecer de volta. Você NÃO é o professor: não julga a arte, não escreve parecer, não completa nem melhora o parecer dele.';
    $L[] = '• O PROFESSOR é o GPT dentro do Projeto “' . $proj . '” do ChatGPT do Sérgio. Só o parecer dele vale.';
    $L[] = '• O Sérgio decide por último. Só depois da decisão dele uma lição entra no repertório das agentes.';
    $L[] = '';
    $L[] = 'O QUE FAZER:';
    $L[] = '1. Abra o Projeto “' . $proj . '”. Entregue a ele, na mesma conversa da aula (se a aula já tem rodadas anteriores, continue a conversa dela): a arte desta rodada (entrega-R' . $a['rodada'] . '.png e .svg na pasta ENTRADA), o aula.json (exercício, critérios, histórico das rodadas anteriores e decisões do Sérgio) e as versões anteriores.';
    $L[] = '2. Peça: problemas ESPECÍFICOS da peça, o motivo de cada um e como corrigir; nota 0–100 (aprovada só com nota ≥ ' . (int)($cfg['nota_minima'] ?? 85) . ' e atendendo todos os critérios); pontos fortes; e, se houver, UMA lição geral reutilizável (diga se é da habilidade, universal ou específica de cliente).';
    $L[] = '3. Copie o parecer DELE, sem resumir nem reescrever, para o campo texto_do_professor do arquivo.';
    $L[] = '4. Grave SAIDA/CORRECAO.json exatamente no formato de 00_FORMATO_CORRECAO.json (inclua aula_id, rodada e chamado EXATOS deste chamado e a origem real). Depois escreva TERMINEI ' . $chamado . ' na célula B2 da planilha SD_CONTROLE.';
    $L[] = '';
    $L[] = 'SE O PROFESSOR NÃO RESPONDEU (ou você não conseguiu levar a aula ao Projeto): escreva AGUARDANDO-PROFESSOR na célula B2 e NÃO crie CORRECAO.json. Nunca invente um parecer para fechar a aula. A PD mantém a aula como “aguardando”.';
    $L[] = 'Não produza a arte pela aluna, não envie nada a ninguém e não use dados de cliente real. O exercício usa cliente fictício.';
    $L[] = 'Sem acesso à pasta ou à planilha: e-mail para sergio.duh.design@gmail.com (SEM o +sdia), assunto [SD-IA BLOQUEIO] ' . $chamado . ', com o motivo.';
    return implode("\n", $L);
}
function pdChamadosHoje() {
    $ini = gmdate('Y-m-d\TH:i:s\Z', strtotime(date('Y-m-d') . ' 00:00:00 -03:00'));
    $r = pdAulaSup('/rest/v1/pd_aulas?modo=neq.teste&chamado->>ultimo_envio=gte.' . urlencode($ini) . '&select=id'); return is_array($r['body'] ?? null) ? count($r['body']) : 0;
}
function pdProfessorEnviar($a, $sk, $cfg, $manual = false) {
    if (!pdPonteOk()) return ['ok' => false, 'msg' => 'A ponte do Drive não está configurada (SD_PONTE_URL / SD_PONTE_KEY).'];
    if (!function_exists('ponte') || !function_exists('emailIA')) require_once __DIR__ . '/pd-lib.php';
    $cap = (int)($cfg['chamados_dia'] ?? 5);
    if ($a['modo'] !== 'teste' && !$manual && pdChamadosHoje() >= $cap) return ['ok' => false, 'msg' => 'Limite de ' . $cap . ' chamados ao dia atingido; esta aula espera o próximo dia.'];
    $ent = (array)$a['entregas']; $ch0 = (array)($a['chamado'] ?? []);
    try {
        if (!empty($ch0['folderId'])) { $p = $ch0; }   // nova rodada ou reenvio manual: mesma pasta
        else {
            $nome = ($a['modo'] === 'teste' ? 'AULA-TESTE · ' : 'AULA · ') . ($a['personagem'] ?: 'ia') . ' · ' . $a['skill_id'] . ' · ' . substr($a['id'], 0, 8);
            $p = ponte('preparar', ['folderId' => null, 'parentId' => (defined('SD_ESCOLA_DRIVE_ID') && SD_ESCOLA_DRIVE_ID) ? SD_ESCOLA_DRIVE_ID : null, 'nome' => mb_substr($nome, 0, 120),
                'briefing' => ['tarefa' => 'AULA · ' . $a['skill_id'], 'cliente' => '(fictício)', 'projeto' => ($sk['nome'] ?? $a['skill_id']),
                               'pedido' => "ISTO É UMA AULA, não produção. Você (dot) é só o TRANSPORTE; o professor é o GPT do Projeto do ChatGPT. Leia o 00_BRIEFING_IA.txt desta pasta. Ignore os passos 2–4 abaixo.", 'referencia' => '']]);
        }
        $chamado = 'AULA-' . substr($a['id'], 0, 8) . '-R' . $a['rodada'] . '-' . date('ymdHis');
        $texto = pdProfessorTexto($a, $sk, $cfg, $chamado);
        $g = fn($pasta, $nome, $bytes, $mime) => ponte('gravar', ['folderId' => $pasta, 'nome' => $nome, 'b64' => base64_encode($bytes), 'mime' => $mime]);
        foreach ($ent as $e) {   // TODAS as versões ficam na pasta (a atual e as anteriores)
            $r = (int)$e['rodada']; if ($r !== (int)$a['rodada'] && !empty($ch0['folderId'])) continue;
            $g($p['entradaId'], 'entrega-R' . $r . '.svg', (string)($e['svg'] ?? ''), 'image/svg+xml');
            $png = pdSvgPng((string)($e['svg'] ?? '')); if ($png) $g($p['entradaId'], 'entrega-R' . $r . '.png', base64_decode($png), 'image/png');
        }
        $g($p['entradaId'], 'aula.json', json_encode(pdAulaJson($a, $sk, $cfg, $chamado), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), 'text/plain');
        $g($p['folderId'], '00_FORMATO_CORRECAO.json', json_encode(pdModeloCorrecao($a, $chamado, $cfg), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), 'text/plain');
        $g($p['folderId'], '00_BRIEFING_IA.txt', "\xEF\xBB\xBF" . $texto, 'text/plain');
        $g($p['folderId'], '00_CHAMADO_IA.txt', "\xEF\xBB\xBF" . $texto, 'text/plain');
        ponte('status', ['controleId' => $p['controleId'], 'status' => 'CORRIGIR-AULA', 'rodada' => $a['rodada'], 'obs' => $manual ? 'reenvio manual' : 'chamado enviado']);
        $assunto = '[SD-IA] AULA · CORRIGIR · ' . ($a['modo'] === 'teste' ? 'TESTE · ' : '') . ($a['personagem'] ?: 'ia') . ' · ' . $a['skill_id'] . ' · R' . $a['rodada'];
        $ok = @mail(emailIA(), '=?UTF-8?B?' . base64_encode($assunto) . '?=', $texto . "\n\nPasta: " . ($p['folderUrl'] ?? '') . "\nSD Workflow · " . sd_base(),
            "From: SD Workflow <workflow@sergioduh.com>\r\nReply-To: workflow@sergioduh.com\r\nX-SD-Chamado: " . $chamado . "\r\nContent-Type: text/plain; charset=UTF-8");
        $mesma = !empty($ch0['enviado_em']) && (int)($ch0['rodada'] ?? 0) === (int)$a['rodada'];
        pdAulaSup('/rest/v1/pd_aulas?id=eq.' . urlencode($a['id']), 'PATCH', ['chamado' => ['chamado' => $chamado, 'folderId' => $p['folderId'], 'folderUrl' => $p['folderUrl'] ?? '', 'entradaId' => $p['entradaId'], 'saidaId' => $p['saidaId'], 'controleId' => $p['controleId'],
            'rodada' => (int)$a['rodada'], 'enviado_em' => $mesma ? $ch0['enviado_em'] : gmdate('c'), 'ultimo_envio' => gmdate('c'), 'envios' => ((int)($ch0['envios'] ?? 0)) + 1, 'email' => (bool)$ok, 'erro' => null]]);
        return ['ok' => true, 'msg' => ($manual ? 'Aviso reenviado ao dot.' : 'Aviso enviado ao dot') . ' (e-mail + pasta no Drive). A aula fica AGUARDANDO o professor.'];
    } catch (Throwable $e) { error_log('professor chamado: ' . $e->getMessage()); return ['ok' => false, 'msg' => 'Não consegui avisar o dot: ' . $e->getMessage()]; }
}
/** Valida o CORRECAO.json contra o contrato pd-correcao/2. Devolve [true, correção_normalizada] ou [false, motivo]. */
function pdCorrecaoValida($j, $a, $ch, $cfg) {
    if (!is_array($j)) return [false, 'arquivo não é um JSON válido'];
    if (($j['contrato'] ?? '') !== PD_CONTRATO_CORRECAO) return [false, 'contrato diferente de ' . PD_CONTRATO_CORRECAO];
    if (($j['aula_id'] ?? '') !== $a['id']) return [false, 'aula_id não bate com esta aula'];
    if ((int)($j['rodada'] ?? 0) !== (int)$a['rodada']) return [false, 'rodada não bate (esperada ' . $a['rodada'] . ')'];
    if (($j['chamado'] ?? '') !== ($ch['chamado'] ?? '')) return [false, 'chamado não bate com o último enviado'];
    $o = (array)($j['origem'] ?? []);
    if (($o['papel'] ?? '') !== 'professor') return [false, 'origem.papel precisa ser "professor" (o dot é só o transporte)'];
    if (($o['fonte'] ?? '') !== 'chatgpt_projeto') return [false, 'origem.fonte precisa ser "chatgpt_projeto"'];
    if (trim((string)($o['projeto'] ?? '')) === '') return [false, 'origem.projeto vazio (qual Projeto do ChatGPT deu o parecer?)'];
    $pp = (array)($j['parecer_do_professor'] ?? []);
    $txt = trim((string)($pp['texto_do_professor'] ?? '')); if (mb_strlen($txt) < 60) return [false, 'texto_do_professor ausente ou curto demais (cole o parecer do professor, sem resumir)'];
    if (!isset($pp['nota']) || !is_numeric($pp['nota']) || $pp['nota'] < 0 || $pp['nota'] > 100) return [false, 'nota ausente ou fora de 0–100'];
    $pb = array_values(array_filter((array)($pp['problemas'] ?? []), fn($x) => is_array($x) && trim((string)($x['problema'] ?? '')) !== '' && trim((string)($x['motivo'] ?? '')) !== ''));
    $min = (float)($cfg['nota_minima'] ?? 85); $aprov = !empty($pp['aprovado']) && (float)$pp['nota'] >= $min;
    if (!$aprov && !$pb) return [false, 'parecer sem problemas específicos (problema + motivo + correcao)'];
    $fb = ''; foreach ($pb as $i => $x) $fb .= ($i + 1) . '. ' . trim((string)$x['problema']) . "\n   Motivo: " . trim((string)$x['motivo']) . (trim((string)($x['correcao'] ?? '')) !== '' ? "\n   Corrigir: " . trim((string)$x['correcao']) : '') . "\n";
    $l = (array)($j['licao_sugerida'] ?? []); $esc = in_array($l['escopo'] ?? '', ['habilidade', 'universal', 'cliente'], true) ? $l['escopo'] : 'habilidade';
    return [true, ['nota' => (float)$pp['nota'], 'aprovado' => $aprov, 'feedback' => trim($fb) ?: $txt, 'pontos_fortes' => (string)($pp['pontos_fortes'] ?? ''), 'texto_do_professor' => $txt,
        'licao' => trim((string)($l['regra'] ?? '')) !== '' && stripos((string)$l['regra'], '<') !== 0 ? ['regra' => $l['regra'], 'escopo' => $esc, 'exemplo_bom' => (string)($l['exemplo_bom'] ?? ''), 'exemplo_ruim' => (string)($l['exemplo_ruim'] ?? '')] : null,
        '_origem' => ['papel' => 'professor', 'fonte' => 'chatgpt_projeto', 'projeto' => $o['projeto'], 'transportado_por' => (string)($o['transportado_por'] ?? 'dot')]]];
}
/** Um passo: manda o aviso ao dot, ou confere se o professor já respondeu. Sem resposta = AGUARDANDO (nunca “corrigida”). */
function pdProfessorPasso($a, $sk, $cfg) {
    $ch = (array)($a['chamado'] ?? []);
    foreach ((array)$a['correcoes'] as $c) if ((int)($c['rodada'] ?? 0) === (int)$a['rodada']) return ['ok' => false, 'msg' => 'Esta rodada já tem correção registrada (nada repetido).'];
    if (empty($ch['folderId']) || (int)($ch['rodada'] ?? 0) !== (int)$a['rodada']) return pdProfessorEnviar($a, $sk, $cfg);
    try {
        $st = strtoupper(trim((string)(ponte('ler_status', ['controleId' => $ch['controleId']])['status'] ?? '')));
        $min = round((time() - strtotime($ch['enviado_em'] ?? 'now')) / 60); $ha = $min < 60 ? $min . ' min' : round($min / 60, 1) . ' h';
        if (strpos($st, 'TERMINEI') !== 0) {
            if (strpos($st, 'AGUARDANDO-PROFESSOR') === 0) return ['ok' => false, 'msg' => 'O dot avisou: o professor ainda não respondeu (há ' . $ha . '). A aula segue aguardando.'];
            return ['ok' => false, 'msg' => 'Aguardando o dot e o professor (aviso há ' . $ha . ').'];
        }
        $arq = ponte('listar', ['folderId' => $ch['saidaId']])['arquivos'] ?? []; $f = null;
        foreach ($arq as $x) if (preg_match('/^correc/i', $x['name'])) { if (!$f || $x['modified'] > $f['modified']) $f = $x; }
        $rej = function ($motivo) use ($ch, $a) { ponte('status', ['controleId' => $ch['controleId'], 'status' => 'CORRECAO REJEITADA: ' . $motivo . ' — dot: corrija o CORRECAO.json (ou escreva AGUARDANDO-PROFESSOR)', 'rodada' => $a['rodada']]);
            pdAulaSup('/rest/v1/pd_aulas?id=eq.' . urlencode($a['id']), 'PATCH', ['chamado' => array_merge($ch, ['erro' => $motivo])]); return ['ok' => false, 'msg' => 'Correção rejeitada pela PD: ' . $motivo . '. A aula continua aguardando.']; };
        if (!$f) return $rej('TERMINEI sem CORRECAO.json na SAIDA');
        $j = pdJson(base64_decode((string)(ponte('baixar', ['fileId' => $f['id']])['b64'] ?? '')));
        [$ok, $n] = pdCorrecaoValida($j, $a, $ch, $cfg); if (!$ok) return $rej($n);
        $r = pdAulaAplicarCorrecao($a, $n, 'Professor (GPT do Projeto “' . $n['_origem']['projeto'] . '”) via dot', $cfg);
        ponte('status', ['controleId' => $ch['controleId'], 'status' => 'RECEBIDO PELO SD', 'rodada' => $a['rodada'], 'obs' => 'nota ' . round($n['nota'])]);
        return $r;
    } catch (Throwable $e) { error_log('professor varrer: ' . $e->getMessage()); return ['ok' => false, 'msg' => 'Falha ao conferir a resposta: ' . $e->getMessage()]; }
}
/** Relógio: confere as aulas que já foram levadas ao professor (só lê; nunca envia nada novo). */
function pdProfessorVarrer() {
    $cfg = pdIncub() ?: []; if (($cfg['professor_modo'] ?? 'projeto_gpt') !== 'projeto_gpt' || !pdPonteOk()) return null;
    $as = pdAulaSup('/rest/v1/pd_aulas?status=eq.em_correcao&select=*&limit=20')['body'] ?? []; $out = [];
    foreach ((array)$as as $a) {
        if (empty($a['chamado']['folderId']) || (int)($a['chamado']['rodada'] ?? 0) !== (int)$a['rodada']) continue;
        $sk = pdAulaSup('/rest/v1/pd_habilidades?skill_id=eq.' . urlencode($a['skill_id']) . '&ativa=eq.true&order=versao.desc&limit=1&select=*')['body'][0] ?? [];
        $r = pdProfessorPasso($a, $sk, $cfg); if (!empty($r['ok'])) $out[] = $r['msg'];
    }
    return $out ? implode(' | ', $out) : null;
}

/** Aplica o parecer do professor: nota, lição PROPOSTA, próximo estado. Aprovar é decisão do Sérgio. */
function pdAulaAplicarCorrecao($a, $j, $prof, $cfg = null) {
    $id = $a['id']; $cfg = $cfg ?: (pdIncub() ?: ['nota_minima' => 85]); $cor = (array)$a['correcoes']; $teste = ($a['modo'] ?? '') === 'teste';
    foreach ($cor as $c) if ((int)($c['rodada'] ?? 0) === (int)$a['rodada']) return ['ok' => false, 'msg' => 'Esta rodada já tem correção registrada.'];
    $patch = fn($d) => pdAulaSup('/rest/v1/pd_aulas?id=eq.' . urlencode($id), 'PATCH', $d);
    $nota = max(0, min(100, (float)($j['nota'] ?? 0))); $o = (array)($j['_origem'] ?? []);
    $aprovou = array_key_exists('aprovado', $j) ? !empty($j['aprovado']) : $nota >= (float)$cfg['nota_minima'];
    $cor[] = ['rodada' => (int)$a['rodada'], 'nota' => $nota, 'feedback' => (string)($j['feedback'] ?? ''), 'fortes' => (string)($j['pontos_fortes'] ?? ''), 'professor' => $prof,
              'origem' => $o, 'texto_do_professor' => (string)($j['texto_do_professor'] ?? ''), 'em' => gmdate('c')];
    if ($teste) {   // rodada de teste da ponte: prova que a PD reconheceu o parecer; sem nota, sem lição
        $patch(['correcoes' => $cor, 'status' => 'validacao_humana', 'nota_final' => $nota]);
        pdAulaSup('/rest/v1/pd_incubacao?id=eq.1', 'PATCH', ['teste_reconhecido_em' => gmdate('c'), 'teste_aula_id' => $id]);
        return ['ok' => true, 'msg' => 'PONTE RECONHECIDA: a PD leu o parecer do professor (' . $prof . '). Falta a sua confirmação para liberar a rotina.'];
    }
    pdAulaSup('/rest/v1/pd_notas', 'POST', ['ia_user_id' => $a['ia_user_id'], 'personagem' => $a['personagem'], 'skill_id' => $a['skill_id'], 'fonte' => 'professor', 'nota' => $nota, 'aula_id' => $id]);
    if ($aprovou && $nota >= (float)$cfg['nota_minima']) {
        $l = $j['licao'] ?? null;
        pdAulaSup('/rest/v1/pd_licoes?aula_id=eq.' . urlencode($id) . '&status=eq.proposta', 'DELETE');
        if (is_array($l) && !empty($l['regra'])) pdAulaSup('/rest/v1/pd_licoes', 'POST', ['ia_user_id' => $a['ia_user_id'], 'personagem' => $a['personagem'], 'skill_id' => $a['skill_id'], 'regra' => mb_substr($l['regra'], 0, 600),
            'escopo' => in_array($l['escopo'] ?? '', ['habilidade', 'universal', 'cliente'], true) ? $l['escopo'] : 'habilidade', 'exemplo_bom' => mb_substr((string)($l['exemplo_bom'] ?? ''), 0, 400), 'exemplo_ruim' => mb_substr((string)($l['exemplo_ruim'] ?? ''), 0, 400), 'aula_id' => $id]);
        $patch(['correcoes' => $cor, 'status' => 'validacao_humana', 'nota_final' => $nota]); return ['ok' => true, 'msg' => 'Nota ' . $nota . ' — o professor aprovou; aguarda a SUA decisão.'];
    }
    if ((int)$a['rodada'] >= (int)$a['max_rodadas']) { $patch(['correcoes' => $cor, 'status' => 'reprovada', 'nota_final' => $nota]); return ['ok' => true, 'msg' => 'Nota ' . $nota . ' — esgotou as rodadas; reprovada.']; }
    $patch(['correcoes' => $cor, 'status' => 'em_exercicio', 'rodada' => (int)$a['rodada'] + 1]); return ['ok' => true, 'msg' => 'Nota ' . $nota . ' — o professor pediu ajustes; a aluna refaz (rodada ' . ((int)$a['rodada'] + 1) . ').'];
}

/** Sérgio decide (decisão FINAL): 'aprovar' · 'revisar' (comentário e a aluna refaz) · 'reprovar'. */
function pdAulaValidar($id, $decisao, $parecer, $porId, $licaoRegra = null) {
    if ($decisao === true) $decisao = 'aprovar'; if ($decisao === false) $decisao = 'reprovar';
    $a = pdAulaSup('/rest/v1/pd_aulas?id=eq.' . urlencode($id) . '&select=*')['body'][0] ?? null; if (!$a) return false;
    $teste = ($a['modo'] ?? '') === 'teste';
    if ($decisao === 'revisar' && !$teste) {
        $cor = (array)$a['correcoes']; if ($cor) { $k = count($cor) - 1; $cor[$k]['humano'] = mb_substr((string)$parecer, 0, 800); }
        pdAulaSup('/rest/v1/pd_aulas?id=eq.' . urlencode($id), 'PATCH', ['correcoes' => $cor, 'status' => 'em_exercicio', 'rodada' => (int)$a['rodada'] + 1, 'max_rodadas' => max((int)$a['max_rodadas'], (int)$a['rodada'] + 1), 'nota_final' => null]);
        return true;
    }
    $aprovar = $decisao === 'aprovar';
    pdAulaSup('/rest/v1/pd_aulas?id=eq.' . urlencode($id), 'PATCH', ['status' => $aprovar ? 'validada' : 'reprovada', 'parecer_humano' => mb_substr((string)$parecer, 0, 800), 'validada_por' => $porId, 'validada_em' => gmdate('c')]);
    if ($teste) { if ($aprovar) pdAulaSup('/rest/v1/pd_incubacao?id=eq.1', 'PATCH', ['teste_confirmado_em' => gmdate('c')]); return true; }
    if ($aprovar && trim((string)$licaoRegra) !== '') pdAulaSup('/rest/v1/pd_licoes?aula_id=eq.' . urlencode($id) . '&status=eq.proposta', 'PATCH', ['regra' => mb_substr(trim((string)$licaoRegra), 0, 600)]);
    pdAulaSup('/rest/v1/pd_licoes?aula_id=eq.' . urlencode($id) . '&status=eq.proposta', 'PATCH', $aprovar ? ['status' => 'aprovada', 'aprovada_por' => $porId, 'aprovada_em' => gmdate('c')] : ['status' => 'recusada', 'motivo' => mb_substr((string)$parecer, 0, 300)]);
    if ($aprovar && $a['nota_final'] !== null) pdAulaSup('/rest/v1/pd_notas', 'POST', ['ia_user_id' => $a['ia_user_id'], 'personagem' => $a['personagem'], 'skill_id' => $a['skill_id'], 'fonte' => 'humano', 'nota' => $a['nota_final'], 'aula_id' => $id]);
    pdFormacaoAtualiza($a['ia_user_id'], $a['skill_id']);
    return true;
}

// ---------------------------------------------------------------- formação: barra de evolução
/** Provas fechadas (validada = entrega boa; reprovada = ruim), em ordem. Rodada de teste não conta. */
function pdFormacaoSituacao($iaId, $skillId, $cfg = null) {
    $cfg = $cfg ?: (pdIncub() ?: []); $boasPara = (int)($cfg['boas_para_apto'] ?? 3);
    $r = pdAulaSup('/rest/v1/pd_aulas?ia_user_id=eq.' . urlencode($iaId) . '&skill_id=eq.' . urlencode($skillId) . '&modo=neq.teste&status=in.(validada,reprovada)&order=criada_em.asc&select=status&limit=400')['body'] ?? [];
    $fechadas = count($r); $boas = 0; foreach ($r as $x) $boas += $x['status'] === 'validada' ? 1 : 0;
    $seq = 0; foreach (array_reverse($r) as $x) { if ($x['status'] === 'validada') $seq++; else break; }
    return ['hist' => array_map(fn($x) => $x['status'] === 'validada' ? 1 : 0, $r), 'fechadas' => $fechadas, 'boas' => $boas, 'seguidas' => $seq, 'boas_para_apto' => $boasPara, 'apta' => $seq >= $boasPara];
}
function pdFormacaoAtualiza($iaId, $skillId) {
    $f = pdAulaSup('/rest/v1/pd_formacoes?ia_user_id=eq.' . urlencode($iaId) . '&skill_id=eq.' . urlencode($skillId) . '&select=id,apta_em')['body'][0] ?? null; if (!$f) return;
    $sit = pdFormacaoSituacao($iaId, $skillId);
    pdAulaSup('/rest/v1/pd_formacoes?id=eq.' . urlencode($f['id']), 'PATCH', ['apta_em' => $sit['apta'] ? ($f['apta_em'] ?: gmdate('c')) : null]);
}
function pdFormacaoMatricula($iaId, $personagem, $skillId) {
    $r = pdAulaSup('/rest/v1/pd_formacoes?on_conflict=ia_user_id,skill_id', 'POST', ['ia_user_id' => $iaId, 'personagem' => $personagem ?: 'ia', 'skill_id' => $skillId, 'ativa' => true], ['Prefer: resolution=merge-duplicates,return=representation']);
    return $r['body'][0] ?? null;
}

/** Relógio. Só roda a rotina automática depois do TESTE PONTA A PONTA confirmado por você.
 *  Avança uma aula apenas quando há fato novo (entrega nova ou parecer novo); nunca repete análise. */
function pdIncubacaoRelogio() {
    $v = pdProfessorVarrer();   // só LÊ respostas de aulas já levadas ao professor
    $c = pdIncub(); if (!$c || empty($c['ativa'])) return $v ?: 'desligada';
    if (empty($c['teste_confirmado_em'])) return ($v ? $v . ' | ' : '') . 'rotina bloqueada: falta a rodada de teste ponta a ponta confirmada por você';
    if (!pdIncubTemTeto()) return ($v ? $v . ' | ' : '') . 'teto do dia atingido';
    $msg = $v ? [$v] : []; $meta = (int)($c['provas_meta'] ?? 30); $hoje = date('Y-m-d', time() - 3 * 3600);
    $abertas = pdAulaSup('/rest/v1/pd_aulas?modo=neq.teste&status=in.(aberta,em_exercicio,em_correcao,validacao_humana)&select=ia_user_id,skill_id,status,id')['body'] ?? [];
    $ocupado = []; foreach ($abertas as $a) $ocupado[$a['ia_user_id'] . '|' . $a['skill_id']] = 1;
    $forms = pdAulaSup('/rest/v1/pd_formacoes?ativa=eq.true&apta_em=is.null&select=*&limit=60')['body'] ?? [];
    foreach ($forms as $f) {
        $k = $f['ia_user_id'] . '|' . $f['skill_id']; if (isset($ocupado[$k])) continue;
        $sit = pdFormacaoSituacao($f['ia_user_id'], $f['skill_id'], $c); if ($sit['fechadas'] >= (int)($f['meta_provas'] ?: $meta)) continue;
        $ja = pdAulaSup('/rest/v1/pd_aulas?ia_user_id=eq.' . urlencode($f['ia_user_id']) . '&skill_id=eq.' . urlencode($f['skill_id']) . '&modo=neq.teste&dia=eq.' . $hoje . '&select=id&limit=1')['body'] ?? [];
        if ($ja) continue;
        $n = pdAulaCriar($f['ia_user_id'], $f['personagem'], $f['skill_id'], 'prova'); if ($n) { $msg[] = 'prova do dia aberta (' . $f['skill_id'] . ')'; $abertas[] = ['id' => $n['id'], 'status' => 'aberta', 'ia_user_id' => $f['ia_user_id'], 'skill_id' => $f['skill_id']]; }
        if (count($msg) >= 3) break;
    }
    // só avançam as etapas da PD (exercício, versão da aluna, aviso ao dot). Quem espera o professor ou o Sérgio fica parado.
    $n = 0; foreach ($abertas as $a) { if (in_array($a['status'], ['validacao_humana'], true)) continue;
        for ($i = 0; $i < 3; $i++) { $r = pdAulaPasso($a['id']); if (!empty($r['ok'])) $msg[] = $r['msg']; if (empty($r['ok'])) break; } if (++$n >= 3) break; }
    return $msg ? implode(' | ', $msg) : 'nada novo';
}
