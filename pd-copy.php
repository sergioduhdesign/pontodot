<?php
// Pontodot — COPYS: a área de narrativa do PD · Studio Design.
// Briefing rápido + dados estratégicos (texto, links, docs do Drive) + estilos (da casa, do cliente, de redatores) → variantes.
// Equipe/admin. Guarda em pd_copys; estilos em pd_estilos; as referências da casa vêm da pasta PD · REFERÊNCIAS › COPYS no Drive.
require_once __DIR__ . '/sd-auth.php';
require_once __DIR__ . '/pd-lib.php';
define('PD_SO_FUNCOES', 1); require_once __DIR__ . '/pd-estudio-ia.php';   // paClaude / paReferencias / paJson (sem rodar o roteador de lá)
@set_time_limit(280);
header('Content-Type: application/json; charset=utf-8');
$u = sd_current_user(); if (!in_array($u['role'], ['admin', 'team'], true)) sd_fail('Só a equipe.', 403);
$b = sd_body(); $acao = (string)($b['acao'] ?? 'inicio');

// estilos-base da casa: vozes de redatores e escolas que funcionam (o banco de referências do Drive soma a estes)
function cpEstilosBase() { return [
  ['id' => 'ogilvy', 'nome' => 'Ogilvy · fato e benefício', 'resumo' => 'Clareza, informação útil, promessa concreta. Título longo com fato específico.', 'dna' => 'Começa pelo benefício mais forte. Usa fatos, números e nomes próprios. Frases completas, sem gíria. Nunca esperto demais: o leitor é inteligente. Fecha com uma razão para acreditar.'],
  ['id' => 'bernbach', 'nome' => 'Bernbach · ideia única', 'resumo' => 'Uma ideia só, humor inteligente, honestidade desarmante (Think small).', 'dna' => 'Uma única ideia por peça. Admite o defeito e vira virtude. Título curto e inesperado; texto que conversa. Nunca repete o que a imagem mostra.'],
  ['id' => 'olivetto', 'nome' => 'Olivetto · brasilidade afetiva', 'resumo' => 'Calor humano, cotidiano brasileiro, trocadilho com propósito, frase que vira bordão.', 'dna' => 'Fala como o brasileiro fala. Cena do dia a dia com emoção. Jogo de palavras só quando reforça a ideia. Bordão memorável no fim.'],
  ['id' => 'nizan', 'nome' => 'Nizan · frase-conceito', 'resumo' => 'Conceito em uma frase, grandiosidade com simplicidade, otimismo.', 'dna' => 'Uma frase-conceito que resume a marca. Ritmo de slogan. Otimismo sem ingenuidade. Palavras grandes usadas com economia.'],
  ['id' => 'hemingway', 'nome' => 'Hemingway · frase curta', 'resumo' => 'Frases curtas, verbos fortes, zero adjetivo inútil. Iceberg: diz 20%, sugere 80%.', 'dna' => 'Frases curtas. Verbos no presente. Corta adjetivos e advérbios. Deixa o não-dito trabalhar. Sem exclamação.'],
  ['id' => 'godin', 'nome' => 'Seth Godin · uma ideia por parágrafo', 'resumo' => 'Parágrafos de uma linha, provocação, permissão. Fala com a tribo.', 'dna' => 'Parágrafos de 1–2 linhas. Começa com uma provocação. Fala com quem já quer ouvir (a tribo). Termina com uma pergunta ou um convite, não com venda.'],
  ['id' => 'apple', 'nome' => 'Apple · minimalismo', 'resumo' => 'Pouquíssimas palavras, produto como herói, verbo + adjetivo.', 'dna' => 'Duas a cinco palavras no título. Produto no centro. Adjetivo inesperado com verbo simples. Nada de explicação: o detalhe fala.'],
  ['id' => 'nike', 'nome' => 'Nike · imperativo', 'resumo' => 'Ordem curta, atitude, segunda pessoa, verbo de ação.', 'dna' => 'Começa com verbo no imperativo. Fala com você. Tensão e superação. Nunca descreve o produto: descreve quem usa.'],
  ['id' => 'storytelling', 'nome' => 'Storytelling · jornada', 'resumo' => 'Personagem, conflito, virada, recompensa. A marca é o guia, não o herói.', 'dna' => 'Abre numa cena concreta. Um personagem com um problema real. A marca aparece como guia. Virada clara. Fecha na vida depois.'],
  ['id' => 'editorial', 'nome' => 'Editorial · revista', 'resumo' => 'Tom de jornalismo de qualidade: lead, contexto, dado, citação, fecho.', 'dna' => 'Lead informativo. Contexto em duas frases. Um dado verificável. Uma citação. Fecho que amarra sem vender.'],
  ['id' => 'luxo', 'nome' => 'Luxo · silêncio e detalhe', 'resumo' => 'Poucas palavras, ritmo lento, materiais, origem, tempo. Nunca urgência.', 'dna' => 'Frases curtas com espaço entre elas. Nomeia materiais, origem, processo. Nunca desconto, nunca urgência. O tempo como valor.'],
  ['id' => 'performance', 'nome' => 'Performance · direto ao ponto', 'resumo' => 'Gancho em 3 palavras, benefício, prova, CTA claro. Para anúncio e social.', 'dna' => 'Gancho nas primeiras 3 palavras. Um benefício. Uma prova (número, depoimento). CTA único e literal. Sem rodeio.'],
  ['id' => 'imobiliario', 'nome' => 'Imobiliário premium · vida, não metro quadrado', 'resumo' => 'Vende a vida que acontece ali; dado técnico só como apoio; bairro, luz, tempo, família.', 'dna' => 'Começa pela cena de vida (manhã, luz, caminho). Bairro e vizinhança como personagem. Metragem, planta e condições entram depois, como prova. Sem "oportunidade única". Fecha com um convite concreto (visitar, conhecer o decorado).'],
]; }
function cpRefsDrive() {   // referências da casa: docs na pasta PD · REFERÊNCIAS › COPYS (lidos como texto, até 6)
    try { $r = sd_supabase('/rest/v1/app_settings?id=eq.1&select=drive_refs_id'); $raiz = trim((string)($r['body'][0]['drive_refs_id'] ?? '')); if (!$raiz || !function_exists('ponte')) return [];
        $g = ponte('pasta_garantir', ['pai_id' => $raiz, 'nome' => 'COPYS', 'criar' => false]); $pid = $g['id'] ?? null; if (!$pid) return [];
        $l = ponte('listar', ['folderId' => $pid]); $out = [];
        foreach ((array)($l['arquivos'] ?? []) as $f) { if (count($out) >= 6) break; $m = (string)($f['mime'] ?? ''); if (strpos($m, 'document') === false && strpos($m, 'text') === false && strpos($m, 'pdf') === false) continue;
            try { $d = ponte('reuniao_ler', ['doc_id' => $f['id']]); $t = trim((string)($d['texto'] ?? '')); if ($t !== '') $out[] = ['nome' => $f['name'], 'texto' => mb_substr($t, 0, 6000)]; } catch (Exception $e) {} }
        return $out; } catch (Exception $e) { return []; }
}
function cpLeLink($lk) {   // Drive (doc/pdf/md) → texto; site → texto resumido
    $lk = trim((string)$lk); if ($lk === '') return '';
    if (preg_match('~(docs|drive)\.google\.com~', $lk) && preg_match('~/d/([\w\-]+)|id=([\w\-]+)~', $lk, $m)) { $id = $m[1] ?: $m[2];
        try { $r0 = ponte('reuniao_ler', ['doc_id' => $id]); $t = trim((string)($r0['texto'] ?? '')); if ($t === '') { $r1 = ponte('baixar', ['fileId' => $id]); $t = trim((string)base64_decode((string)($r1['b64'] ?? ''))); if (!mb_check_encoding($t, 'UTF-8')) $t = ''; } return mb_substr($t, 0, 20000); } catch (Exception $e) { return '[não consegui ler ' . $lk . ': ' . $e->getMessage() . ']'; } }
    try { [$txt] = paReferencias([$lk], 0); return implode("\n", (array)$txt); } catch (Exception $e) { return '[não consegui ler ' . $lk . ']'; }
}

if ($acao === 'inicio') {
    $cid = (string)($b['client_id'] ?? ''); $q = '/rest/v1/pd_estilos?ativo=eq.true&area=eq.copys&select=id,nome,resumo,dna,client_id,refs&order=nome.asc'; $q .= $cid ? '&or=(client_id.is.null,client_id.eq.' . urlencode($cid) . ')' : '&client_id=is.null';
    $est = sd_supabase($q)['body'] ?? []; $cp = $cid ? (sd_supabase('/rest/v1/pd_copys?client_id=eq.' . urlencode($cid) . '&select=id,nome,briefing,variantes,mudado_em&order=mudado_em.desc&limit=30')['body'] ?? []) : [];
    $refs = cpRefsDrive();
    echo json_encode(['base' => cpEstilosBase(), 'estilos' => $est, 'copys' => $cp, 'refs' => array_map(fn($r) => ['nome' => $r['nome']], $refs), 'quem' => pdQuemFazArea('copys')], JSON_UNESCAPED_UNICODE); exit;
}
if ($acao === 'estilo_salvar') {
    $d = ['area' => 'copys', 'nome' => mb_substr(trim((string)($b['nome'] ?? '')), 0, 80) ?: 'Estilo', 'resumo' => mb_substr((string)($b['resumo'] ?? ''), 0, 400), 'dna' => mb_substr((string)($b['dna'] ?? ''), 0, 6000), 'client_id' => ($b['client_id'] ?? null) ?: null, 'criado_por' => $u['id'], 'refs' => is_array($b['refs'] ?? null) ? $b['refs'] : null];
    $id = (string)($b['id'] ?? ''); $r = $id ? sd_supabase('/rest/v1/pd_estilos?id=eq.' . urlencode($id), 'PATCH', $d, ['Prefer: return=representation']) : sd_supabase('/rest/v1/pd_estilos', 'POST', $d, ['Prefer: return=representation']);
    if (($r['code'] ?? 500) >= 300) sd_fail('Rode o SQL v128 no Supabase.', 500); echo json_encode(['ok' => true, 'estilo' => $r['body'][0] ?? null], JSON_UNESCAPED_UNICODE); exit;
}
if ($acao === 'estilo_apagar') { sd_supabase('/rest/v1/pd_estilos?id=eq.' . urlencode((string)($b['id'] ?? '')), 'PATCH', ['ativo' => false]); echo json_encode(['ok' => true]); exit; }
if ($acao === 'abrir') { $r = sd_supabase('/rest/v1/pd_copys?id=eq.' . urlencode((string)($b['id'] ?? '')) . '&select=*')['body'][0] ?? null; if (!$r) sd_fail('Não encontrada.', 404); echo json_encode(['copy' => $r], JSON_UNESCAPED_UNICODE); exit; }
if ($acao === 'salvar') {
    $id = (string)($b['id'] ?? ''); $d = ['mudado_em' => gmdate('c')]; foreach (['nome', 'dados'] as $k) if (isset($b[$k])) $d[$k] = mb_substr((string)$b[$k], 0, $k === 'nome' ? 120 : 60000);
    foreach (['briefing', 'links', 'estilos', 'variantes'] as $k) if (array_key_exists($k, $b)) $d[$k] = is_array($b[$k]) ? $b[$k] : null;
    if (!$id) { if (empty($b['client_id'])) sd_fail('Toda copy é de um cliente.'); $d['client_id'] = (string)$b['client_id']; $d['criado_por'] = $u['id']; $r = sd_supabase('/rest/v1/pd_copys', 'POST', $d, ['Prefer: return=representation']); } else $r = sd_supabase('/rest/v1/pd_copys?id=eq.' . urlencode($id), 'PATCH', $d, ['Prefer: return=representation']);
    if (($r['code'] ?? 500) >= 300) sd_fail('Rode o SQL v128 no Supabase.', 500); echo json_encode(['ok' => true, 'copy' => $r['body'][0] ?? null], JSON_UNESCAPED_UNICODE); exit;
}
if ($acao === 'apagar') { sd_supabase('/rest/v1/pd_copys?id=eq.' . urlencode((string)($b['id'] ?? '')), 'DELETE'); echo json_encode(['ok' => true]); exit; }
if ($acao === 'gerar') {
    $cid = (string)($b['client_id'] ?? ''); if (!$cid) sd_fail('Escolha o cliente.');
    $c = sd_supabase('/rest/v1/clients?id=eq.' . urlencode($cid) . '&select=name,segment,site')['body'][0] ?? []; $kit = sd_supabase('/rest/v1/brand_kits?client_id=eq.' . urlencode($cid) . '&select=tom,voz,sobre')['body'][0] ?? [];
    $br = (array)($b['briefing'] ?? []); $n = max(1, min(8, (int)($b['variantes'] ?? 3))); $dados = mb_substr((string)($b['dados'] ?? ''), 0, 40000);
    $links = array_slice(array_filter(array_map('trim', (array)($b['links'] ?? []))), 0, 6); $lidos = []; foreach ($links as $lk) { $t = cpLeLink($lk); if ($t !== '') $lidos[] = "[$lk]\n" . mb_substr($t, 0, 12000); }
    $base = []; foreach (cpEstilosBase() as $e) $base[$e['id']] = $e; $sel = (array)($b['estilos'] ?? []); $estTxt = [];
    foreach ($sel as $se) { $sid = (string)($se['id'] ?? ''); $peso = max(1, min(3, (int)($se['peso'] ?? 2))); if (isset($base[$sid])) $estTxt[] = "- {$base[$sid]['nome']} (peso $peso/3): {$base[$sid]['dna']}";
        else { $x = sd_supabase('/rest/v1/pd_estilos?id=eq.' . urlencode($sid) . '&select=nome,dna,resumo')['body'][0] ?? null; if ($x) $estTxt[] = "- {$x['nome']} (peso $peso/3): " . ($x['dna'] ?: $x['resumo']); } }
    $refs = !empty($b['usar_refs']) ? cpRefsDrive() : [];
    $fmt = (string)($br['formato'] ?? 'post'); $campos = ['post' => 'titulo, texto (legenda), cta, hashtags', 'anuncio' => 'gancho, titulo, texto, cta', 'email' => 'assunto, preheader, texto, cta', 'landing' => 'headline, subheadline, blocos (3 a 5, cada um com titulo e texto), cta', 'roteiro' => 'gancho (3 s), cenas (lista de {fala, imagem}), fechamento, cta', 'manifesto' => 'titulo, texto (manifesto em parágrafos curtos)', 'nome' => 'opcoes (lista de {nome, por_que})', 'slogan' => 'opcoes (lista de {frase, por_que})'][$fmt] ?? 'titulo, texto, cta';
    $system = "Você é a redatora-chefe da Pontodot, agência de branding com foco em mercado imobiliário. Escreve em português do Brasil, com precisão e sem clichê. Responde SÓ com JSON válido, compacto, sem comentários, sem aspas duplas dentro das strings (use aspas simples) e sem quebras de linha cruas (use \\n).\nFormato: {\"leitura\":\"2 linhas sobre o que você entendeu do briefing e dos dados\",\"variantes\":[{\"estilo\":\"nome curto do estilo/combinação usada\",\"$campos\",\"por_que\":\"1 linha: por que funciona\"} x$n]}.\nCada variante precisa ser DIFERENTE de verdade (ângulo, estrutura, abertura), não a mesma ideia reescrita. Use só fatos que estão nos dados; se faltar fato, deixe [a confirmar]. Nunca invente números, nomes, preços ou prazos.";
    $txt = "CLIENTE: " . ($c['name'] ?? '') . ($c['segment'] ? ' · ' . $c['segment'] : '') . ($c['site'] ? ' · ' . $c['site'] : '') .
        (!empty($kit['tom']) || !empty($kit['voz']) || !empty($kit['sobre']) ? "\nVOZ DO CLIENTE (preset): " . trim(($kit['voz'] ?? '') . ' ' . ($kit['tom'] ?? '') . ' ' . ($kit['sobre'] ?? '')) : '') .
        "\n\nBRIEFING:\n" . implode("\n", array_map(fn($k) => "- $k: " . (is_array($br[$k]) ? implode(', ', $br[$k]) : $br[$k]), array_keys(array_filter($br, fn($v) => $v !== '' && $v !== null && $v !== [])))) .
        "\n\nQUANTAS VARIANTES: $n · FORMATO: $fmt" .
        ($estTxt ? "\n\nESTILOS A COMPOR (misture na proporção dos pesos; o resultado é uma voz só, coerente):\n" . implode("\n", $estTxt) : "\n\nESTILO: escolha o que melhor serve ao objetivo e diga qual usou.") .
        ($dados !== '' ? "\n\nDADOS ESTRATÉGICOS (texto enviado):\n$dados" : '') . ($lidos ? "\n\nDADOS ESTRATÉGICOS (lidos dos links):\n" . implode("\n\n", $lidos) : '') .
        ($refs ? "\n\nREFERÊNCIAS DA CASA (como escrevem os melhores; absorva a lógica, nunca copie frases):\n" . implode("\n\n", array_map(fn($r) => "[{$r['nome']}]\n{$r['texto']}", $refs)) : '');
    [$resp, $custo] = paClaude($system, [['type' => 'text', 'text' => $txt]], 12000);
    $j = paJson($resp); if (!$j || empty($j['variantes'])) { $j2 = paJson(paJsonConserta($resp)); if ($j2 && !empty($j2['variantes'])) $j = $j2; }
    if (!$j || empty($j['variantes'])) sd_fail('A IA não devolveu as variantes em JSON. Tente de novo (ou com menos variantes).', 502);
    $quem = pdQuemFazArea('copys'); try { sd_supabase('/rest/v1/pd_ia_trabalho', 'POST', ['ia_user_id' => $quem['id'], 'personagem' => $quem['personagem'], 'client_id' => $cid, 'familia' => 'copys', 'rodada' => 1, 'resultado' => 'gerou', 'custo_usd' => $custo]); } catch (Throwable $e) {}
    echo json_encode(['ok' => true, 'leitura' => $j['leitura'] ?? '', 'variantes' => $j['variantes'], 'custo_usd' => round($custo, 4), 'quem' => $quem], JSON_UNESCAPED_UNICODE); exit;
}
sd_fail('Ação desconhecida.');
