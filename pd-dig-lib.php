<?php
// PD · DIG — pedidos do Tumi com resultado próprio. Três tipos: achar | abordar | aquecer.
// achar   = garimpo profundo (busca na web) e entrega dos leads.
// abordar = achar + escrever a abordagem por e-mail dentro de uma JORNADA escolhida (cai na Caixa de saída p/ aprovação).
// aquecer = abordar + quando o lead responde com interesse, a Gigi segue no zap, amarrada na narrativa daquele cliente/produto.
require_once __DIR__ . '/pd-vendas-lib.php';

/** Catálogo das jornadas mais comuns. instr = o que a IA segue ao escrever o 1º e-mail; depois = o que acontece depois da resposta. */
function digJornadas() {
    return [
        ['id' => 'relampago', 'nome' => 'Link direto (ativação relâmpago)', 'modos' => ['abordar', 'aquecer'],
         'quando' => 'Oferta simples e com prazo curto: o lead já recebe o link e decide na hora.',
         'passos' => ['E-mail 1: abertura curta + link direto + prazo', 'Dia +2: lembrete com o mesmo link', 'Dia +5: despedida gentil'],
         'instr' => 'JORNADA "Link direto": o e-mail JÁ traz o link {LINK} como único pedido (um botão/linha de ação clara) e um prazo curto e REAL se houver; sem pedir reunião.',
         'campos' => ['link', 'modelo_email', 'narrativa']],
        ['id' => 'resposta_link', 'nome' => 'Resposta primeiro, link depois', 'modos' => ['abordar', 'aquecer'],
         'quando' => 'Quando mandar link frio derruba a entrega: primeiro ganha a resposta, depois manda o link.',
         'passos' => ['E-mail 1: pergunta simples, SEM link', 'Respondeu com interesse: rascunho 2 manda o link', 'Sem resposta em 3 dias: follow-up com ângulo novo'],
         'instr' => 'JORNADA "Resposta primeiro": o e-mail 1 NÃO leva link nenhum; termina com uma pergunta de resposta fácil (sim/não). Quando o lead responder com interesse, a resposta traz o link {LINK}.',
         'campos' => ['link', 'modelo_email', 'narrativa']],
        ['id' => 'diagnostico', 'nome' => 'Diagnóstico grátis', 'modos' => ['abordar', 'aquecer'],
         'quando' => 'Quando o garimpo achou defeito claro no site/redes: abre com o achado e oferece olhar mais fundo.',
         'passos' => ['E-mail 1: 1 achado verificável do site + oferta do diagnóstico', 'Respondeu: envia o diagnóstico ou o link de agenda', 'Follow-up com 2º achado'],
         'instr' => 'JORNADA "Diagnóstico grátis": abra com UM defeito concreto e verificável do site/redes do lead (de FATOS) e ofereça um mini-diagnóstico gratuito; link/agenda {LINK} só depois da resposta, se não houver link direto pedido.',
         'campos' => ['link', 'modelo_email', 'narrativa']],
        ['id' => 'tres_toques', 'nome' => 'Sequência de 3 toques', 'modos' => ['abordar', 'aquecer'],
         'quando' => 'A cadência clássica: abertura, follow-up com ângulo novo, despedida.',
         'passos' => ['Dia 0: abertura', 'Dia +3: follow-up com ângulo novo', 'Dia +7: despedida que deixa a porta aberta'],
         'instr' => 'JORNADA "3 toques": siga a sequência 1 abertura, 2 follow-up com ângulo novo, 3 despedida; um pedido simples por mensagem.',
         'campos' => ['link', 'modelo_email', 'narrativa']],
        ['id' => 'convite', 'nome' => 'Convite para conversa de 15 min', 'modos' => ['abordar', 'aquecer'],
         'quando' => 'Venda consultiva (ticket maior): o objetivo do e-mail é só marcar a conversa.',
         'passos' => ['E-mail 1: contexto do lead + convite de 15 min com link de agenda', 'Follow-up com 2 horários', 'Despedida'],
         'instr' => 'JORNADA "Convite": o único pedido é uma conversa de 15 minutos; ofereça a agenda {LINK} ou dois horários; nada de proposta no e-mail.',
         'campos' => ['link', 'modelo_email', 'narrativa']],
    ];
}
function digJornada($id) { foreach (digJornadas() as $j) if ($j['id'] === $id) return $j; return null; }

/** Texto de OFERTA que o vdEscrever segue: o que o cliente vende + a jornada + o padrão de e-mail + a narrativa. */
function digOferta($dig) {
    $cfg = is_array($dig['jornada_cfg'] ?? null) ? $dig['jornada_cfg'] : (json_decode((string)($dig['jornada_cfg'] ?? ''), true) ?: []);
    $j = digJornada((string)($dig['jornada'] ?? ''));
    $ac = vdLinhas(vdSup('/rest/v1/pd_vendas_auto?client_id=eq.' . ($dig['client_id'] ?: vdCasaId()) . '&select=oferta&limit=1'))[0] ?? null;
    $o = trim((string)($ac['oferta'] ?? ''));
    if (!empty($cfg['oferta'])) $o = trim($cfg['oferta']) . ($o ? ' · ' . $o : '');
    if ($j) $o .= "\n" . str_replace('{LINK}', trim((string)($cfg['link'] ?? '')) ?: '(link a definir)', $j['instr']);
    if (!empty($cfg['narrativa'])) $o .= "\nNARRATIVA DE ABORDAGEM (siga o espírito, sem copiar literal): " . mb_substr(trim($cfg['narrativa']), 0, 1200);
    if (!empty($cfg['modelo_email'])) $o .= "\nE-MAIL PADRÃO do cliente (imite estrutura e tom; troque os dados pelos do lead): " . mb_substr(trim($cfg['modelo_email']), 0, 1800);
    return $o;
}

/** O que a ferramenta consegue de fato — e, se o pedido pede mais, diz o que NÃO dá e sugere o que dá. */
function digViabilidade($briefing, $modo) {
    $sys = "Você avalia se um pedido de garimpo de leads é REALISTA para esta ferramenta. A ferramenta: faz buscas na web e lê sites públicos e perfis públicos; acha empresas, instituições e pessoas jurídicas com site/redes ativos; lê sinais do site (anúncios, WhatsApp, SEO, tecnologia, lançamentos, defeitos visíveis); acha e-mails e telefones PÚBLICOS publicados no site. "
         . "NÃO consegue: faturamento, número de funcionários ou dados financeiros exatos; e-mail ou celular pessoal de decisor que não esteja público; dados de bases pagas ou fechadas (LinkedIn logado, Receita sob consulta, CRM de terceiros); contato de pessoa física sem publicação própria; garantias de volume (quantidade depende do que existe na web). "
         . "Responda SOMENTE JSON: {\"nivel\":\"ok|parcial|nao\",\"aviso\":\"se parcial ou nao: comece com 'Não consigo esse nível de profundidade' e diga em 1-2 frases QUAL parte não dá; senão vazio\",\"sugestao\":\"o pedido reescrito para o que a ferramenta consegue de verdade, mantendo a intenção (vazio se ok)\"}";
    [$txt, , $err] = pdClaude($sys, "MODO: $modo\nPEDIDO:\n" . mb_substr($briefing, 0, 2500), 500);
    $j = $txt ? pdJson($txt) : null;
    if (!$j || empty($j['nivel'])) return ['nivel' => 'ok', 'aviso' => '', 'sugestao' => '', 'sem_leitura' => true];
    return ['nivel' => in_array($j['nivel'], ['ok', 'parcial', 'nao'], true) ? $j['nivel'] : 'ok', 'aviso' => (string)($j['aviso'] ?? ''), 'sugestao' => (string)($j['sugestao'] ?? '')];
}

/** O que falta configurar para o modo pedido — com o passo a passo. */
function digPendencias($modo, $clientId) {
    $p = [];
    if ($modo === 'abordar' || $modo === 'aquecer') {
        if (!vdCaixaDe($clientId)) $p[] = ['id' => 'email', 'titulo' => 'Falta a caixa de e-mail deste cliente',
            'passos' => ['Abra a aba "Caixa de saída" e escolha este cliente no topo.', 'Em "E-mail dos clientes", clique em Editar.', 'Informe só o e-mail e a senha dele (os servidores são descobertos sozinhos) e clique em "Salvar e testar".', 'Volte aqui e rode a Dig de novo.'], 'aba' => 'caixa'];
    }
    if ($modo === 'aquecer') {
        $p[] = ['id' => 'zap', 'aviso' => true, 'titulo' => 'A Gigi só entra no zap quando o lead responde com interesse',
                'passos' => ['O lead precisa ter telefone/WhatsApp público no site (a Dig já procura).', 'A mensagem da Gigi nasce na Caixa de saída, amarrada na narrativa do cliente, e você aprova.']];
    }
    return $p;
}

function digCriar($b, $uid) {
    $cid = vdUuid($b['client_id'] ?? ''); $briefing = trim((string)($b['briefing'] ?? '')); $modo = (string)($b['modo'] ?? 'achar');
    if (!in_array($modo, ['achar', 'abordar', 'aquecer'], true)) return ['erro' => 'tipo de Dig inválido'];
    if (mb_strlen($briefing) < 15) return ['erro' => 'Conte um pouco mais do que você procura (mínimo de uma frase completa).'];
    $cid = $cid ?: vdCasaId();
    $jor = (string)($b['jornada'] ?? ''); if ($modo !== 'achar' && !digJornada($jor)) return ['erro' => 'Escolha a jornada de abordagem.'];
    $pend = array_values(array_filter(digPendencias($modo, $cid), fn($x) => empty($x['aviso'])));
    if ($pend) return ['erro' => 'pendencia', 'pendencias' => $pend];
    $cfg = is_array($b['jornada_cfg'] ?? null) ? array_intersect_key($b['jornada_cfg'], array_flip(['link', 'modelo_email', 'narrativa', 'oferta'])) : [];
    $nome = (string)(vdLinhas(vdSup('/rest/v1/clients?id=eq.' . $cid . '&select=name&limit=1'))[0]['name'] ?? '');
    $titulo = trim((string)($b['titulo'] ?? '')) ?: mb_substr($briefing, 0, 60);
    $r = vdLinhas(vdSup('/rest/v1/pd_digs', 'POST', ['client_id' => $cid, 'titulo' => mb_substr($titulo, 0, 120), 'briefing' => mb_substr($briefing, 0, 3000), 'modo' => $modo,
        'jornada' => $modo === 'achar' ? null : $jor, 'jornada_cfg' => $cfg, 'quantos' => max(3, min(20, (int)($b['quantos'] ?? 10))), 'status' => 'fila', 'viabilidade' => $b['viabilidade'] ?? null, 'criado_por' => $uid]));
    if (empty($r[0]['id'])) return ['erro' => 'Não consegui criar a Dig (a migração v169 já rodou?).'];
    return ['ok' => true, 'dig' => $r[0], 'cliente' => $nome];
}

/** Roda a Dig: garimpa; se for abordar/aquecer, escreve a abordagem (rascunhos na Caixa de saída). */
function digExecutar($id, $uid) {
    @set_time_limit(280);
    $d = vdLinhas(vdSup('/rest/v1/pd_digs?id=eq.' . $id . '&select=*&limit=1'))[0] ?? null; if (!$d) return ['erro' => 'Dig não encontrada'];
    if ($d['status'] === 'rodando' && !empty($d['iniciado_em']) && time() - strtotime($d['iniciado_em']) < 400) return ['erro' => 'Esta Dig já está rodando'];
    vdSup('/rest/v1/pd_digs?id=eq.' . $id, 'PATCH', ['status' => 'rodando', 'iniciado_em' => gmdate('c'), 'erro' => null]);
    $consulta = $d['briefing'];
    $r = vdDescobrir($consulta, (int)$d['quantos'], $d['client_id'], $uid);
    if (empty($r['ok'])) { vdSup('/rest/v1/pd_digs?id=eq.' . $id, 'PATCH', ['status' => 'erro', 'erro' => mb_substr((string)($r['erro'] ?? 'não consegui'), 0, 400), 'concluido_em' => gmdate('c'), 'duplicados' => (int)($r['duplicados'] ?? 0)]); return ['erro' => $r['erro'] ?? 'não consegui']; }
    $up = ['achados' => (int)$r['novos'], 'duplicados' => (int)($r['duplicados'] ?? 0), 'lote_id' => $r['lote'] ?? null];
    $msgs = 0; $falhas = [];
    if ($d['modo'] !== 'achar' && !empty($r['lote'])) {
        $oferta = digOferta($d);
        $ps = vdLinhas(vdSup('/rest/v1/pd_prospects?lote_id=eq.' . $r['lote'] . '&select=id,nome&order=nota.desc.nullslast&limit=12'));
        foreach ($ps as $p) { $e = vdEscrever($p['id'], 'email', 1, null, $oferta); if (!empty($e['ok'])) $msgs++; else $falhas[] = $p['nome'] . ': ' . ($e['erro'] ?? '?'); }
    }
    $up += ['mensagens' => $msgs, 'status' => ($d['modo'] !== 'achar' && $msgs === 0) ? 'parcial' : 'pronto', 'concluido_em' => gmdate('c'),
            'erro' => $falhas ? mb_substr(implode(' · ', array_slice($falhas, 0, 4)), 0, 400) : null];
    vdSup('/rest/v1/pd_digs?id=eq.' . $id, 'PATCH', $up);
    return ['ok' => true] + $up;
}

/** Passagem para a Gigi: lead de uma Dig "aquecer" respondeu com interesse e tem zap → rascunho de zap na Caixa de saída. */
function digPassarGigi($p, $texto) {
    if (empty($p['lote_id'])) return null;
    $d = vdLinhas(vdSup('/rest/v1/pd_digs?lote_id=eq.' . $p['lote_id'] . '&modo=eq.aquecer&select=*&limit=1'))[0] ?? null; if (!$d) return null;
    $tem = !empty($p['zap']) || !empty(vdLinhas(vdSup('/rest/v1/pd_prospect_contatos?prospect_id=eq.' . $p['id'] . '&zap=not.is.null&opt_out=eq.false&select=id&limit=1')));
    if (!$tem) return ['gigi' => false, 'motivo' => 'lead sem zap'];
    vdSup('/rest/v1/pd_prospects?id=eq.' . $p['id'], 'PATCH', ['ia' => 'gigi']);
    $e = vdEscrever($p['id'], 'zap', 1, $texto, digOferta($d) . "\nVocê é a Gigi e assume a conversa no WhatsApp: o lead acabou de responder o e-mail com interesse; continue a narrativa daquele e-mail, sem repetir a apresentação.");
    return ['gigi' => !empty($e['ok']), 'erro' => $e['erro'] ?? null];
}
