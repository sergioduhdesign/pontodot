<?php
// =====================================================================
// PD · VENDAS — o motor tipo HARVEY (ethanplusai/harvey) dentro da casa.
// Ideia do Harvey: sinais públicos → nota de encaixe → um ÂNGULO específico por lead →
// sequência curta de mensagens → aprovação humana → envio → resposta classificada.
// Aqui serve pra e-mail E WhatsApp, e cada CLIENTE tem a própria carteira (carteira_id).
// Regras duras: nada sai sem aprovação de gente; quem pediu pra sair nunca mais recebe;
// teto diário; a IA nunca inventa fato sobre o lead (só usa sinais encontrados).
// =====================================================================
require_once __DIR__ . '/sd-auth.php';
require_once __DIR__ . '/pd-lib.php';
require_once __DIR__ . '/pd-aula-lib.php';

const VD_TETO_DIA = 40;                                   // mensagens enviadas por dia (por casa)
const VD_ESTAGIOS = ['novo', 'abordado', 'engajado', 'qualificando', 'proposta', 'negociando', 'ganho', 'perdido'];

function vdSup($path, $m = 'GET', $b = null) { return sd_supabase($path, $m, $b, $m === 'GET' ? [] : ['Prefer: return=representation']); }
function vdLinhas($r) { return (($r['code'] ?? 500) < 300 && is_array($r['body'] ?? null)) ? $r['body'] : []; }
function vdUuid($v) { $v = trim((string)$v); return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $v) ? strtolower($v) : null; }

/** Quem pode mexer em vendas: admin, ou equipe com a ferramenta "vendas" liberada. */
function vdPode($u) {
    if (($u['role'] ?? '') === 'admin') return true;
    if (($u['role'] ?? '') !== 'team') return false;
    $a = $u['studio_areas'] ?? [];
    if (is_string($a)) $a = array_filter(array_map('trim', explode(',', trim($a, '{}'))));
    return in_array('vendas', (array)$a, true);
}

function vdDominio($site) {
    $s = trim(strtolower((string)$site)); if ($s === '') return '';
    if (!preg_match('#^https?://#', $s)) $s = 'https://' . $s;
    $h = parse_url($s, PHP_URL_HOST); return $h ? preg_replace('/^www\./', '', $h) : '';
}

/** Nome do "dono" da carteira (cliente) ou da casa. */
function vdDono($carteira) {
    if (!$carteira) return ['nome' => defined('SD_EMPRESA_NOME') ? SD_EMPRESA_NOME : 'SD Studio Design', 'id' => null];
    $c = vdLinhas(vdSup('/rest/v1/clients?id=eq.' . $carteira . '&select=id,name'))[0] ?? null;
    return ['nome' => $c['name'] ?? 'a empresa', 'id' => $carteira];
}

// ------------------------------------------------------------ importar
/** Cada linha: "Empresa; site; cidade; segmento; contato; e-mail; zap" (separador ; , ou tab). Só o nome é obrigatório. */
function vdImportar($linhas, $carteira, $loteNome, $uid) {
    $lote = null;
    if ($loteNome) { $lr = vdLinhas(vdSup('/rest/v1/pd_lotes', 'POST', ['nome' => $loteNome, 'carteira_id' => $carteira, 'criado_por' => $uid])); $lote = $lr[0]['id'] ?? null; }
    $novos = 0; $dup = 0; $ignorados = 0; $ids = [];
    foreach (preg_split('/\R/u', (string)$linhas) as $ln) {
        $ln = trim($ln); if ($ln === '') continue;
        $c = array_map('trim', preg_split('/\t|;|,(?=(?:[^"]*"[^"]*")*[^"]*$)/u', $ln)); $c = array_map(fn($x) => trim($x, "\" "), $c);
        $nome = $c[0] ?? ''; if ($nome === '' || mb_strlen($nome) < 2 || preg_match('/^(empresa|nome)$/i', $nome)) { $ignorados++; continue; }
        $site = $c[1] ?? ''; $email = ''; $zap = '';
        foreach ($c as $i => $x) { if ($i && filter_var($x, FILTER_VALIDATE_EMAIL)) $email = strtolower($x); elseif ($i > 1 && preg_match('/^\+?[\d\s().-]{10,}$/', $x)) $zap = pdZapNormaliza($x); }
        if ($site !== '' && (filter_var($site, FILTER_VALIDATE_EMAIL) || preg_match('/^\+?[\d\s().-]{10,}$/', $site))) $site = '';
        $dom = vdDominio($site);
        if ($dom !== '') {
            $ex = vdLinhas(vdSup('/rest/v1/pd_prospects?dominio=eq.' . urlencode($dom) . '&carteira_id=' . ($carteira ? 'eq.' . $carteira : 'is.null') . '&select=id'));
            if ($ex) { $dup++; continue; }
        }
        $row = ['nome' => $nome, 'site' => $site ?: null, 'dominio' => $dom ?: null, 'cidade' => ($c[2] ?? '') ?: null, 'segmento' => ($c[3] ?? '') ?: null,
                'zap' => $zap ?: null, 'origem' => 'planilha', 'lote_id' => $lote, 'carteira_id' => $carteira, 'criado_por' => $uid];
        $r = vdLinhas(vdSup('/rest/v1/pd_prospects', 'POST', $row)); $pid = $r[0]['id'] ?? null;
        if (!$pid) { $ignorados++; continue; }
        $novos++; $ids[] = $pid;
        $contato = $c[4] ?? ''; if ($contato !== '' && (filter_var($contato, FILTER_VALIDATE_EMAIL) || preg_match('/^\+?[\d\s().-]{10,}$/', $contato))) $contato = '';
        if ($contato !== '' || $email !== '' || $zap !== '') vdSup('/rest/v1/pd_prospect_contatos', 'POST', ['prospect_id' => $pid, 'nome' => $contato ?: $nome, 'email' => $email ?: null, 'email_status' => $email ? 'palpite' : 'palpite', 'zap' => $zap ?: null, 'principal' => true, 'dono' => $contato !== '']);
    }
    return ['novos' => $novos, 'duplicados' => $dup, 'ignorados' => $ignorados, 'ids' => $ids, 'lote' => $lote];
}

// ------------------------------------------------------------ sinais (público, grátis)
function vdBuscarSite($dom) {
    if ($dom === '' || !pdHostSeguro($dom)) return null;
    foreach (['https://', 'http://'] as $pre) {
        $ch = curl_init($pre . $dom . '/');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; PontodotBot/1.0; +https://pontodot.ai)', CURLOPT_SSL_VERIFYPEER => true, CURLOPT_RANGE => '0-450000',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS]);
        $h = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $final = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL); curl_close($ch);
        $fh = parse_url($final, PHP_URL_HOST);
        if ($fh && !pdHostSeguro($fh)) return null;
        if ($h !== false && $code >= 200 && $code < 400 && strlen((string)$h) > 200) return (string)$h;
    }
    return null;
}
function vdSinaisDoHtml($html) {
    $s = [];
    $s['RODA_META_ADS']   = (bool)preg_match('/connect\.facebook\.net|fbq\(|facebook-pixel/i', $html);
    $s['RODA_GOOGLE_ADS'] = (bool)preg_match('/googleadservices|AW-\d{6,}|gtag\(.config.,\s*.AW-/i', $html);
    $s['SEM_SCHEMA']      = !preg_match('/application\/ld\+json/i', $html);
    $s['SEM_WHATSAPP']    = !preg_match('/wa\.me|api\.whatsapp\.com|whatsapp:\/\//i', $html);
    $s['TECNOLOGIA']      = preg_match('/wp-content|wp-includes/i', $html) ? 'WordPress' : (preg_match('/wix\.com|wixstatic/i', $html) ? 'Wix' : (preg_match('/squarespace/i', $html) ? 'Squarespace' : (preg_match('/webflow/i', $html) ? 'Webflow' : (preg_match('/shopify/i', $html) ? 'Shopify' : 'HTML/outra'))));
    $s['LANCAMENTO']      = (bool)preg_match('/lan[cç]amento|pr[eé]-?lan[cç]amento|breve lan[cç]amento|em obras|na planta/i', $html);
    $s['CONTRATANDO']     = (bool)preg_match('/trabalhe conosco|vagas abertas|estamos contratando/i', $html);
    if (preg_match('/(?:desenvolvido|criado|design|site)\s+(?:por|by)\s*[:\-]?\s*(?:<[^>]+>\s*)*([A-Za-zÀ-ú0-9 &.\-]{3,40})/iu', $html, $m)) $s['AGENCIA_ATUAL'] = trim(strip_tags($m[1]));
    if (preg_match('#instagram\.com/([A-Za-z0-9._]{2,30})#i', $html, $m) && !in_array(strtolower($m[1]), ['p', 'explore', 'accounts', 'reel', 'sharer'], true)) $s['INSTAGRAM'] = '@' . $m[1];
    $s['TAMANHO_SITE']    = substr_count(strtolower($html), '<a ') > 80 ? 'grande' : (substr_count(strtolower($html), '<a ') > 25 ? 'médio' : 'pequeno');
    return $s;
}
/** Nota de encaixe 1..100: o quanto a lacuna é "nomeável" num primeiro contato. Pesos simples e legíveis. */
function vdNota($s, $temContato) {
    $n = 20;
    if (!empty($s['RODA_META_ADS']) || !empty($s['RODA_GOOGLE_ADS'])) $n += 18;     // já investe: entende o valor
    if (!empty($s['LANCAMENTO'])) $n += 18;                                           // momento quente (nosso mercado)
    if (!empty($s['SEM_WHATSAPP'])) $n += 10;
    if (!empty($s['SEM_SCHEMA'])) $n += 6;
    if (!empty($s['CONTRATANDO'])) $n += 8;
    if (!empty($s['INSTAGRAM'])) $n += 5;
    if (!empty($s['AGENCIA_ATUAL'])) $n -= 6;                                         // já tem fornecedor: mais difícil
    if (($s['TECNOLOGIA'] ?? '') === 'Wix') $n += 5;
    if ($temContato) $n += 10;
    return max(1, min(100, $n));
}
function vdEnriquecer($pid) {
    $p = vdLinhas(vdSup('/rest/v1/pd_prospects?id=eq.' . $pid . '&select=*'))[0] ?? null; if (!$p) return ['erro' => 'lead não encontrado'];
    $sinais = (array)($p['sinais'] ?? []);
    $html = $p['dominio'] ? vdBuscarSite($p['dominio']) : null;
    $achou = [];
    if ($html) {
        $sinais = array_merge($sinais, vdSinaisDoHtml($html));
        if (preg_match_all('/mailto:([A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[a-z]{2,})/i', $html, $mm)) foreach (array_unique($mm[1]) as $e) { $e = strtolower($e); if (!preg_match('/\.(png|jpg|webp|gif)$|example\.|sentry|wixpress/', $e)) $achou['email'][] = $e; }
        if (preg_match('#(?:wa\.me/|phone=)(\d{10,13})#', $html, $m)) $achou['zap'] = pdZapNormaliza($m[1]);
        if (preg_match('/href="tel:([^"]+)"/i', $html, $m)) $achou['tel'] = preg_replace('/[^\d+]/', '', $m[1]);
    } else $sinais['SITE_FORA'] = $p['dominio'] ? 'o site não abriu' : 'sem site cadastrado';
    $cts = vdLinhas(vdSup('/rest/v1/pd_prospect_contatos?prospect_id=eq.' . $pid . '&select=id,email,zap'));
    if (!empty($achou['email'][0]) && !array_filter($cts, fn($c) => !empty($c['email']))) {
        vdSup('/rest/v1/pd_prospect_contatos', 'POST', ['prospect_id' => $pid, 'nome' => 'Contato do site', 'email' => $achou['email'][0], 'email_status' => 'arriscado', 'principal' => !$cts]);
        $sinais['CONTATO_ENCONTRADO'] = true;
    }
    $zap = $achou['zap'] ?? ($p['zap'] ?? null);
    $upd = ['sinais' => $sinais, 'nota' => vdNota($sinais, !empty($achou['email']) || !empty($cts)), 'zap' => $zap ?: ($p['zap'] ?? null)];
    if (!empty($sinais['INSTAGRAM'])) $upd['instagram'] = $sinais['INSTAGRAM'];
    if (!empty($sinais['AGENCIA_ATUAL'])) $upd['agencia_atual'] = $sinais['AGENCIA_ATUAL'];
    if (!empty($sinais['TECNOLOGIA']) && empty($p['porte'])) $upd['porte'] = null;
    vdSup('/rest/v1/pd_prospects?id=eq.' . $pid, 'PATCH', $upd);
    return ['ok' => true, 'nota' => $upd['nota'], 'sinais' => $sinais, 'site_abriu' => (bool)$html];
}

// ------------------------------------------------------------ escrever (o ângulo + a mensagem)
function vdFatos($p) {
    $s = (array)($p['sinais'] ?? []); $f = [];
    $f[] = 'Empresa: ' . $p['nome'] . (!empty($p['cidade']) ? ' (' . $p['cidade'] . (!empty($p['uf']) ? '/' . $p['uf'] : '') . ')' : '') . (!empty($p['segmento']) ? ' · segmento: ' . $p['segmento'] : '');
    if (!empty($p['site'])) $f[] = 'Site: ' . $p['site'];
    foreach ([['RODA_META_ADS', 'tem o pixel da Meta no site (anuncia ou já anunciou)'], ['RODA_GOOGLE_ADS', 'tem a tag do Google Ads no site'], ['SEM_WHATSAPP', 'o site não tem botão de WhatsApp'],
              ['SEM_SCHEMA', 'o site não tem marcação estruturada de SEO'], ['LANCAMENTO', 'o site fala de lançamento/pré-lançamento'], ['CONTRATANDO', 'tem vaga aberta no site'], ['SITE_PARADO', 'conteúdo/blog parado']] as [$k, $t])
        if (!empty($s[$k])) $f[] = 'Sinal verificado: ' . $t;
    if (!empty($s['TECNOLOGIA'])) $f[] = 'Plataforma do site: ' . $s['TECNOLOGIA'];
    if (!empty($s['INSTAGRAM'])) $f[] = 'Instagram: ' . $s['INSTAGRAM'];
    if (!empty($s['AGENCIA_ATUAL'])) $f[] = 'Crédito de quem fez o site: ' . $s['AGENCIA_ATUAL'];
    if (!empty($s['SITE_FORA'])) $f[] = 'Obs.: ' . $s['SITE_FORA'];
    return implode("\n", $f);
}

/**
 * Escreve UMA mensagem (rascunho, status 'aguardando'). $seq: 1 abertura, 2 follow-up, 3 despedida. $resposta: texto do lead, quando é resposta.
 * Devolve ['ok'=>true,'id'=>...] ou ['erro'=>...].
 */
function vdEscrever($pid, $canal, $seq = 1, $resposta = null, $oferta = '') {
    $p = vdLinhas(vdSup('/rest/v1/pd_prospects?id=eq.' . $pid . '&select=*'))[0] ?? null; if (!$p) return ['erro' => 'lead não encontrado'];
    $cts = vdLinhas(vdSup('/rest/v1/pd_prospect_contatos?prospect_id=eq.' . $pid . '&opt_out=eq.false&select=*&order=principal.desc,dono.desc'));
    $ct = null;
    foreach ($cts as $c) { if (($canal === 'email' && !empty($c['email'])) || ($canal === 'zap' && !empty($c['zap']))) { $ct = $c; break; } }
    if (!$ct && $canal === 'zap' && !empty($p['zap'])) $ct = ['id' => null, 'nome' => $p['nome'], 'zap' => $p['zap']];
    if (!$ct) return ['erro' => 'sem ' . ($canal === 'email' ? 'e-mail' : 'zap') . ' de contato (ou a pessoa pediu pra não receber)'];
    $dono = vdDono($p['carteira_id'] ?? null);
    $ia = strtolower($p['ia'] ?: 'tumi'); $iaNome = ucfirst($ia);
    $hist = vdLinhas(vdSup('/rest/v1/pd_prospect_conversas?prospect_id=eq.' . $pid . '&select=direcao,canal,corpo,status,created_at&order=created_at.asc&limit=12'));
    $conv = ''; foreach ($hist as $h) $conv .= ($h['direcao'] === 'entrada' ? 'LEAD: ' : 'NÓS: ') . mb_substr(preg_replace('/\s+/', ' ', $h['corpo']), 0, 400) . "\n";
    $sys = "Você é $iaNome, a IA de vendas de {$dono['nome']}. Escreve a abordagem a um lead de mercado imobiliário/arquitetura/marcas. Regras INEGOCIÁVEIS:\n"
        . "1) Use APENAS os fatos listados em FATOS. Nunca invente número, resultado, cliente, prêmio ou dado sobre o lead. Se não houver sinal bom, abra com uma pergunta honesta sobre o momento dele.\n"
        . "2) Escolha UM ângulo específico (o sinal mais concreto) e abra com ele. Nada de elogio genérico, nada de 'espero que esteja bem'.\n"
        . "3) Curto: e-mail até 90 palavras, zap até 45. Um único pedido simples no fim (uma pergunta ou 15 minutos de conversa). Português do Brasil, tom humano e direto.\n"
        . "4) Você é uma IA e isso não é segredo: assine como \"$iaNome · IA de vendas de {$dono['nome']}\". Não finja ser uma pessoa.\n"
        . "5) Sequência: 1 = abertura; 2 = follow-up com UM ângulo novo (não repita o 1); 3 = despedida gentil que deixa a porta aberta. Se há resposta do lead, responda a ELA primeiro, sem insistir se ela disse não.\n"
        . ($oferta ? "6) O que oferecemos (use só isso): $oferta\n" : '')
        . "Responda SOMENTE JSON: {\"angulo\":\"o fato que abre a conversa, em 1 frase\",\"assunto\":\"até 7 palavras, sem caixa alta (vazio no zap)\",\"corpo\":\"a mensagem pronta, sem assinatura repetida\"}";
    $user = "FATOS:\n" . vdFatos($p) . "\n\nCONTATO: " . ($ct['nome'] ?? '') . (!empty($ct['cargo']) ? ' (' . $ct['cargo'] . ')' : '') . "\nCANAL: $canal · MENSAGEM Nº $seq\n"
        . ($conv ? "\nCONVERSA ATÉ AQUI:\n$conv" : '') . ($resposta ? "\nRESPOSTA NOVA DO LEAD:\n" . mb_substr($resposta, 0, 800) : '');
    [$txt, , $err] = pdClaude($sys, $user, 900);
    if (!$txt) return ['erro' => 'a IA não respondeu: ' . $err];
    $j = pdJson($txt); if (!$j || empty($j['corpo'])) return ['erro' => 'a IA devolveu algo que não consegui ler'];
    $corpo = trim((string)$j['corpo']);
    $row = ['prospect_id' => $pid, 'contato_id' => $ct['id'] ?? null, 'canal' => $canal, 'direcao' => 'saida', 'sequencia' => $seq, 'assunto' => $canal === 'email' ? mb_substr(trim((string)($j['assunto'] ?? '')) ?: 'Uma ideia pra ' . $p['nome'], 0, 120) : null,
            'corpo' => $corpo, 'status' => 'aguardando', 'escrito_por' => $ia];
    $r = vdLinhas(vdSup('/rest/v1/pd_prospect_conversas', 'POST', $row)); $id = $r[0]['id'] ?? null;
    if (!$id) return ['erro' => 'não consegui gravar o rascunho'];
    if (!empty($j['angulo'])) vdSup('/rest/v1/pd_prospects?id=eq.' . $pid, 'PATCH', ['angulo' => mb_substr((string)$j['angulo'], 0, 300)]);
    return ['ok' => true, 'id' => $id, 'assunto' => $row['assunto'], 'corpo' => $corpo, 'angulo' => $j['angulo'] ?? ''];
}

// ------------------------------------------------------------ enviar (só o que gente aprovou)
function vdEnviadosHoje() {
    $ini = gmdate('Y-m-d\T00:00:00\Z', strtotime('today'));
    return count(vdLinhas(vdSup('/rest/v1/pd_prospect_conversas?status=eq.enviada&direcao=eq.saida&enviada_em=gte.' . $ini . '&select=id&limit=1000')));
}
function vdEnviar($convId, $uid) {
    $c = vdLinhas(vdSup('/rest/v1/pd_prospect_conversas?id=eq.' . $convId . '&select=*'))[0] ?? null; if (!$c) return ['erro' => 'mensagem não encontrada'];
    if ($c['status'] === 'enviada') return ['erro' => 'já foi enviada'];
    if (!in_array($c['status'], ['aguardando', 'aprovada'], true)) return ['erro' => 'status ' . $c['status'] . ' não envia'];
    $p = vdLinhas(vdSup('/rest/v1/pd_prospects?id=eq.' . $c['prospect_id'] . '&select=*'))[0] ?? null; if (!$p) return ['erro' => 'lead não encontrado'];
    if (in_array($p['estagio'], ['ganho', 'perdido'], true) && $c['sequencia'] != 99) return ['erro' => 'o lead está em "' . $p['estagio'] . '"'];
    $ct = $c['contato_id'] ? (vdLinhas(vdSup('/rest/v1/pd_prospect_contatos?id=eq.' . $c['contato_id'] . '&select=*'))[0] ?? null) : null;
    if ($ct && !empty($ct['opt_out'])) return ['erro' => 'essa pessoa pediu pra não receber mais'];
    if (vdEnviadosHoje() >= VD_TETO_DIA) return ['erro' => 'teto diário de ' . VD_TETO_DIA . ' envios atingido — amanhã segue', 'teto' => true];
    $dono = vdDono($p['carteira_id'] ?? null); $ia = ucfirst($p['ia'] ?: 'tumi');
    $ok = false; $erro = null; $wamid = null;
    if ($c['canal'] === 'email') {
        $para = $ct['email'] ?? ''; if (!filter_var($para, FILTER_VALIDATE_EMAIL)) return ['erro' => 'contato sem e-mail válido'];
        $rodape = "\n\n— $ia · IA de vendas de {$dono['nome']}\nSe não quiser receber mais, é só responder SAIR que eu paro por aqui.";
        $hdr = "From: " . $ia . " · " . preg_replace('/[\r\n<>"]/', '', $dono['nome']) . " <workflow@sergioduh.com>\r\nReply-To: contato@sergioduh.com\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\nX-PD-Prospect: " . $p['id'] . "\r\nList-Unsubscribe: <mailto:contato@sergioduh.com?subject=SAIR>";
        $ok = (bool)@mail($para, '=?UTF-8?B?' . base64_encode((string)$c['assunto']) . '?=', chunk_split(base64_encode($c['corpo'] . $rodape)), $hdr);
        if (!$ok) $erro = 'o servidor de e-mail recusou';
    } elseif ($c['canal'] === 'zap') {
        $tel = pdZapNormaliza($ct['zap'] ?? ($p['zap'] ?? '')); if (strlen($tel) < 12) return ['erro' => 'contato sem zap válido'];
        [$code, $raw] = pdZapEnviar($tel, $c['corpo'] . "\n\n— $ia · IA de vendas de {$dono['nome']}. Pra parar, responda SAIR.", false);
        $ok = $code >= 200 && $code < 300; $wamid = $ok ? pdWamid($raw) : null; if (!$ok) $erro = 'a Meta recusou (HTTP ' . $code . ')';
    } else return ['erro' => 'canal ' . $c['canal'] . ' é manual: registre quando fizer'];
    $agora = gmdate('c');
    vdSup('/rest/v1/pd_prospect_conversas?id=eq.' . $convId, 'PATCH', $ok ? ['status' => 'enviada', 'enviada_em' => $agora, 'aprovado_por' => $uid, 'wamid' => $wamid] : ['status' => 'falhou', 'motivo_retida' => $erro]);
    if ($ok) {
        $dias = ((int)$c['sequencia']) >= 2 ? 6 : 3;
        $up = ['ultimo_contato' => $agora, 'proximo_passo' => ((int)$c['sequencia']) >= 3 ? null : gmdate('c', time() + $dias * 86400)];
        if ($p['estagio'] === 'novo') $up['estagio'] = 'abordado';
        vdSup('/rest/v1/pd_prospects?id=eq.' . $p['id'], 'PATCH', $up);
    }
    return $ok ? ['ok' => true] : ['erro' => $erro];
}

// ------------------------------------------------------------ resposta do lead (entrada)
function vdClassificar($texto) {
    $t = mb_strtolower(trim($texto));
    if (preg_match('/^\s*sair\b|\bpare\b|descadastr|n[ãa]o (quero|tenho interesse)|remov(a|er) (meu|da)|me tire|spam/u', $t)) return 'opt_out';
    $sys = 'Classifique a resposta de um lead a uma abordagem comercial. Responda SOMENTE uma palavra entre: interessado, pergunta, objecao, pessoa_errada, nao, opt_out, juridico. "opt_out" = pede para não receber mais; "juridico" = ameaça, LGPD, notificação; "nao" = recusa educada sem pedir remoção.';
    [$r] = pdClaude($sys, mb_substr($texto, 0, 1200), 20);
    $r = strtolower(trim((string)$r)); return in_array($r, ['interessado', 'pergunta', 'objecao', 'pessoa_errada', 'nao', 'opt_out', 'juridico'], true) ? $r : 'pergunta';
}
/** Registra o que o lead respondeu, classifica e já age (opt-out é imediato). Devolve a intenção e, se couber, um rascunho de resposta. */
function vdEntrada($pid, $canal, $texto, $contatoId = null) {
    $p = vdLinhas(vdSup('/rest/v1/pd_prospects?id=eq.' . $pid . '&select=*'))[0] ?? null; if (!$p) return ['erro' => 'lead não encontrado'];
    $int = vdClassificar($texto);
    vdSup('/rest/v1/pd_prospect_conversas', 'POST', ['prospect_id' => $pid, 'contato_id' => $contatoId, 'canal' => $canal, 'direcao' => 'entrada', 'corpo' => mb_substr($texto, 0, 4000), 'status' => 'recebida', 'intencao' => $int]);
    $up = ['ultimo_contato' => gmdate('c'), 'proximo_passo' => null];
    $rascunho = null;
    if ($int === 'opt_out' || $int === 'juridico') {
        vdSup('/rest/v1/pd_prospect_contatos?prospect_id=eq.' . $pid, 'PATCH', ['opt_out' => true]);
        vdSup('/rest/v1/pd_prospect_conversas?prospect_id=eq.' . $pid . '&status=in.(aguardando,aprovada,rascunho)', 'PATCH', ['status' => 'retida', 'motivo_retida' => 'lead pediu para sair']);
        $up['estagio'] = 'perdido'; $up['motivo_perda'] = $int === 'juridico' ? 'jurídico/LGPD — não contatar' : 'pediu para sair';
    } elseif ($int === 'nao') { $up['estagio'] = 'perdido'; $up['motivo_perda'] = 'recusou'; }
    elseif ($int === 'pessoa_errada') { /* mantém: a equipe acha a pessoa certa */ }
    else {
        if (in_array($p['estagio'], ['novo', 'abordado'], true)) $up['estagio'] = 'engajado';
        $rr = vdEscrever($pid, $canal === 'zap' ? 'zap' : 'email', 2, $texto);
        if (!empty($rr['ok'])) $rascunho = $rr;
    }
    vdSup('/rest/v1/pd_prospects?id=eq.' . $pid, 'PATCH', $up);
    if (in_array($int, ['interessado', 'pergunta', 'objecao', 'juridico'], true)) {
        try { pdAlarme('vendas', ($int === 'interessado' ? '🔥 ' : '') . $p['nome'] . ' respondeu', mb_substr($texto, 0, 220) . ' — rascunho de resposta te espera em Vendas.', null, '/?vendas=1'); } catch (Throwable $e) {}
    }
    return ['ok' => true, 'intencao' => $int, 'rascunho' => $rascunho];
}

// ------------------------------------------------------------ cadência: quem pede o próximo toque
function vdCadencia($carteira, $max = 10) {
    $f = '/rest/v1/pd_prospects?estagio=in.(abordado)&proximo_passo=lte.' . gmdate('c') . '&select=id,nome&order=nota.desc.nullslast&limit=' . (int)$max
       . ($carteira === 'todas' ? '' : '&carteira_id=' . ($carteira ? 'eq.' . $carteira : 'is.null'));
    $feitos = [];
    foreach (vdLinhas(vdSup($f)) as $p) {
        $ult = vdLinhas(vdSup('/rest/v1/pd_prospect_conversas?prospect_id=eq.' . $p['id'] . '&direcao=eq.saida&select=sequencia,canal,status&order=created_at.desc&limit=6'));
        $pend = array_filter($ult, fn($x) => in_array($x['status'], ['aguardando', 'aprovada'], true)); if ($pend) continue;   // já tem rascunho esperando
        $seq = 0; $canal = 'email'; foreach ($ult as $x) { if ($x['status'] === 'enviada') { $seq = max($seq, (int)$x['sequencia']); $canal = $x['canal']; } }
        if ($seq >= 3 || $seq < 1) { vdSup('/rest/v1/pd_prospects?id=eq.' . $p['id'], 'PATCH', ['proximo_passo' => null]); continue; }
        $r = vdEscrever($p['id'], $canal === 'zap' ? 'zap' : 'email', $seq + 1);
        $feitos[] = ['lead' => $p['nome'], 'ok' => !empty($r['ok']), 'erro' => $r['erro'] ?? null];
    }
    return $feitos;
}

function vdFunil($carteira) {
    $f = '/rest/v1/pd_prospects?select=estagio' . ($carteira === 'todas' ? '' : '&carteira_id=' . ($carteira ? 'eq.' . $carteira : 'is.null')) . '&limit=5000';
    $o = array_fill_keys(VD_ESTAGIOS, 0); foreach (vdLinhas(vdSup($f)) as $x) $o[$x['estagio']] = ($o[$x['estagio']] ?? 0) + 1;
    return $o;
}
