<?php
// =====================================================================
// Pontodot — O ELENCO DAS IAs. Uma fonte só: quem cada IA é, o que faz, o que EXECUTA de verdade e como pedir.
// Toda IA da casa (zap, chat da plataforma, Sumi, Muru, Tumi, Gigi) recebe este texto: ninguém fica sem saber o papel do outro.
// Comunicação é de todos para todos: gente → IA, IA → IA, IA → gente. Pedido entre IAs fica registrado em pd_ia_pedidos (v159).
// =====================================================================
function pdElencoPapeis() {
    return [
        'tumi' => ['nome' => 'Tumi Tua', 'papel' => 'Captação de novos clientes e pesquisa de mercado',
            'faz' => 'Lidera o Dig (garimpo de leads): acha empresas reais na web, lê os sites, dá nota de encaixe, escreve a abertura de cada lead (nada sai sem aprovação de gente). Também pesquisa concorrentes e referências de mercado.',
            'executa' => 'prospectar (achar leads, enriquecer, escrever aberturas), pesquisar concorrentes',
            'onde' => 'painel Dig (Vendas) e botão Comparar de Campanhas/Social', 'palavras' => ['prospect', 'captac', 'captar', 'lead', 'garimp', 'dig', 'vend', 'cliente novo', 'novos clientes', 'abordag', 'cold', 'concorrent', 'player', 'mercado']],
        'sumi' => ['nome' => 'Sumi Sulo', 'papel' => 'IA de Campanhas (anúncios)',
            'faz' => 'Lê investido, leads, custo por lead, público e horários dos anúncios da Meta; compara clientes; aponta o que escalar ou pausar; escreve direção e copy de anúncios.',
            'executa' => 'análise de campanha e anúncio (dentro do painel Campanhas)', 'onde' => 'painel Campanhas',
            'palavras' => ['campanha', 'anuncio', 'anúncio', 'meta ads', 'cpl', 'investimento', 'trafego', 'tráfego']],
        'muru' => ['nome' => 'Muru Mara', 'papel' => 'IA de Social (redes sociais)',
            'faz' => 'Lê o desempenho real das redes (Instagram e Facebook), compara perfis, descobre o que rendeu e o que repetir; entrega direção criativa e copy derivada.',
            'executa' => 'análise de desempenho e comparação de perfis (dentro do painel Social)', 'onde' => 'painel Social',
            'palavras' => ['social', 'instagram', 'facebook', 'post', 'rede social', 'redes', 'engajamento', 'fila de publicacao']],
        'gigi' => ['nome' => 'Gigi Lila', 'papel' => 'Anfitriã e IA do cliente (receptivo)',
            'faz' => 'Recebe o cliente (zap receptivo e chat da plataforma), conhece tudo do cliente dela e nada dos outros, abre pedidos de trabalho e leva problemas à direção.',
            'executa' => 'atendimento do cliente, abrir pedido de trabalho', 'onde' => 'zap receptivo e chat do cliente',
            'palavras' => ['atendimento', 'cliente pediu', 'receptivo', 'boas-vindas', 'onboarding']],
    ];
}
function pdElencoTexto($acoes = true) {
    $t = "ELENCO DAS IAs DA CASA (todas se conhecem e se pedem coisas; comunicação é de todos para todos, de forma leve e rápida; use o papel certo de cada uma):\n";
    foreach (pdElencoPapeis() as $id => $x) $t .= '- ' . $x['nome'] . ' (' . $x['papel'] . '): ' . $x['faz'] . ' Executa: ' . $x['executa'] . '. Fica no ' . $x['onde'] . ".\n";
    $t .= "- Cada pessoa da equipe também tem a IA parceira dela (veja time_e_suas_ias). Pedir algo a essa IA é levar o recado à pessoa, pela IA dela.\n";
    if ($acoes) $t .= "COMO PEDIR A OUTRA IA: se o trabalho é do papel de outra IA, você encaminha (não diz que não sabe quem é, e não faz de conta que fez). No WhatsApp e no chat escreva a ação delegar: ##ACAO## {\"tipo\":\"delegar\",\"para\":\"tumi\",\"pedido\":\"o que fazer, completo\",\"cliente\":\"opcional\",\"quantos\":5}. REGRA GERAL: qualquer pessoa pode pedir QUALQUER coisa a QUALQUER IA; a IA que recebe executa o que é do seu papel e delega o resto (delegar) na mesma resposta, sem dizer que não consegue. Pedido composto (criar tarefa + passar pro Tumi + perfil pela Sumi) = várias ações de uma vez. SKILL BRIEFING DE DIG (pedido ao Tumi para achar leads/prospectar): NUNCA mande pedido vago. Antes de delegar, confira o que já sabe pela conversa e pelos dados da casa; o que faltar, pergunte numa ÚNICA mensagem curta, numerada, já com sugestão para ele só responder \"ok\" ou ajustar: 1) para quem é (carteira/cliente: SD, Stratesign ou outro) e o que vamos oferecer; 2) quem procurar (tipo de empresa/segmento e porte); 3) onde (cidade/região/país); 4) quem NÃO pode entrar (concorrentes, clientes atuais, segmentos fora); 5) quantos (padrão 10, até 20); 6) ângulo da abordagem (o gancho do primeiro e-mail). Só depois delegue ao Tumi com o briefing completo em texto corrido no campo pedido, com cliente e quantos preenchidos, e diga o que mandou e quando volta. Se a pessoa já deu tudo, não pergunte: delegue. Para CONFIRMAR se um pedido foi recebido/está rodando/terminou, NUNCA diga que não tem retorno: escreva ##ACAO## {\"tipo\":\"status_pedidos\",\"para\":\"tumi\"} e conte o estado real (na fila, rodando, feito, erro). Para saber se a professora (GPT) voltou, se as aulas rolaram ou como está a escola: ##ACAO## {\"tipo\":\"escola_status\"} e responda só com o que vier. "
        . "Quando a pessoa disser \"pede pro Tumi...\", \"passa pra Sumi\", \"vê isso com a Muru\" ou der entrada num trabalho que cabe no papel de uma delas, delegue para ela. "
        . "Se você mesma é a IA do papel, é seu: faça. Nunca diga que alguém \"não está na equipe\": as IAs da casa são parte da equipe. "
        . "ORIENTAÇÃO PARA TODAS AS IAs: quando a pessoa pedir para avisar/lembrar as IAs de uma regra válida por um tempo (\"a partir de agora não citem o sócio X\", \"em novembro é Novembro Azul\", \"esse mês nada de promessa de valorização\"), guarde com ##ACAO## {\"tipo\":\"orientar\",\"texto\":\"a regra, clara\",\"ate\":\"AAAA-MM-DD ou vazio\",\"cliente\":\"nome do cliente ou vazio\"} — ela passa a valer para todas as IAs da casa (ou só para aquele cliente).";
    else $t .= "Se o pedido cabe no papel de outra IA, diga com naturalidade quem é ela e onde pedir (no painel dela ou pelo WhatsApp da equipe); você conhece todas e fala delas como colegas. Nunca diga que alguém \"não está na equipe\".";
    $t .= pdOrientacoesTexto();
    return $t;
}
/** Orientações vigentes da casa (painel › Escola, zap ou chat): valem hoje, para todas as IAs; as de cliente citam o cliente. */
function pdOrientacoesTexto($clientId = null) {
    if (!function_exists('sd_supabase')) return '';
    static $cache = null; if ($cache === null) {
        $hoje = date('Y-m-d', time() - 3 * 3600);
        $r = sd_supabase('/rest/v1/pd_orientacoes?ativa=eq.true&or=(de.is.null,de.lte.' . $hoje . ')&select=texto,escopo,client_id,ate,clients(name)&order=criado_em.desc&limit=40');
        $cache = []; foreach ((array)($r['body'] ?? []) as $o) if (is_array($o) && (empty($o['ate']) || $o['ate'] >= $hoje)) $cache[] = $o;
    }
    $l = array_filter($cache, fn($o) => $o['escopo'] === 'todas' || !$clientId || $o['client_id'] === $clientId); if (!$l) return '';
    $t = "\nORIENTAÇÕES VIGENTES DA CASA (valem hoje; aplique sem citar que são orientações):\n";
    foreach ($l as $o) $t .= '- ' . ($o['escopo'] === 'cliente' && !empty($o['clients']['name']) ? '[' . $o['clients']['name'] . '] ' : '') . trim($o['texto']) . (!empty($o['ate']) ? ' (até ' . date('d/m', strtotime($o['ate'])) . ')' : '') . "\n";
    return $t;
}
/** Guarda uma orientação (painel, zap ou chat). Devolve texto de confirmação. */
function pdOrientar($texto, $porId, $via = 'painel', $ate = null, $clienteNome = '', $de = null) {
    $texto = trim((string)$texto); if (mb_strlen($texto) < 6) return '⚠️ Orientação curta demais.';
    $cid = null; if ($clienteNome !== '') { $c = sd_supabase('/rest/v1/clients?select=id,name&name=ilike.*' . rawurlencode($clienteNome) . '*&limit=1'); $cid = $c['body'][0]['id'] ?? null; }
    $row = ['texto' => mb_substr($texto, 0, 600), 'escopo' => $cid ? 'cliente' : 'todas', 'client_id' => $cid, 'via' => $via, 'criado_por' => $porId,
        'de' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$de) ? $de : null, 'ate' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$ate) ? $ate : null];
    $r = sd_supabase('/rest/v1/pd_orientacoes', 'POST', $row);
    if (($r['code'] ?? 500) >= 300) return '⚠️ Não consegui guardar a orientação (o SQL v164 já rodou?).';
    return '✅ Orientação guardada para ' . ($cid ? 'o cliente ' . $clienteNome : 'todas as IAs') . ($row['ate'] ? ' até ' . date('d/m', strtotime($row['ate'])) : '') . ': "' . mb_substr($texto, 0, 120) . '". Está no painel › Escola › Configuração.';
}
// "tumi", "Tumi Tua", "a Sumi" → id do papel da casa; senão null
function pdElencoBusca($q) {
    $q = mb_strtolower(trim((string)$q)); $q = preg_replace('/^(a|o|pra|pro|para|à|ao)\s+/u', '', $q);
    foreach (pdElencoPapeis() as $id => $x) { if ($q === $id || strpos($q, $id) === 0 || mb_strtolower($x['nome']) === $q) return $id; }
    return null;
}
// ---------- executar um pedido (chamado pelo relógio da casa) ----------
function pdPedidoTumiProspectar($ped) {
    require_once __DIR__ . '/pd-vendas-lib.php';
    $ctx = is_array($ped['contexto'] ?? null) ? $ped['contexto'] : (json_decode((string)($ped['contexto'] ?? ''), true) ?: []);
    $cart = vdCasaId(); $nomeCart = 'SD / Stratesign';
    $cn = trim((string)($ctx['cliente'] ?? ''));
    if ($cn !== '') { $r = vdLinhas(vdSup('/rest/v1/clients?name=ilike.' . rawurlencode('*' . $cn . '*') . '&active=is.true&select=id,name&limit=1')); if ($r) { $cart = $r[0]['id']; $nomeCart = $r[0]['name']; } }
    $quantos = max(3, min(20, (int)($ctx['quantos'] ?? 5)));
    $r = vdDescobrir((string)$ped['pedido'], $quantos, $cart, $ped['de_user'] ?? null);
    if (empty($r['ok'])) return [false, 'Não consegui garimpar agora: ' . ($r['erro'] ?? 'sem detalhe') . '.'];
    $leads = vdLinhas(vdSup('/rest/v1/pd_prospects?estagio=eq.novo&select=id,nome&carteira_id=eq.' . $cart . '&order=nota.desc.nullslast&limit=40')); $esc = 0;
    foreach ($leads as $l) { if ($esc >= min(5, $quantos)) break; if (vdLinhas(vdSup('/rest/v1/pd_prospect_conversas?prospect_id=eq.' . $l['id'] . '&direcao=eq.saida&select=id&limit=1'))) continue;
        $w = vdEscrever($l['id'], 'email', 1, null, ''); if (!empty($w['ok'])) $esc++; }
    return [true, 'Achei ' . (int)$r['novos'] . ' empresa(s) nova(s) para a carteira ' . $nomeCart . ', li os sites e deixei ' . $esc . ' abertura(s) escrita(s). Estão esperando a sua aprovação no Dig. Nada foi enviado.'];
}
/** Sumi / Muru / Gigi executam o pedido com o próprio conhecimento (persona + ficha do cliente + busca na web) e devolvem o trabalho; se o pedido nasceu numa tarefa, o resultado entra nela como comentário. */
function pdPedidoIaResponder($ped) {
    require_once __DIR__ . '/meta-ads-lib.php'; @set_time_limit(280);
    $ia = (string)($ped['para_ia'] ?? ''); $ctx = is_array($ped['contexto'] ?? null) ? $ped['contexto'] : (json_decode((string)($ped['contexto'] ?? ''), true) ?: []);
    $sys = $ia === 'muru' && function_exists('sdp_muru_sistema') ? sdp_muru_sistema() : ($ia === 'sumi' && function_exists('pd_sumi_sistema') ? pd_sumi_sistema() : 'Você é ' . ucfirst($ia) . ', IA da casa (agência de branding do mercado imobiliário brasileiro).');
    $sys .= "\n\nVocê recebeu um PEDIDO de outra IA ou de alguém da equipe. Entregue o trabalho pronto, em markdown simples, pt-BR, objetivo, com critérios e fontes quando pesquisar. Se faltar dado essencial, entregue o melhor possível e liste no fim o que faltou (sem devolver perguntas vazias).";
    $fich = ''; $cn = trim((string)($ctx['cliente'] ?? ''));
    if ($cn !== '' && function_exists('pd_ctx_cliente')) { try { $c = sd_supabase('/rest/v1/clients?name=ilike.' . rawurlencode('*' . $cn . '*') . '&select=id,name&limit=1')['body'][0] ?? null; if ($c) { $cc = pd_ctx_cliente($c['id']); $fich = "\n\nFICHA DO CLIENTE " . $c['name'] . ":\n" . mb_substr((string)($cc['ficha'] ?? ''), 0, 6000); } } catch (Throwable $e) {} }
    [$t, $custo, $erro] = pd_ia_campanhas($sys, [['role' => 'user', 'content' => 'PEDIDO' . ($ped['de_ia'] ? ' (de ' . $ped['de_ia'] . ')' : '') . ":\n" . mb_substr((string)$ped['pedido'], 0, 4000) . $fich]], 4000, 4, 200);
    if ($erro || trim((string)$t) === '') return [false, 'Não consegui executar agora: ' . ($erro ?: 'sem resposta') . '.'];
    $t = trim((string)$t);
    if (!empty($ctx['task_id'])) { try { sd_supabase('/rest/v1/comments', 'POST', ['task_id' => $ctx['task_id'], 'user_id' => $ped['de_user'], 'body' => '🤖 ' . ucfirst($ia) . " entregou:\n\n" . mb_substr($t, 0, 9000)]); } catch (Throwable $e) {} }
    return [true, mb_substr($t, 0, 3500)];
}
function pdPedidosRodar($max = 2) {
    // pedido preso em andamento há mais de 20 min (o relógio morreu no meio): fecha com erro explícito e avisa, em vez de ficar pendurado
    try { $pr = sd_supabase('/rest/v1/pd_ia_pedidos?estado=eq.em_andamento&iniciado_em=lt.' . rawurlencode(gmdate('c', time() - 1200)) . '&select=id'); foreach ((array)($pr['body'] ?? []) as $x) sd_supabase('/rest/v1/pd_ia_pedidos?id=eq.' . $x['id'], 'PATCH', ['estado' => 'erro', 'resposta' => 'A execução foi interrompida (o relógio da casa caiu no meio). Peça de novo.', 'concluido_em' => gmdate('c')]); } catch (Throwable $e) {}
    $q = sd_supabase('/rest/v1/pd_ia_pedidos?estado=eq.novo&order=criado_em.asc&limit=10&select=*'); if (($q['code'] ?? 500) >= 300) return 0; $n = 0;
    foreach ((array)$q['body'] as $ped) {
        if (!is_array($ped)) continue; if ($n >= (int)$max) break;
        $c0 = is_array($ped['contexto'] ?? null) ? $ped['contexto'] : (json_decode((string)($ped['contexto'] ?? ''), true) ?: []);
        if (!empty($c0['proxima_em']) && strtotime((string)$c0['proxima_em']) > time()) continue;   // pedido que está insistindo: espera o próximo horário
        sd_supabase('/rest/v1/pd_ia_pedidos?id=eq.' . $ped['id'], 'PATCH', ['estado' => 'em_andamento', 'iniciado_em' => gmdate('c')]);
        try {
            if (($ped['para_ia'] ?? '') === 'tumi') [$ok, $resp] = pdPedidoTumiProspectar($ped); else [$ok, $resp] = pdPedidoIaResponder($ped);
        } catch (Throwable $e) { $ok = false; $resp = 'Deu erro ao executar: ' . mb_substr($e->getMessage(), 0, 160); }
        // GARIMPO NÃO DESISTE: sem novidade, tenta de novo com outros termos ao longo do dia (até 10 tentativas, a cada 40 min)
        $tent = (int)($c0['tentativas'] ?? 0) + 1;
        if (!$ok && ($ped['para_ia'] ?? '') === 'tumi' && $tent < 10 && preg_match('/já estava na carteira|não achei empresas|não devolveu uma lista|Não consegui garimpar agora: (a busca|tudo|não achei|a IA)/u', $resp) && !preg_match('/teto|SD_ANTHROPIC_KEY/u', $resp)) {
            sd_supabase('/rest/v1/pd_ia_pedidos?id=eq.' . $ped['id'], 'PATCH', ['estado' => 'novo', 'iniciado_em' => null, 'resposta' => 'Tentativa ' . $tent . ' sem empresas novas; sigo procurando com outros termos.', 'contexto' => array_merge($c0, ['tentativas' => $tent, 'proxima_em' => gmdate('c', time() + 2400)])]);
            if ($tent === 1) { try { $pf = sd_supabase('/rest/v1/profiles?id=eq.' . urlencode((string)$ped['de_user']) . '&select=zap')['body'][0] ?? null;
                if ($pf && !empty($pf['zap']) && function_exists('pdZapEnviar')) pdZapEnviar(pdZapNormaliza((string)$pf['zap']), '🔁 Tumi: a primeira rodada só trouxe empresas que já estavam na carteira. Não desisti: vou procurar o dia todo, mudando termos e cidades, e te aviso quando juntar as novas.', true); } catch (Throwable $e) {} }
            $n++; continue;
        }
        sd_supabase('/rest/v1/pd_ia_pedidos?id=eq.' . $ped['id'], 'PATCH', ['estado' => $ok ? 'feito' : 'erro', 'resposta' => $resp, 'concluido_em' => gmdate('c')]);
        // devolve pra quem pediu, pelo zap (a janela de 24h costuma estar aberta: foi ele quem mandou a mensagem)
        try { $pf = sd_supabase('/rest/v1/profiles?id=eq.' . urlencode((string)$ped['de_user']) . '&select=name,zap')['body'][0] ?? null;
            if ($pf && !empty($pf['zap']) && function_exists('pdZapEnviar')) pdZapEnviar(pdZapNormaliza((string)$pf['zap']), '🔁 ' . ucfirst((string)$ped['para_ia']) . ' respondeu ao pedido de ' . ($ped['de_ia'] ?: 'sua IA') . ":\n" . $resp, true); } catch (Throwable $e) {}
        $n++;
    }
    return $n;
}
