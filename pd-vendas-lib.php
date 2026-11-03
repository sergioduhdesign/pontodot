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
/** A "casa" não é uma carteira à parte: é o cliente SD/STRAT, que já existe. */
function vdCasaId() {
    static $id = false; if ($id !== false) return $id;
    foreach (['*strat*', 'sd*'] as $pad) { $r = vdLinhas(vdSup('/rest/v1/clients?name=ilike.' . rawurlencode($pad) . '&active=is.true&select=id&order=name&limit=1')); if ($r) return $id = $r[0]['id']; }
    return $id = null;
}
function vdDono($carteira) {
    if (!$carteira) $carteira = vdCasaId();
    if (!$carteira) return ['nome' => defined('SD_EMPRESA_NOME') ? SD_EMPRESA_NOME : 'SD Studio Design', 'id' => null];
    $c = vdLinhas(vdSup('/rest/v1/clients?id=eq.' . $carteira . '&select=id,name'))[0] ?? null;
    return ['nome' => $c['name'] ?? 'a empresa', 'id' => $carteira];
}

// ------------------------------------------------------------ importar
/** Cada linha: "Empresa; site; cidade; segmento; contato; e-mail; zap" (separador ; , ou tab). Só o nome é obrigatório. */
function vdImportar($linhas, $carteira, $loteNome, $uid) {
    $lote = null;
    if ($loteNome) { $lr = vdLinhas(vdSup('/rest/v1/pd_lotes', 'POST', ['nome' => $loteNome, 'carteira_id' => $carteira, 'criado_por' => $uid])); $lote = $lr[0]['id'] ?? null; }
    $rows = [];
    foreach (preg_split('/\R/u', (string)$linhas) as $ln) {
        $ln = trim($ln); if ($ln === '') continue;
        $c = array_map(fn($x) => trim($x, "\" "), array_map('trim', preg_split('/\t|;/u', $ln)));
        if (preg_match('/^(empresa|nome)$/i', $c[0] ?? '')) continue;
        $email = ''; $zap = ''; $site = $c[1] ?? '';
        foreach ($c as $i => $x) { if ($i && filter_var($x, FILTER_VALIDATE_EMAIL)) $email = $x; elseif ($i > 1 && preg_match('/^\+?[\d\s().-]{10,}$/', $x)) $zap = $x; }
        if (filter_var($site, FILTER_VALIDATE_EMAIL) || preg_match('/^\+?[\d\s().-]{10,}$/', $site)) $site = '';
        $ct = $c[4] ?? ''; if (filter_var($ct, FILTER_VALIDATE_EMAIL) || preg_match('/^\+?[\d\s().-]{10,}$/', $ct)) $ct = '';
        $rows[] = ['nome' => $c[0] ?? '', 'site' => $site, 'cidade' => $c[2] ?? '', 'segmento' => $c[3] ?? '', 'contato' => $ct, 'email' => $email, 'zap' => $zap];
    }
    $r = vdImportarLinhas($rows, $carteira, $lote, $uid, 'planilha');
    if (!empty($r['erro'])) return ['novos' => 0, 'duplicados' => 0, 'ignorados' => count($rows), 'ids' => [], 'lote' => $lote];
    return $r + ['lote' => $lote];
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
function vdEnriquecer($pid, $htmlPre = false) {
    $p = vdLinhas(vdSup('/rest/v1/pd_prospects?id=eq.' . $pid . '&select=*'))[0] ?? null; if (!$p) return ['erro' => 'lead não encontrado'];
    $sinais = (array)($p['sinais'] ?? []); unset($sinais['PRE']);
    $html = $htmlPre !== false ? $htmlPre : ($p['dominio'] ? vdBuscarSite($p['dominio']) : null);
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
    $upd = ['enriquecido_em' => gmdate('c'), 'sinais' => $sinais, 'nota' => vdNota($sinais, !empty($achou['email']) || !empty($cts)), 'zap' => $zap ?: ($p['zap'] ?? null)];
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
        $cx = vdCaixaDe($p['carteira_id'] ?? null); if (!$cx) return ['erro' => 'ligue o e-mail de ' . $dono['nome'] . ' antes (Vendas › Caixa de saída › E-mail dos clientes)'];
        $rodape = "\n\n— $ia · IA de vendas de {$dono['nome']}\nSe não quiser receber mais, é só responder SAIR que eu paro por aqui.";
        $res = vdSmtpEnviar($cx, $para, $c['assunto'], $c['corpo'] . $rodape, $ia . ' · ' . preg_replace('/[\r\n<>"]/', '', $dono['nome']), 'X-PD-Prospect: ' . $p['id'] . "\r\n");
        $ok = $res === true; if (!$ok) $erro = (string)$res;
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
    $r = sd_supabase('/rest/v1/rpc/pd_vendas_funil', 'POST', ['p_carteira' => $carteira === 'todas' ? '*' : ($carteira ?: null)]);
    $o = array_fill_keys(VD_ESTAGIOS, 0);
    if (($r['code'] ?? 500) < 300 && is_array($r['body'])) { foreach ($r['body'] as $e => $n) $o[$e] = (int)$n; return $o; }
    $f = '/rest/v1/pd_prospects?select=estagio' . ($carteira === 'todas' ? '' : '&carteira_id=' . ($carteira ? 'eq.' . $carteira : 'is.null')) . '&limit=5000';   // SQL v147 ainda não rodou
    foreach (vdLinhas(vdSup($f)) as $x) $o[$x['estagio']] = ($o[$x['estagio']] ?? 0) + 1;
    return $o;
}

// =====================================================================
// ESCALA — base grande (30 mil), lotes, fila de leitura de sites, descoberta e caixa de entrada
// =====================================================================
function vdNorm($s) { $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string)$s); $t = strtolower($t !== false ? $t : (string)$s); return trim(preg_replace('/[^a-z0-9]+/', ' ', $t)); }
function vdMailGenerico($d) { return in_array(strtolower($d), ['gmail.com', 'hotmail.com', 'outlook.com', 'yahoo.com', 'yahoo.com.br', 'uol.com.br', 'bol.com.br', 'terra.com.br', 'ig.com.br', 'icloud.com', 'live.com', 'msn.com', 'globo.com', 'r7.com', 'me.com', 'protonmail.com'], true); }

/** Nota de PARTIDA, só com o que a planilha já diz (sem abrir site nenhum). Decide quem a fila lê primeiro. */
function vdPreNota($r) {
    $n = 15;
    if (!empty($r['site'])) $n += 12;
    if (!empty($r['email'])) $n += 8;
    if (!empty($r['zap'])) $n += 6;
    $seg = vdNorm(($r['segmento'] ?? '') . ' ' . ($r['nome'] ?? ''));
    if (preg_match('/incorporad|construt|imobili|loteador|loteament|urbaniz|empreendiment|arquitet|engenharia|residenc|condomin/', $seg)) $n += 25;
    if (!empty($r['cidade'])) $n += 3;
    if (!empty($r['porte']) && preg_match('/medio|grande|demais/', vdNorm($r['porte']))) $n += 6;
    return min(100, $n);
}

/** Importa um PEDAÇO de linhas já separadas por coluna (o navegador manda de 400 em 400). Deduplica por carteira+domínio (ou nome+cidade). */
function vdImportarLinhas(array $rows, $carteira, $lote, $uid, $origem = 'planilha') {
    $cartK = $carteira ?: 'casa'; $porChave = []; $ignorados = 0;
    foreach ($rows as $r) {
        $nome = trim((string)($r['nome'] ?? '')); if (mb_strlen($nome) < 2) { $ignorados++; continue; }
        $site = trim((string)($r['site'] ?? '')); $email = strtolower(trim((string)($r['email'] ?? ''))); if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $email = '';
        $zap = trim((string)($r['zap'] ?? '')) !== '' ? pdZapNormaliza($r['zap']) : ''; if (strlen($zap) < 12) $zap = '';
        $dom = vdDominio($site); $inferido = false;
        if ($dom === '' && $email !== '') { $ed = substr(strrchr($email, '@'), 1); if ($ed && !vdMailGenerico($ed)) { $dom = $ed; $inferido = true; } }   // base de CNPJ costuma ter e-mail, não site
        $chave = $cartK . '|' . ($dom !== '' ? $dom : vdNorm($nome) . '|' . vdNorm($r['cidade'] ?? ''));
        if (isset($porChave[$chave])) { $ignorados++; continue; }
        $r2 = ['nome' => $nome, 'site' => $site ?: null, 'email' => $email, 'zap' => $zap, 'cidade' => trim((string)($r['cidade'] ?? '')) ?: null, 'uf' => strtoupper(substr(trim((string)($r['uf'] ?? '')), 0, 2)) ?: null,
               'segmento' => trim((string)($r['segmento'] ?? '')) ?: null, 'porte' => trim((string)($r['porte'] ?? '')) ?: null, 'contato' => trim((string)($r['contato'] ?? '')), 'cargo' => trim((string)($r['cargo'] ?? ''))];
        $porChave[$chave] = ['row' => $r2, 'dom' => $dom, 'inf' => $inferido];
    }
    if (!$porChave) return ['novos' => 0, 'duplicados' => 0, 'ignorados' => $ignorados, 'ids' => []];
    $ins = [];
    foreach ($porChave as $chave => $x) { $r = $x['row'];
        $ins[] = ['nome' => $r['nome'], 'site' => $r['site'], 'dominio' => $x['dom'] ?: null, 'cidade' => $r['cidade'], 'uf' => $r['uf'], 'segmento' => $r['segmento'], 'porte' => $r['porte'], 'zap' => $r['zap'] ?: null,
                  'origem' => $origem, 'lote_id' => $lote, 'carteira_id' => $carteira, 'criado_por' => $uid, 'chave' => $chave, 'nota' => vdPreNota($r), 'sinais' => ['PRE' => true] + ($x['inf'] ? ['SITE_INFERIDO' => true] : [])]; }
    $r = sd_supabase('/rest/v1/pd_prospects?on_conflict=chave', 'POST', $ins, ['Prefer: resolution=ignore-duplicates,return=representation']);
    if (($r['code'] ?? 500) >= 300) return ['erro' => 'banco recusou: ' . mb_substr((string)($r['raw'] ?? ''), 0, 200)];
    $novos = (array)$r['body']; $cts = [];
    foreach ($novos as $n) { $x = $porChave[$n['chave']]['row'] ?? null; if (!$x) continue;
        if ($x['email'] !== '' || $x['zap'] !== '' || $x['contato'] !== '') $cts[] = ['prospect_id' => $n['id'], 'nome' => $x['contato'] ?: $x['nome'], 'cargo' => $x['cargo'] ?: null, 'email' => $x['email'] ?: null, 'zap' => $x['zap'] ?: null, 'email_status' => 'palpite', 'principal' => true, 'dono' => $x['contato'] !== ''];
    }
    if ($cts) sd_supabase('/rest/v1/pd_prospect_contatos', 'POST', $cts, ['Prefer: return=minimal']);
    return ['novos' => count($novos), 'duplicados' => count($porChave) - count($novos), 'ignorados' => $ignorados, 'ids' => array_column($novos, 'id')];
}

// ------------------------------------------------------------ filtro, contagem e lote (cohort)
function vdFiltroQs($b, $carteira) {
    $f = $carteira === 'todas' ? '' : '&carteira_id=' . ($carteira ? 'eq.' . $carteira : 'is.null');
    $lim = fn($v) => rawurlencode('*' . str_replace(['*', '%', ',', '(', ')'], ' ', $v) . '*');
    if (($q = trim((string)($b['q'] ?? ''))) !== '') $f .= '&nome=ilike.' . $lim($q);
    if (($uf = strtoupper(trim((string)($b['uf'] ?? '')))) !== '' && preg_match('/^[A-Z]{2}$/', $uf)) $f .= '&uf=eq.' . $uf;
    if (($c = trim((string)($b['cidade'] ?? ''))) !== '') $f .= '&cidade=ilike.' . $lim($c);
    if (($sg = trim((string)($b['segmento'] ?? ''))) !== '') $f .= '&segmento=ilike.' . $lim($sg);
    if (($nm = (int)($b['nota_min'] ?? 0)) > 0) $f .= '&nota=gte.' . min(100, $nm);
    $est = preg_replace('/[^a-z,]/', '', (string)($b['estagio'] ?? '')); if ($est !== '') $f .= '&estagio=in.(' . $est . ')';
    if (($lt = vdUuid($b['lote'] ?? ''))) $f .= '&lote_id=eq.' . $lt;
    return $f;
}
function vdContar($qs) {
    $ch = curl_init(SD_SUPABASE_URL . '/rest/v1/pd_prospects?select=id' . $qs);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => ['apikey: ' . SD_SECRET_KEY, 'Authorization: Bearer ' . SD_SECRET_KEY, 'Prefer: count=exact', 'Range-Unit: items', 'Range: 0-0']]);
    $raw = (string)curl_exec($ch); curl_close($ch);
    return preg_match('/content-range:\s*[\d*\-]+\/(\d+)/i', $raw, $m) ? (int)$m[1] : 0;
}
/** Transforma o filtro num LOTE (cohort): os $max melhores entram, e a fila lê o site deles em segundo plano. */
function vdLoteCriar($qs, $nome, $max, $carteira, $uid, $motivo = null) {
    $max = max(1, min(5000, (int)$max));
    $lr = vdLinhas(vdSup('/rest/v1/pd_lotes', 'POST', ['nome' => mb_substr($nome, 0, 120), 'motivo' => $motivo, 'carteira_id' => $carteira && $carteira !== 'todas' ? $carteira : null, 'criado_por' => $uid]));
    $lote = $lr[0]['id'] ?? null; if (!$lote) return ['erro' => 'não consegui criar o lote'];
    $ids = []; $off = 0;
    while (count($ids) < $max) {
        $take = min(1000, $max - count($ids));
        $pg = vdLinhas(vdSup('/rest/v1/pd_prospects?select=id&order=nota.desc.nullslast,created_at.asc&limit=' . $take . '&offset=' . $off . '&lote_id=is.null' . $qs));
        if (!$pg) break; foreach ($pg as $x) $ids[] = $x['id']; $off += $take; if (count($pg) < $take) break;
    }
    foreach (array_chunk($ids, 150) as $ch) vdSup('/rest/v1/pd_prospects?id=in.(' . implode(',', $ch) . ')', 'PATCH', ['lote_id' => $lote]);
    return ['lote' => $lote, 'dentro' => count($ids)];
}

// ------------------------------------------------------------ fila: lê muitos sites em paralelo (cron de 5 em 5 min)
function vdBuscarVarios(array $doms, $timeout = 8) {
    $mh = curl_multi_init(); $hs = []; $out = [];
    foreach ($doms as $d) { $out[$d] = null; if (!pdHostSeguro($d)) continue;
        $ch = curl_init('https://' . $d . '/');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; PontodotBot/1.0; +https://pontodot.ai)', CURLOPT_RANGE => '0-450000', CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS]);
        curl_multi_add_handle($mh, $ch); $hs[$d] = $ch; }
    do { curl_multi_exec($mh, $run); if ($run) curl_multi_select($mh, 1.0); } while ($run > 0);
    foreach ($hs as $d => $ch) { $h = curl_multi_getcontent($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $fh = parse_url((string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL), PHP_URL_HOST);
        if ($h && $code >= 200 && $code < 400 && strlen($h) > 200 && (!$fh || pdHostSeguro($fh))) $out[$d] = $h; curl_multi_remove_handle($mh, $ch); curl_close($ch); }
    curl_multi_close($mh); return $out;
}
function vdFilaEnriquecer($n = 40) {
    $ps = vdLinhas(vdSup('/rest/v1/pd_prospects?lote_id=not.is.null&enriquecido_em=is.null&select=id,dominio&order=nota.desc.nullslast&limit=' . (int)$n));
    if (!$ps) return 0;
    $doms = array_values(array_unique(array_filter(array_column($ps, 'dominio')))); $htmls = $doms ? vdBuscarVarios($doms) : [];
    foreach ($ps as $p) vdEnriquecer($p['id'], $p['dominio'] ? ($htmls[$p['dominio']] ?? null) : null);
    return count($ps);
}

// ------------------------------------------------------------ descobrir leads sozinho (Claude + busca na web)
function vdDescobrir($consulta, $quantos, $carteira, $uid) {
    if (!defined('SD_ANTHROPIC_KEY') || !SD_ANTHROPIC_KEY) return ['erro' => 'falta SD_ANTHROPIC_KEY'];
    $hoje = count(vdLinhas(vdSup('/rest/v1/pd_lotes?nome=like.Descoberta*&created_at=gte.' . gmdate('Y-m-d\T00:00:00\Z', strtotime('today')) . '&select=id&limit=50')));
    if ($hoje >= 10) return ['erro' => 'teto de 10 descobertas por dia atingido (cada uma gasta busca na web)'];
    $quantos = max(3, min(15, (int)$quantos)); $cart = $carteira && $carteira !== 'todas' ? $carteira : null;
    $sys = "Você acha EMPRESAS REAIS para prospecção comercial usando a busca na web. Regras: só inclua empresa cujo site apareceu nos resultados que você leu; nunca invente site, nome ou cidade; prefira as que mostram sinais de momento (lançamento, obras, anúncios). "
         . "Responda SOMENTE um JSON array de até $quantos itens: [{\"nome\":\"\",\"site\":\"\",\"cidade\":\"\",\"uf\":\"\",\"segmento\":\"\",\"instagram\":\"\",\"motivo\":\"1 frase do porquê é um bom lead\"}]. Sem texto fora do JSON.";
    $base = defined('PD_ANTHROPIC_BASE') ? rtrim(PD_ANTHROPIC_BASE, '/') : 'https://api.anthropic.com';
    $ch = curl_init($base . '/v1/messages');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 150,
        CURLOPT_HTTPHEADER => ['x-api-key: ' . SD_ANTHROPIC_KEY, 'anthropic-version: 2023-06-01', 'content-type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['model' => defined('SD_ALUNA_MODEL') ? SD_ALUNA_MODEL : 'claude-sonnet-4-5', 'max_tokens' => 3000, 'system' => $sys,
            'tools' => [['type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => 6]], 'messages' => [['role' => 'user', 'content' => 'Achar leads: ' . mb_substr($consulta, 0, 400)]]])]);
    $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code !== 200) { $m = json_decode((string)$raw, true); return ['erro' => 'a busca na web não respondeu (HTTP ' . $code . ')' . (!empty($m['error']['message']) ? ': ' . mb_substr($m['error']['message'], 0, 160) : '') . ($code === 400 ? ' — confira se a busca na web está habilitada na sua conta da Anthropic' : '')]; }
    $j = json_decode((string)$raw, true); $t = ''; foreach ((array)($j['content'] ?? []) as $bl) if (($bl['type'] ?? '') === 'text') $t .= $bl['text'];
    if (!preg_match('/\[.*\]/s', $t, $m)) return ['erro' => 'a IA não devolveu uma lista'];
    $lista = json_decode($m[0], true); if (!is_array($lista) || !$lista) return ['erro' => 'não achei empresas com site confirmado'];
    $lr = vdLinhas(vdSup('/rest/v1/pd_lotes', 'POST', ['nome' => 'Descoberta ' . date('d/m H:i') . ' · ' . mb_substr($consulta, 0, 60), 'motivo' => $consulta, 'carteira_id' => $cart, 'criado_por' => $uid]));
    $lote = $lr[0]['id'] ?? null; $rows = [];
    foreach ($lista as $x) { if (!is_array($x) || empty($x['nome']) || empty($x['site'])) continue; $rows[] = ['nome' => $x['nome'], 'site' => $x['site'], 'cidade' => $x['cidade'] ?? '', 'uf' => $x['uf'] ?? '', 'segmento' => $x['segmento'] ?? '']; }
    $r = vdImportarLinhas($rows, $cart, $lote, $uid, 'descoberta');
    if (empty($r['ids'])) return ['erro' => 'tudo que achei já estava na carteira', 'duplicados' => $r['duplicados'] ?? 0];
    foreach (array_slice($r['ids'], 0, 15) as $id) vdEnriquecer($id);   // já lê os sites
    foreach ($lista as $x) if (!empty($x['motivo']) && !empty($x['site'])) { $d = vdDominio($x['site']); if ($d) vdSup('/rest/v1/pd_prospects?dominio=eq.' . urlencode($d) . '&angulo=is.null' . ($cart ? '&carteira_id=eq.' . $cart : '&carteira_id=is.null'), 'PATCH', ['angulo' => mb_substr((string)$x['motivo'], 0, 300)]); }
    return ['ok' => true, 'novos' => $r['novos'], 'duplicados' => $r['duplicados'], 'lote' => $lote];
}

// ------------------------------------------------------------ caixa de entrada (IMAP): a resposta do lead entra sozinha
function vdImapCfg() { return (defined('SD_IMAP_HOST') && defined('SD_IMAP_USER') && defined('SD_IMAP_PASS') && SD_IMAP_PASS) ? ['h' => SD_IMAP_HOST, 'u' => SD_IMAP_USER, 'p' => SD_IMAP_PASS, 'port' => defined('SD_IMAP_PORT') ? SD_IMAP_PORT : 993] : null; }
function vdImapCmd($fp, $tag, $cmd) {
    fwrite($fp, "$tag $cmd\r\n"); $out = '';
    while (($l = fgets($fp, 16384)) !== false) {
        $out .= $l;
        if (preg_match('/\{(\d+)\}\r\n$/', $l, $m)) { $n = (int)$m[1]; $buf = ''; while (strlen($buf) < $n) { $c = fread($fp, $n - strlen($buf)); if ($c === '' || $c === false) break; $buf .= $c; } $out .= $buf; continue; }
        if (strpos($l, "$tag ") === 0) break;
    }
    return $out;
}
function vdMimeCabecalhos($raw) {
    $p = preg_split('/\r?\n\r?\n/', $raw, 2); $h = []; $cur = null;
    foreach (preg_split('/\r?\n/', $p[0]) as $ln) { if ($ln !== '' && ($ln[0] === ' ' || $ln[0] === "\t") && $cur) { $h[$cur] .= ' ' . trim($ln); continue; } if (strpos($ln, ':') !== false) { [$k, $v] = explode(':', $ln, 2); $cur = strtolower(trim($k)); $h[$cur] = trim($v); } }
    return [$h, $p[1] ?? ''];
}
function vdMimeTexto($raw, $nivel = 0) {
    [$h, $corpo] = vdMimeCabecalhos($raw); $ct = $h['content-type'] ?? 'text/plain';
    if ($nivel < 4 && preg_match('/multipart\/\w+;.*boundary="?([^";\s]+)"?/is', $ct, $m)) {
        $melhor = ''; $html = '';
        foreach (preg_split('/\r?\n--' . preg_quote($m[1], '/') . '(?:--)?/', "\n" . $corpo) as $parte) { $parte = ltrim($parte, "\r\n"); if (trim($parte) === '' || $parte === '--') continue;
            $t = vdMimeTexto($parte, $nivel + 1); [$ph] = vdMimeCabecalhos($parte); $pct = strtolower($ph['content-type'] ?? 'text/plain');
            if (strpos($pct, 'text/plain') !== false && $melhor === '') $melhor = $t; elseif (strpos($pct, 'text/html') !== false && $html === '') $html = $t; elseif (strpos($pct, 'multipart') !== false && $melhor === '') $melhor = $t; }
        return $melhor !== '' ? $melhor : $html;
    }
    if (!preg_match('/^text\//i', $ct) && $ct !== '') return '';
    $cte = strtolower($h['content-transfer-encoding'] ?? '7bit');
    $b = $cte === 'base64' ? base64_decode($corpo) : ($cte === 'quoted-printable' ? quoted_printable_decode($corpo) : $corpo);
    if (preg_match('/charset="?([\w-]+)/i', $ct, $cm) && !preg_match('/utf-?8/i', $cm[1])) { $c = @mb_convert_encoding($b, 'UTF-8', $cm[1]); if ($c !== false) $b = $c; }
    if (stripos($ct, 'text/html') !== false) { $b = preg_replace('/<(br|\/p|\/div|\/tr)\s*\/?>/i', "\n", preg_replace('/<(style|script)[^>]*>.*?<\/\1>/is', '', $b)); $b = html_entity_decode(strip_tags($b), ENT_QUOTES, 'UTF-8'); }
    return trim($b);
}
/** Só o que a pessoa escreveu de novo: corta a citação da mensagem anterior. */
function vdSemCitacao($t) {
    $out = [];
    foreach (preg_split('/\r?\n/', $t) as $ln) { if (preg_match('/^(Em .{5,120}escreveu:?|On .{5,120}wrote:?|-{2,}\s*(Original|Mensagem original)|De:\s|From:\s|Enviado de meu)/iu', trim($ln)) || (isset($ln[0]) && $ln[0] === '>')) break; $out[] = $ln; }
    return trim(mb_substr(implode("\n", $out), 0, 1500));
}
function vdInboxCaixa($cx, $max = 25) {
    $cfg = ['h' => $cx['imap_host'], 'u' => $cx['usuario'], 'p' => $cx['senha'], 'port' => (int)($cx['imap_porta'] ?: 993)];
    [$fp, $err] = vdImapAbrir($cx);
    if (!$fp) { $msg0 = $err . ' — ' . $cx['email']; vdSup('/rest/v1/pd_vendas_caixas?id=eq.' . $cx['id'], 'PATCH', ['ultimo_erro' => $err]); return ['erro' => $msg0]; }
    $q = fn($v) => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"';
    if (stripos(vdImapCmd($fp, 'a1', 'LOGIN ' . $q($cfg['u']) . ' ' . $q($cfg['p'])), 'a1 OK') === false) { fclose($fp); vdSup('/rest/v1/pd_vendas_caixas?id=eq.' . $cx['id'], 'PATCH', ['ultimo_erro' => 'o e-mail recusou o login (usuário ou senha)']); return ['erro' => 'o e-mail de ' . $cx['email'] . ' recusou o login (usuário ou senha)']; }
    vdImapCmd($fp, 'a2', 'SELECT INBOX');
    $ultimo = (int)($cx['ultimo_uid'] ?? 0);
    $busca = vdImapCmd($fp, 'a3', 'UID SEARCH UNSEEN'); $uids = [];
    if (preg_match('/\* SEARCH([\d ]*)/', $busca, $m)) $uids = array_values(array_filter(array_map('intval', preg_split('/\s+/', trim($m[1]))), fn($u) => $u > $ultimo));
    sort($uids); $uids = array_slice($uids, 0, $max); $r = ['lidas' => 0, 'respostas' => 0, 'bounces' => 0, 'ignoradas' => 0]; $tag = 10; $maxUid = $ultimo;
    foreach ($uids as $uid) {
        $tag++; $resp = vdImapCmd($fp, 'b' . $tag, "UID FETCH $uid (BODY.PEEK[])"); $maxUid = max($maxUid, $uid);
        if (!preg_match('/\{(\d+)\}\r\n/', $resp, $m, PREG_OFFSET_CAPTURE)) continue; $raw = substr($resp, $m[0][1] + strlen($m[0][0]), (int)$m[1][0]); $r['lidas']++;
        [$h] = vdMimeCabecalhos($raw); $de = strtolower((string)(preg_match('/[\w.+\-]+@[\w\-]+\.[\w.\-]+/', $h['from'] ?? '', $mm) ? $mm[0] : '')); $assunto = (string)@iconv_mime_decode($h['subject'] ?? '', 0, 'UTF-8');
        $texto = vdMimeTexto($raw);
        if ($de === '') { $r['ignoradas']++; continue; }
        if (preg_match('/mailer-daemon|postmaster/i', $de) || preg_match('/undeliver|delivery status|falha na entrega|returned mail|n[ãa]o foi entregue/i', $assunto)) {   // e-mail que não existe: marca e segue
            if (preg_match_all('/[\w.+\-]+@[\w\-]+\.[\w.\-]+/', $texto, $ems)) foreach (array_unique($ems[0]) as $e) { $e = strtolower($e); if (strpos($e, 'sergioduh') !== false || preg_match('/mailer-daemon|postmaster/', $e)) continue;
                vdSup('/rest/v1/pd_prospect_contatos?email=eq.' . urlencode($e), 'PATCH', ['email_status' => 'invalido']); }
            $r['bounces']++; vdImapCmd($fp, 'c' . $tag, "UID STORE $uid +FLAGS (\\Seen)"); continue; }
        $auto = (isset($h['auto-submitted']) && !preg_match('/^no/i', $h['auto-submitted'])) || preg_match('/resposta autom|out of office|fora do escrit[óo]rio|ausente/i', $assunto);
        if ($auto) { $r['ignoradas']++; continue; }
        $ct = vdLinhas(vdSup('/rest/v1/pd_prospect_contatos?email=eq.' . urlencode($de) . '&select=id,prospect_id&limit=1'))[0] ?? null; $pid = $ct['prospect_id'] ?? null;
        if (!$pid) { $dd = substr(strrchr($de, '@'), 1); if ($dd && !vdMailGenerico($dd)) $pid = vdLinhas(vdSup('/rest/v1/pd_prospects?dominio=eq.' . urlencode($dd) . '&select=id&order=ultimo_contato.desc.nullslast&limit=1'))[0]['id'] ?? null; }
        if (!$pid) { $r['ignoradas']++; continue; }   // não é de nenhum lead: deixa pra um humano
        $novo = vdSemCitacao($texto); if ($novo === '') $novo = mb_substr($texto, 0, 800);
        vdEntrada($pid, 'email', $novo, $ct['id'] ?? null); $r['respostas']++;
        vdImapCmd($fp, 'c' . $tag, "UID STORE $uid +FLAGS (\\Seen)");
    }
    vdImapCmd($fp, 'z', 'LOGOUT'); fclose($fp);
    vdSup('/rest/v1/pd_vendas_caixas?id=eq.' . $cx['id'], 'PATCH', ['ultimo_uid' => max($maxUid, $ultimo), 'ultimo_erro' => null, 'ultima_leitura' => gmdate('c')]);
    return $r;
}


// ------------------------------------------------------------ caixas de e-mail POR CLIENTE (cada carteira fala pelo e-mail dela)
function vdCaixaChave() { return hash('sha256', (defined('SD_SECRET_KEY') ? SD_SECRET_KEY : 'pd') . '|pd-caixa-v1', true); }
function vdCifrar($t) { $iv = random_bytes(12); $tag = ''; $ct = openssl_encrypt($t, 'aes-256-gcm', vdCaixaChave(), OPENSSL_RAW_DATA, $iv, $tag); return base64_encode($iv . $tag . $ct); }
function vdDecifrar($b) { $d = base64_decode((string)$b, true); if ($d === false || strlen($d) < 29) return ''; $r = openssl_decrypt(substr($d, 28), 'aes-256-gcm', vdCaixaChave(), OPENSSL_RAW_DATA, substr($d, 0, 12), substr($d, 12, 16)); return $r === false ? '' : $r; }
/** A caixa do cliente (com a senha já aberta). Sem carteira = a casa (SD/STRAT). */
function vdCaixaDe($carteira) {
    $carteira = $carteira ?: vdCasaId(); if (!$carteira) return null;
    $r = vdLinhas(vdSup('/rest/v1/pd_vendas_caixas?client_id=eq.' . $carteira . '&ativo=is.true&select=*&limit=1'))[0] ?? null;
    if (!$r || empty($r['senha_cifrada'])) return null; $r['senha'] = vdDecifrar($r['senha_cifrada']); unset($r['senha_cifrada']);
    return $r['senha'] === '' ? null : $r;
}
function vdCaixasTodas() {
    $o = []; foreach (vdLinhas(vdSup('/rest/v1/pd_vendas_caixas?ativo=is.true&select=*')) as $r) { if (empty($r['senha_cifrada'])) continue; $r['senha'] = vdDecifrar($r['senha_cifrada']); unset($r['senha_cifrada']); if ($r['senha'] !== '') $o[] = $r; }
    return $o;
}
/** Descobre sozinho os servidores do e-mail a partir do endereço — o usuário só informa e-mail e senha. */
function vdProvedor($dom) {
    $mx = ''; foreach ((array)@dns_get_record($dom, DNS_MX) as $r) $mx .= ' ' . strtolower($r['target'] ?? '');
    if (preg_match('/google\.com|googlemail\.com/', $mx)) return ['imap' => [['imap.gmail.com', 993]], 'smtp' => [['smtp.gmail.com', 465]]];
    if (preg_match('/outlook\.com|protection\.outlook|office365/', $mx)) return ['imap' => [['outlook.office365.com', 993]], 'smtp' => [['smtp.office365.com', 587]]];
    return null;
}
function vdCandidatos($cx, $lado) {
    $dom = strtolower(substr(strrchr($cx['email'], '@'), 1)); $h = trim((string)($cx[$lado . '_host'] ?? '')); $p = (int)($cx[$lado . '_porta'] ?? 0);
    $auto = $h === '' || $h === 'mail.' . $dom;
    if (!$auto) return [[$h, $p ?: ($lado === 'imap' ? 993 : 465)]];
    if ($pv = vdProvedor($dom)) return $pv[$lado];
    if ($lado === 'imap') return [['mail.' . $dom, 993], ['imap.' . $dom, 993], [$dom, 993]];
    return [['mail.' . $dom, 465], ['mail.' . $dom, 587], ['smtp.' . $dom, 465], ['smtp.' . $dom, 587], [$dom, 465]];
}
/** Nome do servidor pelo IP (a hospedagem compartilhada costuma ter o certificado no nome dele, não no do domínio). */
function vdPtr($host) { $ip = @gethostbyname($host); if ($ip === $host) return null; $n = @gethostbyaddr($ip); return ($n && $n !== $ip) ? rtrim($n, '.') : null; }
/** Conecta com certificado SEMPRE verificado: no nome pedido e, se não bater, no nome real do servidor. */
function vdTlsConecta($host, $port, $implicito) {
    $peers = [null]; $pt = vdPtr($host); if ($pt && $pt !== $host) $peers[] = $pt;
    foreach ($peers as $peer) {
        $o = ['verify_peer' => true, 'verify_peer_name' => true]; if ($peer) $o['peer_name'] = $peer;
        $fp = @stream_socket_client(($implicito ? 'ssl://' : 'tcp://') . $host . ':' . $port, $en, $es, 12, STREAM_CLIENT_CONNECT, stream_context_create(['ssl' => $o]));
        if ($fp) return $fp;
    }
    return null;
}
function vdCaixaLembra($cx, $lado, $h, $p) {
    if (empty($cx['id']) || (($cx[$lado . '_host'] ?? '') === $h && (int)($cx[$lado . '_porta'] ?? 0) === (int)$p)) return;
    vdSup('/rest/v1/pd_vendas_caixas?id=eq.' . $cx['id'], 'PATCH', [$lado . '_host' => $h, $lado . '_porta' => (int)$p]);
}
function vdImapAbrir($cx) {
    $cand = vdCandidatos($cx, 'imap');
    foreach ($cand as [$h, $p]) { $fp = vdTlsConecta($h, $p, true); if ($fp) { stream_set_timeout($fp, 25); fgets($fp); vdCaixaLembra($cx, 'imap', $h, $p); return [$fp, null]; } }
    return [null, 'não conectei na entrada: ' . vdPorQue($cand[0][0], $cand[0][1]) . (count($cand) > 1 ? ' (tentei ' . implode(', ', array_map(fn($c) => $c[0], $cand)) . ')' : '')];
}
/** Descobre o motivo de não conectar, em português claro: endereço, porta ou certificado. */
function vdPorQue($h, $p) {
    if (gethostbyname($h) === $h) return "o endereço $h não existe";
    $t = @fsockopen($h, (int)$p, $en, $es, 8); if (!$t) return "o servidor $h não atende na porta $p";
    fclose($t); return "o servidor $h atende, mas o certificado de segurança não é desse nome";
}
function vdSmtpLe($fp) { $o = ''; while (($l = fgets($fp, 2048)) !== false) { $o .= $l; if (strlen($l) < 4 || $l[3] === ' ') break; } return $o; }
function vdSmtpCmd($fp, $c) { fwrite($fp, $c . "\r\n"); return vdSmtpLe($fp); }
/** Abre o SMTP já autenticado. Devolve [$fp, null] ou [null, 'motivo']. Sempre com o certificado verificado. */
function vdSmtpAbrir($cx) {
    $cand = vdCandidatos($cx, 'smtp'); $fp = null; $h = $port = null;
    foreach ($cand as [$h, $port]) { $fp = vdTlsConecta($h, $port, $port === 465); if ($fp) break; }
    if (!$fp) return [null, 'não conectei no envio: ' . vdPorQue($cand[0][0], $cand[0][1]) . (count($cand) > 1 ? ' (tentei ' . implode(', ', array_unique(array_map(fn($c) => $c[0], $cand))) . ')' : '')];
    stream_set_timeout($fp, 25); vdSmtpLe($fp); $eh = 'pontodot.ai';
    vdSmtpCmd($fp, "EHLO $eh");
    if ($port !== 465) { vdSmtpCmd($fp, 'STARTTLS'); if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) { fclose($fp); return [null, "o envio ($h:$port) não aceitou conexão segura"]; } vdSmtpCmd($fp, "EHLO $eh"); }
    vdSmtpCmd($fp, 'AUTH LOGIN'); vdSmtpCmd($fp, base64_encode($cx['usuario'])); $a = vdSmtpCmd($fp, base64_encode($cx['senha']));
    if (strpos($a, '235') !== 0) { fclose($fp); return [null, 'o envio recusou o login (usuário ou senha)']; }
    vdCaixaLembra($cx, 'smtp', $h, $port);
    return [$fp, null];
}
function vdSmtpEnviar($cx, $para, $assunto, $corpo, $nomeDe, $extra = '') {
    [$fp, $err] = vdSmtpAbrir($cx); if (!$fp) return $err;
    $de = $cx['email']; $dom = substr(strrchr($de, '@'), 1) ?: 'pontodot.ai';
    $m = "From: =?UTF-8?B?" . base64_encode($nomeDe) . "?= <$de>\r\nTo: <$para>\r\nReply-To: $de\r\nSubject: =?UTF-8?B?" . base64_encode((string)$assunto) . "?=\r\nDate: " . date('r') . "\r\nMessage-ID: <" . bin2hex(random_bytes(10)) . "@$dom>\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\nList-Unsubscribe: <mailto:$de?subject=SAIR>\r\n" . $extra . "\r\n" . chunk_split(base64_encode($corpo));
    $m = preg_replace('/^\./m', '..', $m);
    $r1 = vdSmtpCmd($fp, "MAIL FROM:<$de>"); $r2 = vdSmtpCmd($fp, "RCPT TO:<$para>"); $r3 = vdSmtpCmd($fp, 'DATA');
    if (strpos($r1, '250') !== 0 || strpos($r2, '25') !== 0 || strpos($r3, '354') !== 0) { vdSmtpCmd($fp, 'QUIT'); fclose($fp); return 'o servidor recusou o destinatário: ' . trim(mb_substr($r2 . ' ' . $r3, 0, 160)); }
    fwrite($fp, $m . "\r\n.\r\n"); $f = vdSmtpLe($fp); vdSmtpCmd($fp, 'QUIT'); fclose($fp);
    return strpos($f, '250') === 0 ? true : 'o servidor recusou a mensagem: ' . trim(mb_substr($f, 0, 160));
}
/** Testa a caixa sem mandar nada: entrada (IMAP) e saída (SMTP). */
function vdCaixaTestar($cx) {
    $r = ['entrada' => null, 'saida' => null];
    [$fp, $err] = vdImapAbrir($cx);
    if (!$fp) $r['entrada'] = $err;
    else { $q = fn($v) => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"';
        $r['entrada'] = stripos(vdImapCmd($fp, 'a1', 'LOGIN ' . $q($cx['usuario']) . ' ' . $q($cx['senha'])), 'a1 OK') !== false ? true : 'o e-mail recusou o login (usuário ou senha)'; fclose($fp); }
    [$sp, $err] = vdSmtpAbrir($cx); if ($sp) { vdSmtpCmd($sp, 'QUIT'); fclose($sp); $r['saida'] = true; } else $r['saida'] = $err;
    return $r;
}
function vdInboxLer($max = 25, $soCarteira = null) {
    $cxs = $soCarteira ? array_filter([vdCaixaDe($soCarteira)]) : vdCaixasTodas();
    if (!$cxs) return ['pulado' => 'nenhum cliente com e-mail ligado'];
    $t = ['lidas' => 0, 'respostas' => 0, 'bounces' => 0, 'ignoradas' => 0]; $erros = [];
    foreach ($cxs as $cx) { try { $r = vdInboxCaixa($cx, $max); } catch (Throwable $e) { $r = ['erro' => $e->getMessage()]; }
        if (!empty($r['erro'])) { $erros[] = $r['erro']; continue; } foreach ($t as $k => $_) $t[$k] += (int)($r[$k] ?? 0); }
    if ($erros) $t['erro'] = implode(' · ', $erros);
    return $t;
}

/** Um tique do relógio da casa: caixa de entrada, fila de sites e (de hora em hora) os follow-ups. */
function vdTick() {
    $o = [];
    try { $o['inbox'] = vdInboxLer(25); } catch (Throwable $e) { $o['inbox'] = ['erro' => $e->getMessage()]; }
    try { $o['sites'] = vdFilaEnriquecer(40); } catch (Throwable $e) { $o['sites'] = 'erro ' . $e->getMessage(); }
    try { if ((int)date('i') < 5) $o['follow'] = count(vdCadencia('todas', 4)); } catch (Throwable $e) {}
    return $o;
}
