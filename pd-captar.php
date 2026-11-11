<?php
// PD · CAPTAR — quatro portas legais para chegar ao público do cliente. Equipe/admin.
//   publico_montar | publico_salvar | publico_listar | publico_apagar   = público por região (ponto no mapa + raio) e interesse, para a Meta
//   gatilho_listar | gatilho_salvar | gatilho_apagar | gatilho_log       = comentário com palavra-chave vira DM (API oficial da Meta)
//   isca_listar | isca_salvar | isca_apagar                              = página de isca com cadastro e consentimento (isca.php é a página pública)
//   fornecedor_testar | fornecedor_buscar                                = contatos B2B de fornecedor licenciado (Apollo), caem no funil de pessoas
require_once __DIR__ . '/sd-base.php';
require_once __DIR__ . '/sd-auth.php';
require_once __DIR__ . '/meta-ads-lib.php';
require_once __DIR__ . '/pd-pessoas-lib.php';
header('Content-Type: application/json; charset=utf-8');
@set_time_limit(120);
$u = sd_current_user();
if (!in_array($u['role'] ?? '', ['admin', 'team'], true)) sd_fail('Só a equipe.', 403);
$b = sd_body(); $acao = (string)($b['acao'] ?? '');
$out = fn($d) => print(json_encode(['ok' => true] + $d, JSON_UNESCAPED_UNICODE));
$cid = vdUuid($b['client_id'] ?? '');
function cpL($r) { return (($r['code'] ?? 500) < 300 && is_array($r['body'] ?? null)) ? $r['body'] : []; }

// ---------------------------------------------------------------- 1 · público por região e interesse
function capGeocodifica($busca) {   // OpenStreetMap/Nominatim: 1 consulta por lugar, com identificação (política de uso deles)
    $ch = curl_init('https://nominatim.openstreetmap.org/search?format=json&limit=1&countrycodes=br&q=' . urlencode($busca));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => ['User-Agent: PontodotPD/1.0 (+https://pontodot.com.br)', 'Accept-Language: pt-BR']]);
    $raw = curl_exec($ch); curl_close($ch); $j = json_decode((string)$raw, true);
    if (!is_array($j) || empty($j[0]['lat'])) return null;
    return ['lat' => round((float)$j[0]['lat'], 5), 'lng' => round((float)$j[0]['lon'], 5), 'nome_achado' => mb_substr((string)($j[0]['display_name'] ?? ''), 0, 120)];
}
function capTargeting($pub) {
    $geo = [];
    $cl = [];
    foreach ((array)($pub['pontos'] ?? []) as $p) if (isset($p['lat'], $p['lng'])) $cl[] = ['latitude' => (float)$p['lat'], 'longitude' => (float)$p['lng'], 'radius' => max(1, min(80, (int)($p['raio_km'] ?? 10))), 'distance_unit' => 'kilometer'];
    if ($cl) $geo['custom_locations'] = $cl; else $geo['countries'] = ['BR'];
    $t = ['geo_locations' => $geo, 'age_min' => max(18, min(65, (int)($pub['idade_min'] ?? 30))), 'age_max' => max(18, min(65, (int)($pub['idade_max'] ?? 65)))];
    $ints = array_values(array_filter((array)($pub['interesses'] ?? []), fn($x) => is_array($x) && !empty($x['id'])));
    if ($ints) $t['flexible_spec'] = [['interests' => array_map(fn($x) => ['id' => (string)$x['id'], 'name' => (string)($x['nome'] ?? '')], array_slice($ints, 0, 25))]];
    return $t;
}
if ($acao === 'publico_montar') {
    $desc = trim(mb_substr((string)($b['descricao'] ?? ''), 0, 800)); if ($desc === '') sd_fail('Descreva o público (onde ele mora e o que ele curte ou compra).');
    $conta = $cid ? (cpL(sd_supabase('/rest/v1/meta_ad_accounts?client_id=eq.' . urlencode($cid) . '&select=id,conectada_por&limit=1'))[0] ?? null) : null;
    if (!$conta) sd_fail('Este cliente não tem conta de anúncio da Meta ligada. Conecte em Redes sociais.', 409);
    $tok = pd_token_para($conta['conectada_por'] ?? null); if (!$tok) sd_fail('Ninguém com acesso válido à Meta. Reconecte a Meta em Redes sociais.', 409);
    $sys = "Você monta públicos de anúncio da Meta (Facebook/Instagram) para o mercado imobiliário brasileiro. Recebe a descrição de quem se quer alcançar e devolve SOMENTE JSON: "
         . "{\"nome\":\"nome curto do público\",\"lugares\":[{\"busca\":\"nome do lugar com cidade e UF, para achar no mapa\",\"raio_km\":10}],\"interesses\":[\"termos de interesse que existem na Meta, em português\"],\"idade_min\":30,\"idade_max\":65,\"observacao\":\"1 frase do que foi decidido\"}. "
         . "Regras: no máximo 4 lugares e 8 interesses; raio de 1 a 80 km (represa ou bairro: 5 a 20 km; cidade: 15 a 30 km); só interesses de comportamento e consumo (ex.: investimento imobiliário, náutica, pesca esportiva, imóveis de lazer, mercado financeiro). "
         . "NUNCA use ou infira dado sensível (saúde, religião, política, orientação sexual, etnia).";
    [$txt, , $err] = pdClaude($sys, "DESCRIÇÃO DO PÚBLICO:\n" . $desc, 1200); if (!$txt) sd_fail('A IA não respondeu (' . $err . ').', 502);
    $j = pdJson($txt); if (!$j || empty($j['lugares'])) sd_fail('A IA não devolveu lugares; descreva a região com mais clareza.', 422);
    $pontos = []; $semAchar = [];
    foreach (array_slice((array)$j['lugares'], 0, 4) as $l) { $g = capGeocodifica((string)($l['busca'] ?? '')); usleep(1100000);
        if ($g) $pontos[] = ['nome' => mb_substr((string)$l['busca'], 0, 80), 'lat' => $g['lat'], 'lng' => $g['lng'], 'raio_km' => max(1, min(80, (int)($l['raio_km'] ?? 10))), 'achado' => $g['nome_achado']]; else $semAchar[] = (string)($l['busca'] ?? ''); }
    if (!$pontos) sd_fail('Não achei nenhum desses lugares no mapa: ' . implode('; ', $semAchar), 422);
    $ints = []; $semInt = [];
    foreach (array_slice((array)($j['interesses'] ?? []), 0, 8) as $termo) {
        $r = pd_meta('GET', '/search', ['type' => 'adinterest', 'q' => mb_substr((string)$termo, 0, 60), 'locale' => 'pt_BR', 'limit' => 3], $tok); $it = $r['body']['data'][0] ?? null;
        if (is_array($it) && !empty($it['id'])) $ints[] = ['id' => $it['id'], 'nome' => $it['name'], 'alcance' => $it['audience_size_upper_bound'] ?? null]; else $semInt[] = (string)$termo; }
    $pub = ['pontos' => $pontos, 'interesses' => $ints, 'idade_min' => (int)($j['idade_min'] ?? 30), 'idade_max' => (int)($j['idade_max'] ?? 65)];
    $r = pd_meta('GET', '/' . $conta['id'] . '/delivery_estimate', ['targeting_spec' => json_encode(capTargeting($pub)), 'optimization_goal' => 'REACH'], $tok); $d = $r['body']['data'][0] ?? null;
    $est = $d ? ['de' => $d['estimate_mau_lower_bound'] ?? null, 'ate' => $d['estimate_mau_upper_bound'] ?? null] : null;
    $out(['nome' => (string)($j['nome'] ?? 'Público'), 'publico' => $pub, 'estimativa' => $est, 'aviso_estimativa' => $d ? null : pd_meta_erro($r), 'sem_mapa' => $semAchar, 'sem_interesse' => $semInt, 'observacao' => (string)($j['observacao'] ?? '')]); exit;
}
if ($acao === 'publico_salvar') {
    if (!$cid) sd_fail('Escolha o cliente.');
    $r = sd_supabase('/rest/v1/pd_publicos', 'POST', ['client_id' => $cid, 'nome' => mb_substr(trim((string)($b['nome'] ?? 'Público')), 0, 120), 'descricao' => mb_substr((string)($b['descricao'] ?? ''), 0, 800), 'publico' => (array)($b['publico'] ?? []), 'estimativa' => $b['estimativa'] ?? null, 'criado_por' => $u['id'] ?? null], ['Prefer: return=representation']);
    if (($r['code'] ?? 500) >= 300) sd_fail('Rode o SQL v182 no Supabase.', 500); $out(['publico' => $r['body'][0] ?? null]); exit;
}
if ($acao === 'publico_listar') { $out(['itens' => cpL(sd_supabase('/rest/v1/pd_publicos?select=*' . ($cid ? '&client_id=eq.' . urlencode($cid) : '') . '&order=created_at.desc&limit=60'))]); exit; }
if ($acao === 'publico_apagar') { sd_supabase('/rest/v1/pd_publicos?id=eq.' . urlencode((string)($b['id'] ?? '')), 'DELETE'); $out([]); exit; }

// ---------------------------------------------------------------- 2 · gatilhos de comentário
if ($acao === 'gatilho_listar') {
    if (!$cid) sd_fail('Escolha o cliente.');
    $out(['itens' => cpL(sd_supabase('/rest/v1/pd_gatilhos?client_id=eq.' . urlencode($cid) . '&select=*&order=created_at.desc')), 'dm_liberado' => defined('SD_META_DM_LIBERADO') && SD_META_DM_LIBERADO]); exit;
}
if ($acao === 'gatilho_salvar') {
    if (!$cid) sd_fail('Escolha o cliente.');
    $pal = vdNorm((string)($b['palavra'] ?? '')); $dm = trim(mb_substr((string)($b['resposta_dm'] ?? ''), 0, 900));
    if ($pal === '' || mb_strlen($pal) < 3 || $dm === '') sd_fail('Informe a palavra (3+ letras) e a mensagem do direct.');
    $row = ['client_id' => $cid, 'palavra' => $pal, 'post_id' => trim((string)($b['post_id'] ?? '')) ?: null, 'resposta_dm' => $dm, 'resposta_publica' => trim(mb_substr((string)($b['resposta_publica'] ?? ''), 0, 300)) ?: null, 'ativo' => !isset($b['ativo']) || !empty($b['ativo'])];
    $id = vdUuid($b['id'] ?? ''); $r = $id ? sd_supabase('/rest/v1/pd_gatilhos?id=eq.' . $id, 'PATCH', $row, ['Prefer: return=representation']) : sd_supabase('/rest/v1/pd_gatilhos', 'POST', $row, ['Prefer: return=representation']);
    if (($r['code'] ?? 500) >= 300) sd_fail('Rode o SQL v182 no Supabase.', 500); $out(['item' => $r['body'][0] ?? null]); exit;
}
if ($acao === 'gatilho_apagar') { sd_supabase('/rest/v1/pd_gatilhos?id=eq.' . urlencode((string)($b['id'] ?? '')), 'DELETE'); $out([]); exit; }
if ($acao === 'gatilho_log') { $out(['itens' => cpL(sd_supabase('/rest/v1/pd_gatilho_log?' . ($cid ? 'client_id=eq.' . urlencode($cid) . '&' : '') . 'select=username,texto,enviado,erro,created_at&order=created_at.desc&limit=40'))]); exit; }

// ---------------------------------------------------------------- 3 · iscas
function capSlug($s) { $s = vdNorm($s); $s = trim(preg_replace('/[^a-z0-9]+/', '-', $s), '-'); return substr($s ?: 'isca', 0, 50); }
if ($acao === 'isca_listar') {
    if (!$cid) sd_fail('Escolha o cliente.');
    $out(['itens' => cpL(sd_supabase('/rest/v1/pd_iscas?client_id=eq.' . urlencode($cid) . '&select=*&order=created_at.desc')), 'base' => 'https://' . ($_SERVER['HTTP_HOST'] ?? 'pontodot.com.br') . '/isca.php?s=']); exit;
}
if ($acao === 'isca_salvar') {
    if (!$cid) sd_fail('Escolha o cliente.');
    $tit = trim(mb_substr((string)($b['titulo'] ?? ''), 0, 140)); if ($tit === '') sd_fail('Dê um título para a isca.');
    $row = ['client_id' => $cid, 'titulo' => $tit, 'subtitulo' => trim(mb_substr((string)($b['subtitulo'] ?? ''), 0, 240)) ?: null, 'promessa' => trim(mb_substr((string)($b['promessa'] ?? ''), 0, 400)) ?: null,
        'entrega_url' => preg_match('~^https?://~i', (string)($b['entrega_url'] ?? '')) ? trim((string)$b['entrega_url']) : null, 'pede_telefone' => !isset($b['pede_telefone']) || !empty($b['pede_telefone']),
        'ativo' => !isset($b['ativo']) || !empty($b['ativo'])];
    if (!empty($b['consentimento'])) $row['consentimento'] = trim(mb_substr((string)$b['consentimento'], 0, 500));
    $id = vdUuid($b['id'] ?? '');
    if ($id) $r = sd_supabase('/rest/v1/pd_iscas?id=eq.' . $id, 'PATCH', $row, ['Prefer: return=representation']);
    else { $slug = capSlug((string)($b['slug'] ?? $tit)); for ($i = 0; $i < 5; $i++) { $r = sd_supabase('/rest/v1/pd_iscas', 'POST', $row + ['slug' => $i ? $slug . '-' . substr(bin2hex(random_bytes(2)), 0, 3) : $slug], ['Prefer: return=representation']); if (($r['code'] ?? 500) < 300) break; } }
    if (($r['code'] ?? 500) >= 300) sd_fail('Rode o SQL v182 no Supabase.', 500); $out(['item' => $r['body'][0] ?? null]); exit;
}
if ($acao === 'isca_apagar') { sd_supabase('/rest/v1/pd_iscas?id=eq.' . urlencode((string)($b['id'] ?? '')), 'DELETE'); $out([]); exit; }

// ---------------------------------------------------------------- 4 · fornecedor licenciado de contatos B2B (Apollo)
function capApolloChave() {
    $r = sd_supabase('/rest/v1/pd_credenciais?client_id=is.null&servico=eq.apollo&select=segredo'); $s = trim((string)($r['body'][0]['segredo'] ?? ''));
    if ($s === '') throw new Exception('Falta a chave do fornecedor de contatos (Apollo) no cofre da casa.'); return $s;
}
function capApollo($path, $body) {
    $ch = curl_init('https://api.apollo.io/api/v1' . $path);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Cache-Control: no-cache', 'X-Api-Key: ' . capApolloChave()], CURLOPT_POSTFIELDS => json_encode($body)]);
    $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch); return [$code, json_decode((string)$raw, true)];
}
if ($acao === 'fornecedor_testar') {
    try { [$code, $j] = capApollo('/mixed_people/search', ['per_page' => 1, 'person_titles' => ['diretor']]); } catch (Exception $e) { sd_fail($e->getMessage(), 409); }
    if ($code === 401 || $code === 403) sd_fail('O fornecedor recusou a chave (HTTP ' . $code . '). Confira a chave e o plano.', 422);
    if ($code >= 300) sd_fail('O fornecedor respondeu HTTP ' . $code . '.', 502); $out(['ligado' => true]); exit;
}
if ($acao === 'fornecedor_buscar') {
    $cons = trim(mb_substr((string)($b['consulta'] ?? ''), 0, 600)); $n = max(3, min(25, (int)($b['quantos'] ?? 10))); if ($cons === '') sd_fail('Diga quem você procura (cargo, setor, cidade).');
    $sys = "Converta o pedido em filtros de busca de contatos B2B. Responda SOMENTE JSON: {\"cargos\":[\"títulos em português e inglês\"],\"locais\":[\"Cidade, Estado, Brasil\"],\"palavras\":\"palavras-chave do setor\"}. Sem dado sensível.";
    [$txt, , $err] = pdClaude($sys, $cons, 600); $f = $txt ? pdJson($txt) : null; if (!$f) sd_fail('A IA não entendeu o pedido (' . $err . ').', 502);
    try { [$code, $j] = capApollo('/mixed_people/search', ['per_page' => $n, 'page' => 1, 'person_titles' => array_slice((array)($f['cargos'] ?? []), 0, 8), 'person_locations' => array_slice((array)($f['locais'] ?? []), 0, 4), 'q_keywords' => (string)($f['palavras'] ?? '')]); }
    catch (Exception $e) { sd_fail($e->getMessage(), 409); }
    if ($code >= 300) sd_fail('O fornecedor respondeu HTTP ' . $code . ' (' . mb_substr((string)($j['error'] ?? ''), 0, 120) . ').', 502);
    $lista = []; foreach ((array)($j['people'] ?? []) as $p) { if (!is_array($p) || empty($p['name'])) continue;
        $lista[] = ['nome' => $p['name'], 'cargo' => $p['title'] ?? '', 'empresa' => $p['organization']['name'] ?? '', 'cidade' => $p['city'] ?? '', 'uf' => $p['state'] ?? '', 'linkedin' => $p['linkedin_url'] ?? '',
            'site' => $p['organization']['website_url'] ?? '', 'email' => (($p['email_status'] ?? '') === 'verified' && !empty($p['email']) && stripos((string)$p['email'], 'not_unlocked') === false) ? $p['email'] : '', 'fonte_url' => 'apollo.io', 'nota' => 60, 'motivo' => 'Contato licenciado do fornecedor: ' . ($p['title'] ?? '') . ' em ' . ($p['organization']['name'] ?? '')]; }
    if (!$lista) sd_fail('O fornecedor não devolveu pessoas para esse pedido.', 422);
    $cart = ($cid ?: vdCasaId());
    $r = vdPessoasGrava($lista, $cons, $cart, $u['id'] ?? null, 'pessoas-fornecedor', 'Fornecedor · ' . date('d/m H:i') . ' · ' . mb_substr($cons, 0, 50));
    try { sd_supabase('/rest/v1/pd_fornecedor_log', 'POST', ['fornecedor' => 'apollo', 'consulta' => mb_substr($cons, 0, 300), 'achados' => count($lista)], ['Prefer: return=minimal']); } catch (Throwable $e) {}
    if (!empty($r['erro'])) sd_fail($r['erro'], 500); $out(['r' => $r]); exit;
}
sd_fail('Ação desconhecida.');
