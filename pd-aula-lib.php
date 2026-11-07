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
        CURLOPT_POSTFIELDS => json_encode(['model' => $model, 'max_tokens' => $max, 'system' => $system . (function_exists('pdBlindagem') ? pdBlindagem() : ''), 'messages' => [['role' => 'user', 'content' => $user]]])]);
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
    $usdP = (($u['prompt_tokens'] ?? 0) * 2 + ($u['completion_tokens'] ?? 0) * 8) / 1e6; if (function_exists('pdOpenaiLog')) pdOpenaiLog('professor', $model, $usdP, 'prova/aula');
    return [trim((string)($j['choices'][0]['message']['content'] ?? '')), $usdP, null, 'GPT cru (API · piloto)'];
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
function pdProjetoProfessor($cfg) { return trim((string)($cfg['professor_projeto'] ?? '')) ?: 'Aprendendo a fazer arte de post'; }   // só INTERNO (dica opcional); a instrução ao dot é CLIENTE > TIPO > O QUE FAZER
/** Hierarquia que o dot usa para achar, no GPT do Sérgio, onde esta tarefa se encaixa. */
/** Aula de ARTE de job real (pd-aula-arte-lib.php): a entrega é uma lista de PNGs por peça, não um SVG. */
function pdAulaEhArteJob($a) { return (($a['tipo'] ?? 'arte') === 'arte' && ($a['modo'] ?? '') === 'job'); }
function pdAulaArquivosRodada($a, $r) {
    if (($a['tipo'] ?? 'arte') === 'documento') return ['entrega-R' . $r . '.md'];
    if (pdAulaEhArteJob($a)) { $n = 0; foreach ((array)$a['entregas'] as $e) if ((int)($e['rodada'] ?? 0) === (int)$r) $n = count((array)($e['pecas'] ?? [])); $o = []; for ($i = 1; $i <= max(1, $n); $i++) $o[] = 'entrega-R' . $r . '-' . $i . '.png'; return $o; }
    return ['entrega-R' . $r . '.png', 'entrega-R' . $r . '.svg'];
}
function pdHierarquia($a, $sk) {
    $ex = (array)($a['exercicio'] ?? []); $cf = (array)($ex['cliente_ficticio'] ?? []);
    if (($a['tipo'] ?? 'arte') === 'documento') return ['cliente' => trim((string)($ex['cliente_real'] ?? '')) ?: 'Cliente da casa', 'tipo' => ($sk['nome'] ?? $a['skill_id']), 'o_que_fazer' => 'Avaliar a versão R' . $a['rodada'] . ' do documento da aluna ' . ($a['personagem'] ?: 'IA') . ' (job real' . (!empty($a['task_id']) ? ', tarefa ' . $a['task_id'] : '') . ') e devolver o parecer'];
    if (pdAulaEhArteJob($a)) return ['cliente' => trim((string)($ex['cliente_real'] ?? '')) ?: 'Cliente da casa', 'tipo' => ($sk['nome'] ?? $a['skill_id']), 'o_que_fazer' => 'Avaliar as artes R' . $a['rodada'] . ' da aluna ' . ($a['personagem'] ?: 'IA') . ' (job real, copy aprovada pelo cliente' . (!empty($a['task_id']) ? ', tarefa ' . $a['task_id'] : '') . ') e devolver o parecer'];
    return ['cliente' => trim((string)($cf['nome'] ?? '')) ?: 'Escola das IAs (cliente fictício)', 'tipo' => ($sk['nome'] ?? $a['skill_id']), 'o_que_fazer' => 'Avaliar a entrega da aluna ' . ($a['personagem'] ?: 'IA') . ' (rodada ' . $a['rodada'] . ') e devolver o parecer'];
}

/** Cada passagem é identificada: aula + rodada + chamado. */
function pdAulaJson($a, $sk, $cfg, $chamado) {
    $hist = []; $ent = (array)$a['entregas']; $cor = (array)$a['correcoes'];
    foreach ($ent as $e) { $r = (int)$e['rodada']; $c = null; foreach ($cor as $x) if ((int)($x['rodada'] ?? 0) === $r) $c = $x;
        $hist[] = ['rodada' => $r, 'arquivos' => pdAulaArquivosRodada($a, $r), 'entregue_em' => $e['em'] ?? null,
                   'parecer_do_professor' => $c ? ['nota' => $c['nota'] ?? null, 'feedback' => $c['feedback'] ?? '', 'texto_do_professor' => $c['texto_do_professor'] ?? '', 'origem' => $c['professor'] ?? ''] : null,
                   'decisao_do_sergio' => $c && !empty($c['humano']) ? $c['humano'] : null]; }
    return ['contrato' => PD_CONTRATO_AULA, 'aula_id' => $a['id'], 'rodada' => (int)$a['rodada'], 'max_rodadas' => (int)$a['max_rodadas'], 'chamado' => $chamado, 'modo' => $a['modo'],
        'aluna' => ['personagem' => $a['personagem'], 'ia_user_id' => $a['ia_user_id']],
        'habilidade' => ['skill_id' => $a['skill_id'], 'nome' => $sk['nome'] ?? '', 'versao' => $sk['versao'] ?? 1, 'finalidade' => $sk['finalidade'] ?? '', 'criterios' => $sk['criterios'] ?? []],
        'exercicio' => ['autor' => ($a['exercicio']['autor'] ?? 'PD')] + (array)$a['exercicio'],
        'versao_atual' => ['rodada' => (int)$a['rodada'], 'arquivos' => pdAulaArquivosRodada($a, (int)$a['rodada'])],
        'tipo' => $a['tipo'] ?? 'arte', 'job_real' => (($a['tipo'] ?? 'arte') === 'documento' || pdAulaEhArteJob($a)) ? ['tarefa' => $a['task_id'] ?? null, 'cliente' => $a['exercicio']['cliente_real'] ?? null, 'contexto' => '00_CONTEXTO.md na raiz da pasta', 'afinacao' => $a['contexto']['afinacao'] ?? '', 'pecas' => pdAulaEhArteJob($a) ? ($a['contexto']['pecas'] ?? []) : null] : null,
        'historico' => $hist, 'nota_minima' => (float)($cfg['nota_minima'] ?? 85), 'professor' => ['onde' => 'GPT do Sérgio (o dot localiza pela hierarquia CLIENTE > TIPO > O QUE FAZER)'], 'hierarquia' => pdHierarquia($a, $sk)];
}
function pdModeloCorrecao($a, $chamado, $cfg) {
    return ['contrato' => PD_CONTRATO_CORRECAO, 'aula_id' => $a['id'], 'rodada' => (int)$a['rodada'], 'chamado' => $chamado,
        'origem' => ['papel' => 'professor', 'fonte' => 'chatgpt_projeto', 'projeto' => '<onde no GPT você encontrou/abriu o professor — opcional>', 'transportado_por' => 'dot'],
        'parecer_do_professor' => [
            'texto_do_professor' => '<o parecer COMO O PROFESSOR ESCREVEU, copiado sem resumir e sem reescrever>',
            'nota' => 0, 'aprovado' => false,
            'problemas' => [['problema' => '<problema específico da peça>', 'motivo' => '<por que é um problema>', 'correcao' => '<como corrigir>']],
            'pontos_fortes' => ''],
        'licao_sugerida' => ['regra' => '<uma regra geral reutilizável, ou deixe vazio>', 'escopo' => 'habilidade|universal|cliente', 'exemplo_bom' => '', 'exemplo_ruim' => '']];
}
function pdProfessorTexto($a, $sk, $cfg, $chamado) {
    $h = pdHierarquia($a, $sk); $L = [];
    $L[] = 'ISTO NÃO É PRODUÇÃO: é uma AULA da escola de IAs. Ignore os passos 2–4 da planilha (são de produção).';
    $L[] = 'Chamado: ' . $chamado . ' · Aula: ' . $a['id'] . ' · Rodada ' . $a['rodada'] . ' de ' . $a['max_rodadas'] . ($a['modo'] === 'teste' ? ' · RODADA DE TESTE da ponte (sem efeito sobre a formação)' : '');
    $L[] = 'Aluna: ' . ($a['personagem'] ?: 'IA da casa');
    $L[] = 'CLIENTE: ' . $h['cliente'];
    $L[] = 'TIPO DE TRABALHO: ' . $h['tipo'];
    $L[] = 'O QUE FAZER: ' . $h['o_que_fazer'];
    $L[] = '(Use essa ordem CLIENTE > TIPO > O QUE FAZER para achar, no ChatGPT do Sérgio, a conversa/projeto onde isso se encaixa melhor. Se houver dúvida entre dois lugares, escolha o do mesmo TIPO de trabalho e diga qual escolheu.)';
    $L[] = '';
    $L[] = 'PAPÉIS — leia com atenção:';
    $L[] = '• Você (dot) é o TRANSPORTE. Leva esta aula ao ChatGPT do Sérgio e traz o parecer de volta. Você NÃO é o professor: não julga a arte, não escreve parecer, não completa nem melhora o parecer dele.';
    $L[] = '• O PROFESSOR é o GPT do Sérgio (o que o conhece). Só o parecer dele vale.';
    $L[] = '• O Sérgio decide por último. Só depois da decisão dele uma lição entra no repertório das agentes.';
    $L[] = '';
    $L[] = 'O QUE FAZER:';
    $L[] = '1. Localize o lugar certo no GPT do Sérgio pela hierarquia acima. Entregue a ele, na mesma conversa da aula (se a aula já tem rodadas anteriores, continue a conversa dela): ' . (($a['tipo'] ?? 'arte') === 'documento' ? 'o documento desta rodada (entrega-R' . $a['rodada'] . '.md na pasta ENTRADA) e o 00_CONTEXTO.md' : (pdAulaEhArteJob($a) ? 'as artes desta rodada (' . implode(', ', pdAulaArquivosRodada($a, (int)$a['rodada'])) . ' na pasta ENTRADA) e o 00_CONTEXTO.md' : 'a arte desta rodada (entrega-R' . $a['rodada'] . '.png e .svg na pasta ENTRADA)')) . ', o aula.json (exercício, critérios, histórico das rodadas anteriores e decisões do Sérgio) e as versões anteriores.';
    $L[] = '2. Peça: problemas ESPECÍFICOS da peça, o motivo de cada um e como corrigir; nota 0–100 (aprovada só com nota ≥ ' . (int)($cfg['nota_minima'] ?? 85) . ' e atendendo todos os critérios); pontos fortes; e, se houver, UMA lição geral reutilizável (diga se é da habilidade, universal ou específica de cliente).';
    $L[] = '3. Copie o parecer DELE, sem resumir nem reescrever, para o campo texto_do_professor do arquivo.';
    $L[] = '4. Grave SAIDA/CORRECAO.json exatamente no formato de 00_FORMATO_CORRECAO.json (inclua aula_id, rodada e chamado EXATOS deste chamado e a origem real). Depois escreva TERMINEI ' . $chamado . ' na célula B2 da planilha SD_CONTROLE.';
    $L[] = '';
    $L[] = 'SE O PROFESSOR NÃO RESPONDEU (ou você não conseguiu levar a aula ao Projeto): escreva AGUARDANDO-PROFESSOR na célula B2 e NÃO crie CORRECAO.json. Nunca invente um parecer para fechar a aula. A PD mantém a aula como “aguardando”.';
    if (($a['tipo'] ?? 'arte') === 'documento') {
        $L[] = 'ESTA É UMA AULA DE DOCUMENTO DE JOB REAL: o cliente é real e a entrega é texto (ENTRADA/entrega-R' . $a['rodada'] . '.md). Leia antes o 00_CONTEXTO.md (ficha do cliente, produtos, briefing da tarefa, afinação do dono da tarefa) — o parecer precisa considerar esse contexto.';
        $L[] = 'Não produza a nova versão pela aluna: aponte problema, motivo e correção; pode sugerir frase, pergunta, referência ou dois caminhos. A nova versão é dela. Até ' . $a['max_rodadas'] . ' rodadas nesta noite; o que ficar em aberto vai destacado para a equipe de manhã.';
        $L[] = 'Se a professora for o próprio GPT com acesso ao Drive (sem dot), ela mesma grava SAIDA/CORRECAO.json e escreve TERMINEI ' . $chamado . ' na célula B2 — transportado_por: "gpt".';
        $L[] = 'OU RESPONDA POR E-MAIL: responda a este aviso (ou mande um e-mail novo para ' . emailIA() . ' com o assunto [SD-IA] CORRECAO · ' . $chamado . ') com o CORRECAO.json inteiro no corpo ou em anexo. A PD lê em até 5 minutos. O chamado dentro do JSON tem de ser exatamente ' . $chamado . '.';
    } elseif (pdAulaEhArteJob($a)) {
        $L[] = 'ESTA É UMA AULA DE ARTE DE JOB REAL (segundo plano): o cliente é real, a copy já foi aprovada por ele e o designer humano produz a mesma peça em paralelo. A aluna gerou uma imagem por peça (ENTRADA/entrega-R' . $a['rodada'] . '-<n>.png); o 00_CONTEXTO.md traz a marca, a tipologia e o texto exato de cada peça.';
        $L[] = 'Avalie cada peça: o título está exatamente como a copy aprovada e legível? A estética é coerente com o mercado imobiliário brasileiro e com a marca (cor, tipologia)? Há logo, preço ou dado inventado? Aponte problema, motivo e correção por peça; não produza a arte pela aluna. Até ' . $a['max_rodadas'] . ' rodadas nesta noite; de manhã o Sérgio compara master × PD.';
        $L[] = 'OU RESPONDA POR E-MAIL: responda a este aviso (ou mande um e-mail novo para ' . emailIA() . ' com o assunto [SD-IA] CORRECAO · ' . $chamado . ') com o CORRECAO.json inteiro no corpo ou em anexo. O chamado dentro do JSON tem de ser exatamente ' . $chamado . '.';
    } else $L[] = 'Não produza a arte pela aluna, não envie nada a ninguém e não use dados de cliente real. O exercício usa cliente fictício.';
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
            $nome = ($a['modo'] === 'teste' ? 'AULA-TESTE · ' : ((($a['tipo'] ?? 'arte') === 'documento' || pdAulaEhArteJob($a)) ? 'AULA JOB · ' . (pdHierarquia($a, $sk)['cliente']) . ' · ' : 'AULA · ')) . ($a['personagem'] ?: 'ia') . ' · ' . $a['skill_id'] . ' · ' . substr($a['id'], 0, 8);
            $p = ponte('preparar', ['folderId' => null, 'parentId' => (defined('SD_ESCOLA_DRIVE_ID') && SD_ESCOLA_DRIVE_ID) ? SD_ESCOLA_DRIVE_ID : null, 'nome' => mb_substr($nome, 0, 120),
                'briefing' => ['tarefa' => 'AULA · ' . $a['skill_id'], 'cliente' => pdHierarquia($a, $sk)['cliente'], 'projeto' => ($sk['nome'] ?? $a['skill_id']),
                               'pedido' => "ISTO É UMA AULA, não produção. Você (dot) é só o TRANSPORTE; o professor é o GPT do Sérgio. Leia o 00_BRIEFING_IA.txt desta pasta. Ignore os passos 2–4 abaixo." . ((($a['tipo'] ?? 'arte') === 'documento') ? ' Aula de DOCUMENTO de job real: leia também o 00_CONTEXTO.md.' : ''), 'referencia' => '']]);
        }
        $chamado = 'AULA-' . substr($a['id'], 0, 8) . '-R' . $a['rodada'] . '-' . date('ymdHis');
        $texto = pdProfessorTexto($a, $sk, $cfg, $chamado);
        $g = fn($pasta, $nome, $bytes, $mime) => ponte('gravar', ['folderId' => $pasta, 'nome' => $nome, 'b64' => base64_encode($bytes), 'mime' => $mime]);
        $doc = ($a['tipo'] ?? 'arte') === 'documento'; $arteJob = pdAulaEhArteJob($a);
        if ($doc && empty($ch0['folderId']) && function_exists('pdAulaDocContextoTexto')) $g($p['folderId'], '00_CONTEXTO.md', "\xEF\xBB\xBF# Contexto da aula (job real)\n\n" . pdAulaDocContextoTexto((array)($a['contexto'] ?? [])), 'text/plain');
        if ($arteJob && empty($ch0['folderId'])) { $cx = (array)($a['contexto'] ?? []); $L = ['CLIENTE: ' . ($cx['cliente'] ?? ''), 'COR DA MARCA: ' . ($cx['cor'] ?? '—'), 'TIPOLOGIA: ' . ($cx['tipologia'] ?? '—'), 'REFERÊNCIAS (Drive): ' . ($cx['refs'] ?? '—')];
            foreach ((array)($cx['pecas'] ?? []) as $i => $pc) $L[] = "\nPEÇA " . ($i + 1) . ' [' . ($pc['kind'] ?? '') . "]\nTÍTULO (texto exato na arte): " . ($pc['titulo'] ?? '') . "\nCTA: " . ($pc['cta'] ?? '—') . "\nLEGENDA: " . ($pc['texto'] ?? '');
            if (!empty($cx['ficha'])) $L[] = "\nFICHA DO CLIENTE:\n" . $cx['ficha'];
            $g($p['folderId'], '00_CONTEXTO.md', "\xEF\xBB\xBF# Contexto da aula de arte (job real)\n\n" . implode("\n", $L), 'text/plain'); }
        foreach ($ent as $e) {   // TODAS as versões ficam na pasta (a atual e as anteriores)
            $r = (int)$e['rodada']; if ($r !== (int)$a['rodada'] && !empty($ch0['folderId'])) continue;
            if ($doc) { $g($p['entradaId'], 'entrega-R' . $r . '.md', "\xEF\xBB\xBF" . (string)($e['texto'] ?? ''), 'text/plain'); continue; }
            if ($arteJob || !empty($e['pecas'])) {   // aula de arte de job real: um PNG por peça (png_b64 em memória, ou baixado do storage)
                foreach ((array)($e['pecas'] ?? []) as $i => $pc) { $bytes = !empty($pc['png_b64']) ? base64_decode((string)$pc['png_b64']) : (!empty($pc['url']) && function_exists('pdArteBaixa') ? pdArteBaixa($pc['url']) : null);
                    if ($bytes) $g($p['entradaId'], 'entrega-R' . $r . '-' . (int)($pc['i'] ?? ($i + 1)) . '.png', $bytes, 'image/png'); }
                continue; }
            $g($p['entradaId'], 'entrega-R' . $r . '.svg', (string)($e['svg'] ?? ''), 'image/svg+xml');
            $png = pdSvgPng((string)($e['svg'] ?? '')); if ($png) $g($p['entradaId'], 'entrega-R' . $r . '.png', base64_decode($png), 'image/png');
        }
        $g($p['entradaId'], 'aula.json', json_encode(pdAulaJson($a, $sk, $cfg, $chamado), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), 'text/plain');
        $g($p['folderId'], '00_FORMATO_CORRECAO.json', json_encode(pdModeloCorrecao($a, $chamado, $cfg), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), 'text/plain');
        $g($p['folderId'], '00_BRIEFING_IA.txt', "\xEF\xBB\xBF" . $texto, 'text/plain');
        $g($p['folderId'], '00_CHAMADO_IA.txt', "\xEF\xBB\xBF" . $texto, 'text/plain');
        ponte('status', ['controleId' => $p['controleId'], 'status' => 'CORRIGIR-AULA', 'rodada' => $a['rodada'], 'obs' => $manual ? 'reenvio manual' : 'chamado enviado']);
        $assunto = '[SD-IA] AULA · CORRIGIR · ' . ($a['modo'] === 'teste' ? 'TESTE · ' : '') . ($a['personagem'] ?: 'ia') . ' · ' . $a['skill_id'] . ' · R' . $a['rodada'];
        $ok = @mail(emailIA(), '=?UTF-8?B?' . base64_encode($assunto) . '?=', $texto . "\n\nPasta: " . ($p['folderUrl'] ?? '') . "\nPontodot · " . sd_base(),
            "From: SD Workflow <workflow@sergioduh.com>\r\nReply-To: workflow@sergioduh.com\r\nX-SD-Chamado: " . $chamado . "\r\nContent-Type: text/plain; charset=UTF-8");
        $mesma = !empty($ch0['enviado_em']) && (int)($ch0['rodada'] ?? 0) === (int)$a['rodada'];
        pdAulaSup('/rest/v1/pd_aulas?id=eq.' . urlencode($a['id']), 'PATCH', ['chamado' => ['chamado' => $chamado, 'folderId' => $p['folderId'], 'folderUrl' => $p['folderUrl'] ?? '', 'entradaId' => $p['entradaId'], 'saidaId' => $p['saidaId'], 'controleId' => $p['controleId'],
            'rodada' => (int)$a['rodada'], 'enviado_em' => $mesma ? $ch0['enviado_em'] : gmdate('c'), 'ultimo_envio' => gmdate('c'), 'envios' => ((int)($ch0['envios'] ?? 0)) + 1, 'email' => (bool)$ok, 'erro' => null]]);
        return ['ok' => true, 'msg' => ($manual ? 'Aviso reenviado ao dot.' : 'Aviso enviado ao dot') . ' (e-mail + pasta no Drive). A aula fica AGUARDANDO o professor.'];
    } catch (Throwable $e) { error_log('professor chamado: ' . $e->getMessage()); return ['ok' => false, 'msg' => 'Não consegui avisar o dot: ' . $e->getMessage()]; }
}
/** Valida o CORRECAO.json contra o contrato pd-correcao/2. Devolve [true, correção_normalizada] ou [false, motivo]. */
// Regra do Sérgio: ≥80 aprova (se os critérios foram atendidos); 78–79 libera COM RESSALVA (nota real + pendências); <78 revisão. Nunca se altera a nota.
const PD_NOTA_APROVA = 80; const PD_NOTA_RESSALVA = 78;
function pdCorrecaoValida($j, $a, $ch, $cfg) {
    if (!is_array($j)) return [false, 'arquivo não é um JSON válido'];
    if (($j['contrato'] ?? '') !== PD_CONTRATO_CORRECAO) return [false, 'contrato diferente de ' . PD_CONTRATO_CORRECAO];
    if (($j['aula_id'] ?? '') !== $a['id']) return [false, 'aula_id não bate com esta aula'];
    if ((int)($j['rodada'] ?? 0) !== (int)$a['rodada']) return [false, 'rodada não bate (esperada ' . $a['rodada'] . ')'];
    if (($j['chamado'] ?? '') !== ($ch['chamado'] ?? '')) return [false, 'chamado não bate com o último enviado'];
    $o = (array)($j['origem'] ?? []);
    if (($o['papel'] ?? '') !== 'professor') return [false, 'origem.papel precisa ser "professor" (o dot é só o transporte)'];
    if (!in_array((string)($o['fonte'] ?? ''), ['chatgpt_projeto', 'chatgpt', 'gpt'], true)) return [false, 'origem.fonte precisa ser "chatgpt_projeto"'];
    $pp = (array)($j['parecer_do_professor'] ?? []);
    $txt = trim((string)($pp['texto_do_professor'] ?? '')); if (mb_strlen($txt) < 60) return [false, 'texto_do_professor ausente ou curto demais (cole o parecer do professor, sem resumir)'];
    if (!isset($pp['nota']) || !is_numeric($pp['nota']) || $pp['nota'] < 0 || $pp['nota'] > 100) return [false, 'nota ausente ou fora de 0–100'];
    $pb = array_values(array_filter((array)($pp['problemas'] ?? []), fn($x) => is_array($x) && trim((string)($x['problema'] ?? '')) !== '' && trim((string)($x['motivo'] ?? '')) !== ''));
    $aprov = !empty($pp['aprovado']) && (float)$pp['nota'] >= PD_NOTA_APROVA; $ress = (float)$pp['nota'] >= PD_NOTA_RESSALVA && (float)$pp['nota'] < PD_NOTA_APROVA;
    if (!$aprov && !$ress && !$pb) return [false, 'parecer sem problemas específicos (problema + motivo + correcao)'];
    $fb = ''; foreach ($pb as $i => $x) $fb .= ($i + 1) . '. ' . trim((string)$x['problema']) . "\n   Motivo: " . trim((string)$x['motivo']) . (trim((string)($x['correcao'] ?? '')) !== '' ? "\n   Corrigir: " . trim((string)$x['correcao']) : '') . "\n";
    $l = (array)($j['licao_sugerida'] ?? []); $esc = in_array($l['escopo'] ?? '', ['habilidade', 'universal', 'cliente'], true) ? $l['escopo'] : 'habilidade';
    return [true, ['nota' => (float)$pp['nota'], 'aprovado' => $aprov, 'ressalva' => $ress, 'feedback' => trim($fb) ?: $txt, 'pontos_fortes' => (string)($pp['pontos_fortes'] ?? ''), 'texto_do_professor' => $txt,
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
            // a professora pode ter respondido por E-MAIL (resposta ao aviso, ou e-mail novo citando o chamado) — a ponte procura no Gmail
            $em = null; try { $em = ponte('email_correcao', ['chamado' => $ch['chamado']]); } catch (Throwable $e) { $em = null; }
            if ($em && !empty($em['achou'])) {
                $j = pdJson((string)$em['json']); [$ok, $n] = pdCorrecaoValida($j, $a, $ch, $cfg);
                if (!$ok) { pdAulaSup('/rest/v1/pd_aulas?id=eq.' . urlencode($a['id']), 'PATCH', ['chamado' => array_merge($ch, ['erro' => 'e-mail: ' . $n])]); return ['ok' => false, 'msg' => 'Parecer por e-mail rejeitado: ' . $n]; }
                $n['_origem']['transportado_por'] = 'email';
                $r = pdAulaAplicarCorrecao($a, $n, 'Professora (' . $n['_origem']['projeto'] . ') por e-mail', $cfg);
                try { ponte('status', ['controleId' => $ch['controleId'], 'status' => 'RECEBIDO PELO SD (e-mail)', 'rodada' => $a['rodada'], 'obs' => 'nota ' . round($n['nota'])]); } catch (Throwable $e) {}
                return $r;
            }
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

/** ESTADO da aula: quem precisa agir, última movimentação, notas por rodada, pendências, erro — e o REGISTRO COMPARTILHADO na pasta do Drive (a professora lê por lá). */
function pdAulaSincroniza($id, $nota = null, $erro = null) {
    $a = pdAulaSup('/rest/v1/pd_aulas?id=eq.' . urlencode($id) . '&select=*')['body'][0] ?? null; if (!$a) return;
    $ch = (array)($a['chamado'] ?? []); $ctx = (array)($a['contexto'] ?? []); $ant = (array)($ctx['estado'] ?? []);
    $quem = 'sistema'; $oque = '';
    if ($a['status'] === 'em_correcao') { $quem = 'professora'; $oque = 'corrigir a R' . (int)$a['rodada'] . ' (chamado ' . ($ch['chamado'] ?? 'ainda não enviado') . ')'; }
    elseif ($a['status'] === 'em_exercicio') { $quem = 'aluna'; $oque = 'refazer e entregar a R' . (int)$a['rodada']; }
    elseif ($a['status'] === 'validacao_humana') { $quem = 'responsável humano'; $oque = 'decidir (aprovar, revisar ou reprovar)'; }
    elseif (in_array($a['status'], ['validada', 'reprovada', 'cancelada'], true)) { $quem = 'ninguém'; $oque = 'encerrada (' . $a['status'] . ')'; }
    $est = ['quem' => $quem, 'precisa' => $oque, 'ultima' => gmdate('c'), 'nota' => $nota, 'erro' => $erro, 'tentativas' => $erro ? (int)($ant['tentativas'] ?? 0) + 1 : 0];
    if (!$erro && $nota !== null && $ant && ($ant['quem'] ?? '') === $quem && ($ant['precisa'] ?? '') === $oque) $est['ultima'] = $ant['ultima'] ?? $est['ultima'];   // sem movimento novo: mantém o horário
    $ctx['estado'] = $est; pdAulaSup('/rest/v1/pd_aulas?id=eq.' . urlencode($id), 'PATCH', ['contexto' => $ctx]);
    if (empty($ch['folderId']) || !function_exists('ponte')) return;
    $notas = []; foreach ((array)$a['correcoes'] as $c) $notas[] = 'R' . (int)$c['rodada'] . ' = ' . round((float)$c['nota']) . (!empty($c['ressalva']) ? ' (com ressalva)' : '');
    $t = "REGISTRO COMPARTILHADO PD ↔ PROFESSORA (atualizado pela PD — não edite)\n\n"
       . 'Aula: ' . $id . "\nCliente: " . (($ctx['cliente'] ?? '') ?: '—') . "\nPersonagem: " . $a['personagem'] . "\nHabilidade: " . $a['skill_id'] . "\nTipo: " . ($a['tipo'] ?? '') . ' · modo ' . ($a['modo'] ?? '') . "\n"
       . 'Rodada atual: R' . (int)$a['rodada'] . ' de ' . (int)$a['max_rodadas'] . "\nEstado: " . $a['status'] . "\nQUEM PRECISA AGIR: " . $quem . ($oque ? ' — ' . $oque : '') . "\n"
       . 'Última movimentação: ' . date('d/m/Y H:i', strtotime($est['ultima']) - 3 * 3600) . " (Brasília)\nNotas: " . ($notas ? implode(' · ', $notas) : 'nenhuma ainda') . "\n"
       . ($a['pendencias'] ? "Pendências:\n" . $a['pendencias'] . "\n" : '') . ($erro ? "ERRO REGISTRADO: " . $erro . ' (a PD tenta de novo sozinha; tentativas ' . $est['tentativas'] . ")\n" : '')
       . "\nRegra de nota: 80+ aprova (critérios atendidos) · 78–79 libera com ressalva · abaixo de 78 revisão · até 3 rodadas por noite · a decisão final é do responsável humano.\n"
       . "Contratos: pd-correcao/2 (CORRECAO.json em SAIDA, com o mesmo chamado e rodada) · pd-curriculo/1 (AULA.json).\n";
    try { ponte('gravar', ['folderId' => $ch['folderId'], 'nome' => '00_STATUS_PD.txt', 'substituir' => true, 'b64' => base64_encode("\xEF\xBB\xBF" . $t), 'mime' => 'text/plain']); } catch (Throwable $e) {}
}
/** Reabre a MESMA aula para a próxima rodada quando ela foi devolvida sem aprovação (nota abaixo da regra) e ainda há rodadas. Idempotente. */
function pdAulaReabrir($id) {
    $a = pdAulaSup('/rest/v1/pd_aulas?id=eq.' . urlencode($id) . '&select=*')['body'][0] ?? null; if (!$a) return ['ok' => false, 'msg' => 'aula não encontrada'];
    $cor = (array)$a['correcoes']; $uc = $cor ? end($cor) : null;
    if (!$uc) return ['ok' => false, 'msg' => 'a aula ainda não tem parecer'];
    if ((float)$uc['nota'] >= PD_NOTA_APROVA) return ['ok' => false, 'msg' => 'a nota já aprova; não há o que refazer'];
    if (count((array)$a['entregas']) > count($cor)) return ['ok' => true, 'msg' => 'a próxima rodada já tem versão; nada a reabrir'];
    $prox = count($cor) + 1; if ($prox > (int)$a['max_rodadas']) return ['ok' => false, 'msg' => 'rodadas esgotadas; segue para decisão humana'];
    $ctx = (array)($a['contexto'] ?? []); $ctx['inicio'] = gmdate('c');
    pdAulaSup('/rest/v1/pd_aulas?id=eq.' . urlencode($id), 'PATCH', ['status' => 'em_exercicio', 'rodada' => $prox, 'entregue_em' => null, 'pendencias' => null, 'nota_final' => null, 'prazo' => pdAulaDocPrazo(), 'contexto' => $ctx]);
    pdAulaSincroniza($id);
    return ['ok' => true, 'msg' => 'aula reaberta na R' . $prox];
}
/** Aplica o parecer do professor: nota, lição PROPOSTA, próximo estado. Aprovar é decisão do Sérgio. */
function pdAulaAplicarCorrecao($a, $j, $prof, $cfg = null) {
    $id = $a['id']; $cfg = $cfg ?: (pdIncub() ?: ['nota_minima' => 85]); $cor = (array)$a['correcoes']; $teste = ($a['modo'] ?? '') === 'teste';
    foreach ($cor as $c) if ((int)($c['rodada'] ?? 0) === (int)$a['rodada']) return ['ok' => false, 'msg' => 'Esta rodada já tem correção registrada.'];
    $patch = fn($d) => pdAulaSup('/rest/v1/pd_aulas?id=eq.' . urlencode($id), 'PATCH', $d);
    $nota = max(0, min(100, (float)($j['nota'] ?? 0))); $o = (array)($j['_origem'] ?? []);
    $aprovou = $nota >= PD_NOTA_APROVA && (array_key_exists('aprovado', $j) ? !empty($j['aprovado']) : true); $ressalva = !$aprovou && $nota >= PD_NOTA_RESSALVA && $nota < PD_NOTA_APROVA;
    $cor[] = ['rodada' => (int)$a['rodada'], 'nota' => $nota, 'feedback' => (string)($j['feedback'] ?? ''), 'fortes' => (string)($j['pontos_fortes'] ?? ''), 'professor' => $prof,
              'origem' => $o, 'texto_do_professor' => (string)($j['texto_do_professor'] ?? ''), 'em' => gmdate('c')];
    if ($teste) {   // rodada de teste da ponte: prova que a PD reconheceu o parecer; sem nota, sem lição
        $patch(['correcoes' => $cor, 'status' => 'validacao_humana', 'nota_final' => $nota]);
        pdAulaSup('/rest/v1/pd_incubacao?id=eq.1', 'PATCH', ['teste_reconhecido_em' => gmdate('c'), 'teste_aula_id' => $id]);
        return ['ok' => true, 'msg' => 'PONTE RECONHECIDA: a PD leu o parecer do professor (' . $prof . '). Falta a sua confirmação para liberar a rotina.'];
    }
    pdAulaSup('/rest/v1/pd_notas', 'POST', ['ia_user_id' => $a['ia_user_id'], 'personagem' => $a['personagem'], 'skill_id' => $a['skill_id'], 'fonte' => 'professor', 'nota' => $nota, 'aula_id' => $id]);
    if ($ressalva) { $cor[count($cor) - 1]['ressalva'] = true; $patch(['correcoes' => $cor, 'status' => 'validacao_humana', 'nota_final' => $nota, 'pendencias' => mb_substr('LIBERADA COM RESSALVA (nota ' . round($nota) . '): ' . (string)($j['feedback'] ?? ''), 0, 1800)]); return ['ok' => true, 'msg' => 'Nota ' . $nota . ' — liberada com ressalva; aguarda a SUA decisão.']; }
    if ($aprovou) {
        $l = $j['licao'] ?? null;
        pdAulaSup('/rest/v1/pd_licoes?aula_id=eq.' . urlencode($id) . '&status=eq.proposta', 'DELETE');
        if (is_array($l) && !empty($l['regra'])) pdAulaSup('/rest/v1/pd_licoes', 'POST', ['ia_user_id' => $a['ia_user_id'], 'personagem' => $a['personagem'], 'skill_id' => $a['skill_id'], 'regra' => mb_substr($l['regra'], 0, 600),
            'escopo' => in_array($l['escopo'] ?? '', ['habilidade', 'universal', 'cliente'], true) ? $l['escopo'] : 'habilidade', 'exemplo_bom' => mb_substr((string)($l['exemplo_bom'] ?? ''), 0, 400), 'exemplo_ruim' => mb_substr((string)($l['exemplo_ruim'] ?? ''), 0, 400), 'aula_id' => $id]);
        $patch(['correcoes' => $cor, 'status' => 'validacao_humana', 'nota_final' => $nota]); return ['ok' => true, 'msg' => 'Nota ' . $nota . ' — o professor aprovou; aguarda a SUA decisão.'];
    }
    if ((int)$a['rodada'] >= (int)$a['max_rodadas']) {
        if (($a['tipo'] ?? 'arte') === 'documento' || pdAulaEhArteJob($a)) { $patch(['correcoes' => $cor, 'status' => 'validacao_humana', 'nota_final' => $nota]); return ['ok' => true, 'msg' => 'Nota ' . $nota . ' — esgotou as rodadas; volta para a equipe com pendências.']; }
        $patch(['correcoes' => $cor, 'status' => 'reprovada', 'nota_final' => $nota]); return ['ok' => true, 'msg' => 'Nota ' . $nota . ' — esgotou as rodadas; reprovada.']; }
    $patch(['correcoes' => $cor, 'status' => 'em_exercicio', 'rodada' => (int)$a['rodada'] + 1]); return ['ok' => true, 'msg' => 'Nota ' . $nota . ' — o professor pediu ajustes; a aluna refaz (rodada ' . ((int)$a['rodada'] + 1) . ').'];
}

/** Sérgio decide (decisão FINAL): 'aprovar' · 'revisar' (comentário e a aluna refaz) · 'reprovar'. */
function pdAulaValidar($id, $decisao, $parecer, $porId, $licaoRegra = null) {
    if ($decisao === true) $decisao = 'aprovar'; if ($decisao === false) $decisao = 'reprovar';
    $a = pdAulaSup('/rest/v1/pd_aulas?id=eq.' . urlencode($id) . '&select=*')['body'][0] ?? null; if (!$a) return false;
    $teste = ($a['modo'] ?? '') === 'teste';
    if ($decisao === 'revisar' && !$teste) {
        $cor = (array)$a['correcoes']; if ($cor) { $k = count($cor) - 1; $cor[$k]['humano'] = mb_substr((string)$parecer, 0, 800); }
        $prox = count($cor) + 1; $ctx = (array)($a['contexto'] ?? []); $ctx['inicio'] = gmdate('c');
        pdAulaSup('/rest/v1/pd_aulas?id=eq.' . urlencode($id), 'PATCH', ['correcoes' => $cor, 'status' => 'em_exercicio', 'rodada' => $prox, 'max_rodadas' => max((int)$a['max_rodadas'], $prox), 'nota_final' => null, 'entregue_em' => null, 'pendencias' => null, 'prazo' => function_exists('pdAulaDocPrazo') ? pdAulaDocPrazo() : null, 'contexto' => $ctx]);
        if (function_exists('pdAulaSincroniza')) pdAulaSincroniza($id);
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
    $abertas = pdAulaSup('/rest/v1/pd_aulas?modo=neq.teste&modo=neq.job&status=in.(aberta,em_exercicio,em_correcao,validacao_humana)&select=ia_user_id,skill_id,status,id')['body'] ?? [];   // aulas de job real têm relógio próprio (documento e arte)
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
