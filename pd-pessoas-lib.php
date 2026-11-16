<?php
// PD · TUMI ACHA PESSOAS — o mesmo motor do Harvey (CRM de prospects), agora com PESSOAS.
// Dois caminhos, ambos sem coletar nada de rede social por robô:
//   descobrir = busca na web pública (sites, listas, catálogos, matérias, páginas profissionais) e traz pessoas reais com a fonte;
//   triar     = a equipe cola @ / links / bios que viu no perfil do cliente e a Tumi cruza com o critério (ex.: investidor, finanças) e dá nota.
// Cada pessoa vira um prospect com sinais.PESSOA = true, entra no funil, na Caixa de saída e no CRM como qualquer lead.
require_once __DIR__ . '/pd-vendas-lib.php';
require_once __DIR__ . '/pd-aula-lib.php';

function vdPessoaChave($cartK, $x) {
    $ig = strtolower(trim((string)($x['instagram'] ?? ''), " @/")); $ig = preg_replace('~^https?://(www\.)?instagram\.com/~', '', $ig); $ig = trim(explode('?', $ig)[0], '/');
    $ln = strtolower((string)($x['linkedin'] ?? '')); if (preg_match('~linkedin\.com/in/([^/?#]+)~', $ln, $m)) $ln = $m[1]; else $ln = '';
    if ($ig !== '') return $cartK . '|p|ig:' . $ig;
    $em = strtolower(trim((string)($x['email'] ?? ''))); if ($ln === '' && $em !== '' && filter_var($em, FILTER_VALIDATE_EMAIL)) return $cartK . '|p|em:' . $em;
    if ($ln !== '') return $cartK . '|p|in:' . $ln;
    return $cartK . '|p|' . vdNorm($x['nome'] ?? '') . '|' . vdNorm($x['empresa'] ?? '');
}

/** Grava pessoas como prospects. $lista: [{nome,cargo,empresa,cidade,uf,instagram,linkedin,site,email,fonte_url,motivo,nota,perfil}] */
function vdPessoasGrava(array $lista, $criterio, $cart, $uid, $origem, $loteNome) {
    $lr = $cart ? vdLinhas(vdSup('/rest/v1/pd_lotes?nome=eq.' . rawurlencode($loteNome) . '&carteira_id=eq.' . $cart . '&select=id&limit=1')) : [];   // o mesmo lote serve às capturas seguintes (ex.: uma isca)
    if (!$lr) $lr = vdLinhas(vdSup('/rest/v1/pd_lotes', 'POST', ['nome' => $loteNome, 'motivo' => mb_substr($criterio, 0, 500), 'carteira_id' => $cart, 'criado_por' => $uid]));
    $lote = $lr[0]['id'] ?? null; $cartK = $cart ?: 'casa'; $por = []; $ign = 0;
    foreach ($lista as $x) {
        if (!is_array($x)) continue; $nome = trim((string)($x['nome'] ?? ''));
        if (mb_strlen($nome) < 2 || (trim((string)($x['instagram'] ?? '')) === '' && trim((string)($x['linkedin'] ?? '')) === '' && trim((string)($x['empresa'] ?? '')) === '' && trim((string)($x['site'] ?? '')) === '')) { $ign++; continue; }
        $k = vdPessoaChave($cartK, $x); if (isset($por[$k])) { $ign++; continue; }
        $email = strtolower(trim((string)($x['email'] ?? ''))); if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $email = '';
        $ig = trim((string)($x['instagram'] ?? ''), " @/"); $ig = preg_replace('~^https?://(www\.)?instagram\.com/~', '', $ig); $ig = trim(explode('?', $ig)[0], '/');
        $por[$k] = ['nome' => mb_substr($nome, 0, 160), 'site' => trim((string)($x['site'] ?? '')) ?: null, 'dominio' => null, 'cidade' => trim((string)($x['cidade'] ?? '')) ?: null,
            'uf' => strtoupper(substr(trim((string)($x['uf'] ?? '')), 0, 2)) ?: null, 'segmento' => mb_substr(trim(((string)($x['cargo'] ?? '')) . (!empty($x['empresa']) ? ' · ' . $x['empresa'] : '')), 0, 160) ?: null,
            'instagram' => $ig ?: null, 'origem' => $origem, 'lote_id' => $lote, 'carteira_id' => $cart, 'criado_por' => $uid, 'chave' => $k,
            'nota' => isset($x['nota']) ? max(1, min(100, (int)$x['nota'])) : 50, 'angulo' => mb_substr((string)($x['motivo'] ?? ''), 0, 300) ?: null,
            'sinais' => ['PESSOA' => true, 'cargo' => (string)($x['cargo'] ?? ''), 'empresa' => (string)($x['empresa'] ?? ''), 'criterio' => mb_substr($criterio, 0, 200), 'fonte_url' => (string)($x['fonte_url'] ?? ''), 'linkedin' => (string)($x['linkedin'] ?? ''), 'perfil' => mb_substr((string)($x['perfil'] ?? ''), 0, 300)] + (is_array($x['sinais_extra'] ?? null) ? $x['sinais_extra'] : []),
            'enriquecido_em' => gmdate('c'), '_email' => $email, '_zap' => (trim((string)($x['zap'] ?? '')) !== '' ? pdZapNormaliza($x['zap']) : '')];
    }
    if (!$por) return ['novos' => 0, 'duplicados' => 0, 'ignorados' => $ign, 'lote' => $lote];
    $chaves = array_keys($por); $jaTem = [];
    foreach (array_chunk($chaves, 100) as $ck) { $q = sd_supabase('/rest/v1/pd_prospects?chave=in.(' . implode(',', array_map(fn($c) => '"' . str_replace('"', '', $c) . '"', $ck)) . ')&select=chave'); foreach ((array)($q['body'] ?? []) as $r) if (is_array($r)) $jaTem[$r['chave']] = 1; }
    $ins = []; foreach ($por as $k => $r) if (!isset($jaTem[$k])) { unset($r['_email'], $r['_zap']); $ins[] = $r; }
    $dup = count($por) - count($ins); if (!$ins) return ['novos' => 0, 'duplicados' => $dup, 'ignorados' => $ign, 'lote' => $lote];
    $r = sd_supabase('/rest/v1/pd_prospects', 'POST', $ins, ['Prefer: return=representation']);
    if (($r['code'] ?? 500) >= 300) { $msg = (string)($r['body']['message'] ?? $r['raw'] ?? '');
        if (preg_match("/Could not find the '([a-z_]+)' column/", $msg, $mm)) { $ins = array_map(function ($x) use ($mm) { unset($x[$mm[1]]); return $x; }, $ins); $r = sd_supabase('/rest/v1/pd_prospects', 'POST', $ins, ['Prefer: return=representation']); }
        if (($r['code'] ?? 500) >= 300) return ['erro' => 'banco recusou: ' . mb_substr((string)($r['raw'] ?? ''), 0, 200)]; }
    $cts = []; foreach ((array)$r['body'] as $n) { $e = $por[$n['chave']]['_email'] ?? ''; $z = $por[$n['chave']]['_zap'] ?? ''; if (strlen($z) < 12) $z = ''; if ($e !== '' || $z !== '') $cts[] = ['prospect_id' => $n['id'], 'nome' => $n['nome'], 'cargo' => ($por[$n['chave']]['sinais']['cargo'] ?? '') ?: null, 'email' => $e ?: null, 'zap' => $z ?: null, 'email_status' => ($por[$n['chave']]['origem'] ?? '') === 'isca' ? 'verificado' : 'arriscado', 'principal' => true, 'dono' => true]; }
    if ($cts) sd_supabase('/rest/v1/pd_prospect_contatos', 'POST', $cts, ['Prefer: return=minimal']);
    return ['novos' => count((array)$r['body']), 'duplicados' => $dup, 'ignorados' => $ign, 'lote' => $lote];
}

/** Confere cada pessoa achada: uma busca na web por pessoa (em paralelo), procurando a presença própria dela (site, clínica/escritório, LinkedIn público, cadastro profissional)
 *  e comparando profissão, cidade e empresa com o que a fonte original disse. Devolve a mesma lista com 'verif' = {ok, confianca, evidencias[], email, telefone, site, instagram, linkedin, canal}. */
function vdVerificarPessoas(array $lista, $criterio) {
    $base = defined('PD_ANTHROPIC_BASE') ? rtrim(PD_ANTHROPIC_BASE, '/') : 'https://api.anthropic.com';
    $sys = "Você confere uma pessoa achada numa fonte pública, de dois jeitos conforme o PEDIDO. (A) Pedido por PROFISSÃO/CARGO: confirme que é a MESMA pessoa com presença profissional própria na web (site próprio, clínica/escritório/empresa, LinkedIn público, diretórios e cadastros abertos); profissão E cidade/região E (empresa OU outro dado) devem bater. "
         . "(B) Pedido por INTERESSE, COMPORTAMENTO ou INTENÇÃO (o que a pessoa gosta, pratica, deseja): a profissão NÃO é exigida. Confirme (1) que o SINAL é real: o que a pessoa declarou ou fez, na fonte, com a data; (2) que é uma pessoa identificada (nome inteiro) ligada à cidade/região do pedido; (3) o PAPEL dela no sinal: praticante, convidada, palestrante, moradora, profissional, organizador, fornecedor ou expositor. Se o pedido é por afinidade ou moradia, organizador, fornecedor e expositor NÃO servem (confirmado=false). "
         . "Classifique a CAMADA: intencao (declara querer construir, mudar, comprar, procurar terreno ou arquiteto), comportamento (faz/pratica algo concreto, recorrente) ou afinidade (só demonstra interesse). Homônimo ou cidade diferente = não confirmado. Nunca invente nada; e-mail e telefone só se estiverem escritos numa página que você leu (profissional/comercial), senão vazio. Nunca deduza patrimônio, saúde, religião, política ou outros dados sensíveis. "
         . "Responda SOMENTE um JSON: {\"confirmado\":true|false,\"confianca\":0-100,\"evidencias\":[\"url1\",\"url2\"],\"camada\":\"intencao|comportamento|afinidade|\",\"sinal\":\"o que declarou ou fez, em 1 frase\",\"data_fonte\":\"data ou ano da fonte\",\"papel\":\"\",\"conexao\":\"1 frase: como isso se liga ao que vamos oferecer\",\"email\":\"\",\"telefone\":\"\",\"site\":\"\",\"instagram\":\"\",\"linkedin\":\"\",\"canal\":\"melhor canal público para chegar (ex.: e-mail do site, formulário, WhatsApp visível, Instagram ativo, LinkedIn)\",\"resumo\":\"1 frase do que confirmou ou por que não\"}";
    $mh = curl_multi_init(); $chs = [];
    foreach ($lista as $i => $x) {
        $q = 'Pessoa: ' . ($x['nome'] ?? '') . ' · cargo/profissão: ' . ($x['cargo'] ?? '') . ' · empresa: ' . ($x['empresa'] ?? '') . ' · cidade: ' . ($x['cidade'] ?? '') . ' ' . ($x['uf'] ?? '') . ' · fonte onde apareceu: ' . ($x['fonte_url'] ?? '') . ' · critério do pedido: ' . mb_substr($criterio, 0, 300);
        $ch = curl_init($base . '/v1/messages');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 150,
            CURLOPT_HTTPHEADER => ['x-api-key: ' . SD_ANTHROPIC_KEY, 'anthropic-version: 2023-06-01', 'content-type: application/json'],
            CURLOPT_POSTFIELDS => json_encode(['model' => defined('SD_PESSOAS_VERIF_MODEL') ? SD_PESSOAS_VERIF_MODEL : 'claude-haiku-4-5', 'max_tokens' => 1200, 'system' => $sys . (function_exists('pdBlindagem') ? pdBlindagem() : ''),
                'tools' => [['type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => 3]], 'messages' => [['role' => 'user', 'content' => $q]]])]);
        curl_multi_add_handle($mh, $ch); $chs[$i] = $ch;
    }
    do { $st = curl_multi_exec($mh, $run); if ($run) curl_multi_select($mh, 1.0); } while ($run && $st === CURLM_OK);
    foreach ($chs as $i => $ch) {
        $raw = curl_multi_getcontent($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_multi_remove_handle($mh, $ch); curl_close($ch);
        $v = ['ok' => false, 'confianca' => 0, 'evidencias' => [], 'resumo' => $code === 200 ? '' : 'a conferência não respondeu (HTTP ' . $code . ')'];
        if ($code === 200) { $j = json_decode((string)$raw, true); $t = ''; foreach ((array)($j['content'] ?? []) as $bl) if (($bl['type'] ?? '') === 'text') $t .= $bl['text'];
            if (preg_match('/\{.*\}/s', $t, $m) && is_array($o = json_decode($m[0], true))) {
                $ev = array_values(array_filter((array)($o['evidencias'] ?? []), fn($u) => is_string($u) && preg_match('~^https?://~', $u)));
                $v = ['ok' => !empty($o['confirmado']) && count($ev) >= 1, 'confianca' => max(0, min(100, (int)($o['confianca'] ?? 0))), 'evidencias' => array_slice($ev, 0, 3),
                      'email' => (string)($o['email'] ?? ''), 'telefone' => (string)($o['telefone'] ?? ''), 'site' => (string)($o['site'] ?? ''), 'instagram' => (string)($o['instagram'] ?? ''),
                      'linkedin' => (string)($o['linkedin'] ?? ''), 'canal' => mb_substr((string)($o['canal'] ?? ''), 0, 160), 'resumo' => mb_substr((string)($o['resumo'] ?? ''), 0, 240),
                      'camada' => in_array(($o['camada'] ?? ''), ['intencao', 'comportamento', 'afinidade'], true) ? $o['camada'] : '', 'sinal' => mb_substr((string)($o['sinal'] ?? ''), 0, 240), 'data_fonte' => mb_substr((string)($o['data_fonte'] ?? ''), 0, 30),
                      'papel' => mb_substr((string)($o['papel'] ?? ''), 0, 40), 'conexao' => mb_substr((string)($o['conexao'] ?? ''), 0, 240)]; } }
        $lista[$i]['verif'] = $v;
    }
    curl_multi_close($mh); return $lista;
}

/** PLANO DE BUSCA: a IA lê o pedido em linguagem normal e o traduz em recortes (onde procurar + comando de busca), como um pesquisador faria;
 *  cada recorte roda em paralelo e os nomes são juntados. Devolve null se não conseguir planejar (aí vale a busca única de sempre). */
function vdColetarPlanejado($consulta, $quantos, $sysColeta) {
    $plano = 'Você é pesquisador de prospecção. Leia o PEDIDO e o traduza em um plano de busca na web aberta para achar PESSOAS (não empresas). Devolva de 4 a 6 RECORTES, cada um uma família diferente de fonte pública onde essas pessoas aparecem com nome e papel: '
           . 'programações e palestrantes de eventos e congressos, premiações e rankings, catálogos e diretórios profissionais abertos, páginas de equipe/sócios de clínicas, escritórios e empresas, associações e sociedades de classe, matérias e entrevistas, e fontes específicas do meio da pessoa (ex.: leilões e feiras para o agro). '
           . 'Se o pedido descreve INTERESSES, COMPORTAMENTOS ou INTENÇÃO (o que a pessoa gosta, pratica, deseja; querer construir, mudar, procurar terreno ou arquiteto) em vez de uma profissão, monte os recortes por TIPO DE SINAL (ex.: quem declara querer construir ou mudar de casa; entrevistas sobre receber, cozinhar ou colecionar; resultados e participações identificadas em torneios e eventos; relatos sobre a própria casa, arte ou design), com comandos curtos, um assunto por recorte; a profissão entra só como dado complementar. '
           . 'Inclua a cidade/região do pedido e cidades próximas, e prefira os últimos 24 meses quando a fonte tiver data. Responda SOMENTE um JSON array: [{"recorte":"nome curto","onde":"tipo de fonte","comando":"instrução de busca em 1-2 frases, concreta"}].';
    [$txt, , $err] = pdClaude($plano, 'PEDIDO: ' . mb_substr($consulta, 0, 1500), 1500);
    if (!$txt || !preg_match('/\[.*\]/s', $txt, $m) || !is_array($rec = json_decode($m[0], true))) return null;
    $rec = array_slice(array_values(array_filter($rec, fn($r) => is_array($r) && !empty($r['comando']))), 0, 6); if (count($rec) < 2) return null;
    $base = defined('PD_ANTHROPIC_BASE') ? rtrim(PD_ANTHROPIC_BASE, '/') : 'https://api.anthropic.com';
    $porRec = max(4, (int)ceil($quantos * 2 / count($rec))); $mh = curl_multi_init(); $chs = [];
    foreach ($rec as $i => $r) {
        $ch = curl_init($base . '/v1/messages');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 110,
            CURLOPT_HTTPHEADER => ['x-api-key: ' . SD_ANTHROPIC_KEY, 'anthropic-version: 2023-06-01', 'content-type: application/json'],
            CURLOPT_POSTFIELDS => json_encode(['model' => defined('SD_PESSOAS_COLETA_MODEL') ? SD_PESSOAS_COLETA_MODEL : 'claude-haiku-4-5', 'max_tokens' => 4000, 'system' => str_replace('até ' . $quantos . ' itens', 'até ' . $porRec . ' itens', $sysColeta) . (function_exists('pdBlindagem') ? pdBlindagem() : ''),
                'tools' => [['type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => 5]],
                'messages' => [['role' => 'user', 'content' => 'PEDIDO ORIGINAL: ' . mb_substr($consulta, 0, 900) . "\n\nSEU RECORTE: " . ($r['recorte'] ?? '') . ' — ' . ($r['onde'] ?? '') . "\nCOMANDO: " . $r['comando']]]])]);
        curl_multi_add_handle($mh, $ch); $chs[$i] = $ch;
    }
    do { $st = curl_multi_exec($mh, $run); if ($run) curl_multi_select($mh, 1.0); } while ($run && $st === CURLM_OK);
    $todas = []; $visto = [];
    foreach ($chs as $i => $ch) {
        $raw = curl_multi_getcontent($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_multi_remove_handle($mh, $ch); curl_close($ch);
        if ($code !== 200) continue; $j = json_decode((string)$raw, true); $t = ''; foreach ((array)($j['content'] ?? []) as $bl) if (($bl['type'] ?? '') === 'text') $t .= $bl['text'];
        if (!preg_match('/\[.*\]/s', $t, $mm) || !is_array($l = json_decode($mm[0], true))) continue;
        foreach ($l as $x) { if (!is_array($x) || empty($x['nome']) || empty($x['fonte_url'])) continue; $k = vdNorm($x['nome']) . '|' . vdNorm($x['cidade'] ?? ''); if (isset($visto[$k])) continue; $visto[$k] = 1; $x['recorte'] = $rec[$i]['recorte'] ?? ''; $todas[] = $x; }
    }
    curl_multi_close($mh); return $todas ?: null;
}

/** Acha PESSOAS na web pública. Só traz quem apareceu numa fonte que a IA leu (com o link). */
function vdDescobrirPessoas($consulta, $quantos, $carteira, $uid) {
    if (!defined('SD_ANTHROPIC_KEY') || !SD_ANTHROPIC_KEY) return ['erro' => 'falta SD_ANTHROPIC_KEY'];
    $hoje = count(vdLinhas(vdSup('/rest/v1/pd_lotes?nome=like.Descoberta*&created_at=gte.' . gmdate('Y-m-d\T00:00:00\Z', strtotime('today')) . '&select=id&limit=50')));
    $tg = vdTetoGeral()['buscas']; if ($hoje >= $tg) return ['erro' => 'teto geral de ' . $tg . ' buscas por dia atingido'];
    $quantos = max(3, min(20, (int)$quantos)); $cart = $carteira && $carteira !== 'todas' ? $carteira : null;
    $sys = "Você acha PESSOAS REAIS para uma conversa comercial, usando a busca na web. Fontes que valem: sites de empresas (equipe, sócios), matérias, listas e catálogos de associações, eventos e palestrantes, diretórios de profissionais, páginas públicas de perfil profissional. "
         . "Regras: só inclua pessoa que apareceu em uma fonte que você leu, e devolva o link dessa fonte; nunca invente nome, cargo, empresa, @, e-mail ou telefone. Só dados PROFISSIONAIS e públicos; ignore vida privada, saúde, religião, política, filhos e qualquer dado sensível. "
         . "Se o e-mail comercial estiver publicado na fonte, devolva; se não estiver, deixe vazio (nunca deduza). Siga o CRITÉRIO do pedido ao pé da letra. INSISTA: faça várias buscas variando termos, cidades e tipos de lista até juntar a quantidade pedida; prefira gente menos óbvia. "
         . "Responda SOMENTE um JSON array de até $quantos itens: [{\"nome\":\"\",\"cargo\":\"\",\"empresa\":\"\",\"cidade\":\"\",\"uf\":\"\",\"instagram\":\"\",\"linkedin\":\"\",\"site\":\"\",\"email\":\"\",\"fonte_url\":\"\",\"data_fonte\":\"\",\"sinal\":\"o que a pessoa declarou ou fez\",\"papel\":\"praticante|convidada|moradora|profissional|organizador|fornecedor\",\"nota\":0,\"motivo\":\"1 frase: por que esta pessoa encaixa no critério\"}]. Quando o pedido for por interesse ou intenção, priorize declarações concretas, recentes e participação recorrente, e deixe cargo/empresa vazios se a fonte não disser. nota = encaixe de 1 a 100. Sem texto fora do JSON.";
    $lista = vdColetarPlanejado($consulta, $quantos, $sys);   // plano de busca em recortes, rodando em paralelo
    if ($lista === null) {   // sem plano: a busca única de sempre
    $base = defined('PD_ANTHROPIC_BASE') ? rtrim(PD_ANTHROPIC_BASE, '/') : 'https://api.anthropic.com';
    $ch = curl_init($base . '/v1/messages');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 240,
        CURLOPT_HTTPHEADER => ['x-api-key: ' . SD_ANTHROPIC_KEY, 'anthropic-version: 2023-06-01', 'content-type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['model' => defined('SD_ALUNA_MODEL') ? SD_ALUNA_MODEL : 'claude-sonnet-4-5', 'max_tokens' => 7000, 'system' => $sys . (function_exists('pdBlindagem') ? pdBlindagem() : ''),
            'tools' => [['type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => 10]], 'messages' => [['role' => 'user', 'content' => 'PEDIDO (' . $quantos . ' pessoas novas): ' . mb_substr($consulta, 0, 1800)]]])]);
    $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code !== 200) { $m = json_decode((string)$raw, true); return ['erro' => 'a busca na web não respondeu (HTTP ' . $code . ')' . (!empty($m['error']['message']) ? ': ' . mb_substr($m['error']['message'], 0, 160) : '')]; }
    $j = json_decode((string)$raw, true); $t = ''; foreach ((array)($j['content'] ?? []) as $bl) if (($bl['type'] ?? '') === 'text') $t .= $bl['text'];
    if (!preg_match('/\[.*\]/s', $t, $m)) return ['erro' => 'a IA não devolveu uma lista'];
    $lista = json_decode($m[0], true); if (!is_array($lista) || !$lista) return ['erro' => 'não achei pessoas com fonte confirmada'];
    }
    $lista = array_values(array_filter($lista, fn($x) => is_array($x) && !empty($x['fonte_url'])));
    if (!$lista) return ['erro' => 'as pessoas achadas vieram sem fonte; nada foi gravado'];
    // conferência automática: cada pessoa é pesquisada de novo na web; só entra quem teve a presença própria confirmada (profissão + cidade + empresa/outro dado batendo)
    $achadas = count($lista); $lista = vdVerificarPessoas(array_slice($lista, 0, 20), $consulta); $ok = [];
    foreach ($lista as $x) { $v = $x['verif'] ?? []; if (empty($v['ok']) || (int)($v['confianca'] ?? 0) < 60) continue;
        foreach (['email', 'site', 'instagram', 'linkedin'] as $k) if (trim((string)($x[$k] ?? '')) === '' && trim((string)($v[$k] ?? '')) !== '') $x[$k] = $v[$k];
        if (trim((string)($x['zap'] ?? '')) === '' && trim((string)($v['telefone'] ?? '')) !== '') $x['zap'] = $v['telefone'];
        $bonus = ['intencao' => 15, 'comportamento' => 8, 'afinidade' => 0][$v['camada'] ?? ''] ?? 0;   // intenção explícita pesa mais que afinidade
        $x['nota'] = min(100, (int)round(((int)($x['nota'] ?? 50) + (int)$v['confianca']) / 2) + $bonus);
        if (trim((string)($v['conexao'] ?? '')) !== '') $x['motivo'] = $v['conexao'];
        $x['sinais_extra'] = ['VERIFICADA' => true, 'confianca' => (int)$v['confianca'], 'evidencias' => $v['evidencias'], 'canal_sugerido' => $v['canal'] ?? '', 'conferencia' => $v['resumo'] ?? '',
            'camada' => $v['camada'] ?? '', 'sinal' => $v['sinal'] ?? '', 'data_fonte' => $v['data_fonte'] ?? '', 'papel' => $v['papel'] ?? ''];
        $ok[] = $x; }
    if (!$ok) return ['erro' => 'achei ' . $achadas . ' nomes, mas nenhum teve a presença profissional confirmada na web (homônimo ou dado que não bate); nada foi gravado'];
    $r = vdPessoasGrava($ok, $consulta, $cart, $uid, 'pessoas-web', 'Descoberta · pessoas ' . date('d/m H:i') . ' · ' . mb_substr($consulta, 0, 50));
    if (!empty($r['erro'])) return $r; if (empty($r['novos'])) return ['erro' => 'tudo que achei já estava na carteira (' . count($ok) . ' confirmadas, ' . (int)$r['duplicados'] . ' repetidas)'];
    $r['descartadas'] = $achadas - count($ok);
    return ['ok' => true] + $r;
}

/** Tria uma lista colada (um por linha: @ ou link, com a bio/cargo se a pessoa tiver copiado) contra o critério. Não acessa nenhuma rede social. */
function vdTriarPessoas($texto, $criterio, $carteira, $uid) {
    $cart = $carteira && $carteira !== 'todas' ? $carteira : null; $criterio = trim($criterio);
    if ($criterio === '') return ['erro' => 'diga o critério (ex.: investidores, quem fala de finanças e imóveis)'];
    $linhas = array_slice(array_values(array_filter(array_map('trim', preg_split('/\R/u', (string)$texto)), fn($l) => mb_strlen($l) >= 3)), 0, 120);
    if (!$linhas) return ['erro' => 'cole a lista: um @, link ou nome por linha, com a bio ao lado se tiver'];
    $sys = "Você tria pessoas para uma abordagem comercial. Recebe linhas coladas por uma pessoa da equipe (um @, link ou nome, às vezes com a bio ou o cargo). Use SOMENTE o que está escrito em cada linha; não invente nada e não deduza dado pessoal. "
         . "Para cada linha, decida se a pessoa encaixa no CRITÉRIO. Sem informação suficiente na linha, marque abordar=false e diga 'faltou bio'. Nunca deduza saúde, religião, política, orientação ou outros dados sensíveis. "
         . "Responda SOMENTE um JSON array, um item por linha de entrada, na mesma ordem: [{\"linha\":1,\"nome\":\"\",\"instagram\":\"\",\"linkedin\":\"\",\"cargo\":\"\",\"empresa\":\"\",\"cidade\":\"\",\"abordar\":true,\"nota\":0,\"perfil\":\"1 frase do que a linha diz\",\"motivo\":\"1 frase de por que encaixa ou não\"}]. nota de 1 a 100.";
    $num = []; foreach ($linhas as $i => $l) $num[] = ($i + 1) . '. ' . mb_substr($l, 0, 400);
    [$txt, , $err] = pdClaude($sys, "CRITÉRIO: " . mb_substr($criterio, 0, 600) . "\n\nLINHAS:\n" . implode("\n", $num), 8000);
    if (!$txt) return ['erro' => 'a IA não respondeu (' . $err . ')'];
    if (!preg_match('/\[.*\]/s', $txt, $m)) return ['erro' => 'a IA não devolveu uma lista'];
    $j = json_decode($m[0], true); if (!is_array($j)) return ['erro' => 'resposta fora do formato; tente de novo com menos linhas'];
    $ok = array_values(array_filter($j, fn($x) => is_array($x) && !empty($x['abordar']) && (int)($x['nota'] ?? 0) >= 50)); $fora = count($j) - count($ok);
    if (!$ok) return ['erro' => 'nenhuma das ' . count($j) . ' linhas encaixou no critério (ou faltou bio nas linhas)'];
    $r = vdPessoasGrava($ok, $criterio, $cart, $uid, 'pessoas-lista', 'Lista triada ' . date('d/m H:i') . ' · ' . mb_substr($criterio, 0, 50));
    if (!empty($r['erro'])) return $r; return ['ok' => true, 'fora' => $fora, 'lidas' => count($j)] + $r;
}
