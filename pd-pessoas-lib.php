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
    if ($ln !== '') return $cartK . '|p|in:' . $ln;
    return $cartK . '|p|' . vdNorm($x['nome'] ?? '') . '|' . vdNorm($x['empresa'] ?? '');
}

/** Grava pessoas como prospects. $lista: [{nome,cargo,empresa,cidade,uf,instagram,linkedin,site,email,fonte_url,motivo,nota,perfil}] */
function vdPessoasGrava(array $lista, $criterio, $cart, $uid, $origem, $loteNome) {
    $lr = vdLinhas(vdSup('/rest/v1/pd_lotes', 'POST', ['nome' => $loteNome, 'motivo' => mb_substr($criterio, 0, 500), 'carteira_id' => $cart, 'criado_por' => $uid]));
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
            'sinais' => ['PESSOA' => true, 'cargo' => (string)($x['cargo'] ?? ''), 'empresa' => (string)($x['empresa'] ?? ''), 'criterio' => mb_substr($criterio, 0, 200), 'fonte_url' => (string)($x['fonte_url'] ?? ''), 'linkedin' => (string)($x['linkedin'] ?? ''), 'perfil' => mb_substr((string)($x['perfil'] ?? ''), 0, 300)],
            'enriquecido_em' => gmdate('c'), '_email' => $email];
    }
    if (!$por) return ['novos' => 0, 'duplicados' => 0, 'ignorados' => $ign, 'lote' => $lote];
    $chaves = array_keys($por); $jaTem = [];
    foreach (array_chunk($chaves, 100) as $ck) { $q = sd_supabase('/rest/v1/pd_prospects?chave=in.(' . implode(',', array_map(fn($c) => '"' . str_replace('"', '', $c) . '"', $ck)) . ')&select=chave'); foreach ((array)($q['body'] ?? []) as $r) if (is_array($r)) $jaTem[$r['chave']] = 1; }
    $ins = []; foreach ($por as $k => $r) if (!isset($jaTem[$k])) { $e = $r['_email']; unset($r['_email']); $ins[] = $r; }
    $dup = count($por) - count($ins); if (!$ins) return ['novos' => 0, 'duplicados' => $dup, 'ignorados' => $ign, 'lote' => $lote];
    $r = sd_supabase('/rest/v1/pd_prospects', 'POST', $ins, ['Prefer: return=representation']);
    if (($r['code'] ?? 500) >= 300) { $msg = (string)($r['body']['message'] ?? $r['raw'] ?? '');
        if (preg_match("/Could not find the '([a-z_]+)' column/", $msg, $mm)) { $ins = array_map(function ($x) use ($mm) { unset($x[$mm[1]]); return $x; }, $ins); $r = sd_supabase('/rest/v1/pd_prospects', 'POST', $ins, ['Prefer: return=representation']); }
        if (($r['code'] ?? 500) >= 300) return ['erro' => 'banco recusou: ' . mb_substr((string)($r['raw'] ?? ''), 0, 200)]; }
    $cts = []; foreach ((array)$r['body'] as $n) { $e = $por[$n['chave']]['_email'] ?? ''; if ($e !== '') $cts[] = ['prospect_id' => $n['id'], 'nome' => $n['nome'], 'cargo' => ($por[$n['chave']]['sinais']['cargo'] ?? '') ?: null, 'email' => $e, 'email_status' => 'publico', 'principal' => true, 'dono' => true]; }
    if ($cts) sd_supabase('/rest/v1/pd_prospect_contatos', 'POST', $cts, ['Prefer: return=minimal']);
    return ['novos' => count((array)$r['body']), 'duplicados' => $dup, 'ignorados' => $ign, 'lote' => $lote];
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
         . "Responda SOMENTE um JSON array de até $quantos itens: [{\"nome\":\"\",\"cargo\":\"\",\"empresa\":\"\",\"cidade\":\"\",\"uf\":\"\",\"instagram\":\"\",\"linkedin\":\"\",\"site\":\"\",\"email\":\"\",\"fonte_url\":\"\",\"nota\":0,\"motivo\":\"1 frase: por que esta pessoa encaixa no critério\"}]. nota = encaixe de 1 a 100. Sem texto fora do JSON.";
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
    $lista = array_values(array_filter($lista, fn($x) => is_array($x) && !empty($x['fonte_url'])));
    if (!$lista) return ['erro' => 'as pessoas achadas vieram sem fonte; nada foi gravado'];
    $r = vdPessoasGrava($lista, $consulta, $cart, $uid, 'pessoas-web', 'Descoberta · pessoas ' . date('d/m H:i') . ' · ' . mb_substr($consulta, 0, 50));
    if (!empty($r['erro'])) return $r; if (empty($r['novos'])) return ['erro' => 'tudo que achei já estava na carteira (' . count($lista) . ' achadas, ' . (int)$r['duplicados'] . ' repetidas)'];
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
