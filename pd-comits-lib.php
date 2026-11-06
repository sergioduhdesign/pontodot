<?php
// COMITS — grupos de conversa com humanos, IAs e clientes. Fechar nunca apaga (só admin humano fecha; fica o histórico).
require_once __DIR__ . '/pd-lib.php';
require_once __DIR__ . '/pd-aula-lib.php';

function cmtQ($path, $m = 'GET', $b = null) { $r = sd_supabase($path, $m, $b, $m === 'POST' ? ['Prefer: return=representation'] : []); return $r; }
function cmtEhAdmin($u) { return ($u['role'] ?? '') === 'admin'; }
function cmtLog($id, $acao, $quem, $det = null) { try { sd_supabase('/rest/v1/pd_comit_log', 'POST', ['comit_id' => $id, 'acao' => $acao, 'quem' => $quem, 'detalhe' => $det]); } catch (\Throwable $e) {} }

/** quem pode entrar numa conversa: gente da casa, clientes e as IAs (personagens) */
function cmtPessoas() {
    $pf = (array)(sd_supabase('/rest/v1/profiles?active=eq.true&select=id,name,role,client_id,avatar_url,clients(name)&order=name.asc&limit=400')['body'] ?? []);
    $h = []; $nomeDe = []; $equipe = [];
    foreach ($pf as $p) { if (empty($p['id'])) continue; $ehCli = !empty($p['client_id']); if (!$ehCli) { $nomeDe[$p['id']] = $p['name']; $equipe[$p['id']] = true; }
        $h[] = ['tipo' => $ehCli ? 'cliente' : 'humano', 'ref' => $p['id'], 'nome' => $p['name'], 'avatar' => $p['avatar_url'] ?? '', 'sub' => $ehCli ? ('cliente · ' . ($p['clients']['name'] ?? '')) : ($p['role'] === 'admin' ? 'admin' : 'equipe')]; }
    $cat = pdPersonagens(); $ia = []; $usadas = [];
    // 1) a IA de cada pessoa da equipe (quem já fez o teste e tem personagem)
    $jr = sd_supabase('/rest/v1/pd_jeito?select=user_id,personagem,apelido&limit=400')['body'] ?? [];
    foreach ((is_array($jr) && isset($jr[0]['user_id']) ? $jr : []) as $j) {
        $k = strtolower(trim((string)($j['personagem'] ?? ''))); if ($k === '' || !isset($cat[$k]) || empty($equipe[$j['user_id']]) || isset($usadas[$k])) continue;
        $usadas[$k] = true; $ia[] = ['tipo' => 'ia', 'ref' => $k, 'nome' => $cat[$k][0], 'sub' => 'IA de ' . $nomeDe[$j['user_id']] . ' · ' . $cat[$k][2]]; }
    // 2) as IAs das ferramentas (Dig, campanhas, social)
    foreach (['tumi' => 'Dig · captação e pesquisa', 'sumi' => 'campanhas', 'muru' => 'social'] as $k => $fn) { if (!isset($cat[$k]) || isset($usadas[$k])) continue; $usadas[$k] = true; $ia[] = ['tipo' => 'ia', 'ref' => $k, 'nome' => $cat[$k][0], 'sub' => 'IA da ferramenta · ' . $fn]; }
    return ['humanos' => $h, 'ias' => $ia];
}
function cmtComit($id) { $r = sd_supabase('/rest/v1/pd_comits?id=eq.' . urlencode((string)$id) . '&select=*&limit=1')['body'][0] ?? null; return $r; }
function cmtMembros($id) { return (array)(sd_supabase('/rest/v1/pd_comit_membros?comit_id=eq.' . urlencode((string)$id) . '&select=tipo,ref,nome&order=entrou_em.asc')['body'] ?? []); }
/** pode ver? admin vê tudo; os demais, só se é membro ou o comit é público (cliente: só se membro) */
function cmtPode($u, $c, $mem) {
    if (cmtEhAdmin($u)) return true;
    foreach ($mem as $m) if ($m['ref'] === $u['id'] && $m['tipo'] !== 'ia') return true;
    return !empty($c['publico']) && empty($u['client_id']);
}
function cmtCriar($titulo, $assunto, $membros, $criadoPor, $criadoPorIa = null, $publico = false, $nomeQuem = 'Alguém') {
    $titulo = trim(mb_substr((string)$titulo, 0, 120)); if ($titulo === '') return [null, 'Dê um nome ao comit.'];
    $r = sd_supabase('/rest/v1/pd_comits', 'POST', ['titulo' => $titulo, 'assunto' => $assunto ?: null, 'criado_por' => $criadoPor, 'criado_por_ia' => $criadoPorIa, 'publico' => (bool)$publico], ['Prefer: return=representation']);
    $c = $r['body'][0] ?? null; if (!$c) return [null, 'Rode o SQL v170 no Supabase.'];
    $vistos = []; $linhas = [];
    if ($criadoPor) $membros[] = ['tipo' => 'humano', 'ref' => $criadoPor];
    $pers = pdPersonagens();
    foreach ($membros as $m) {
        $tp = in_array($m['tipo'] ?? '', ['humano', 'ia', 'cliente'], true) ? $m['tipo'] : 'humano'; $ref = (string)($m['ref'] ?? ''); if ($ref === '') continue;
        if ($tp === 'ia') { $ref = strtolower($ref); if (!isset($pers[$ref])) continue; $nome = $pers[$ref][0]; }
        else { $pr = sd_supabase('/rest/v1/profiles?id=eq.' . urlencode($ref) . '&select=name,client_id&limit=1')['body'][0] ?? null; if (!$pr) continue; $nome = $pr['name']; $tp = $pr['client_id'] ? 'cliente' : 'humano'; }
        if (isset($vistos[$tp . $ref])) continue; $vistos[$tp . $ref] = 1;
        $linhas[] = ['comit_id' => $c['id'], 'tipo' => $tp, 'ref' => $ref, 'nome' => $nome];
    }
    if ($linhas) sd_supabase('/rest/v1/pd_comit_membros', 'POST', $linhas);
    cmtLog($c['id'], 'criou', $nomeQuem, count($linhas) . ' participante(s)');
    return [$c, null];
}
function cmtAddMembro($comitId, $tipo, $ref, $quem) {
    $pers = pdPersonagens(); $ref = (string)$ref;
    if ($tipo === 'ia') { $ref = strtolower($ref); if (!isset($pers[$ref])) return false; $nome = $pers[$ref][0]; }
    else { $pr = sd_supabase('/rest/v1/profiles?id=eq.' . urlencode($ref) . '&select=name,client_id&limit=1')['body'][0] ?? null; if (!$pr) return false; $nome = $pr['name']; $tipo = $pr['client_id'] ? 'cliente' : 'humano'; }
    sd_supabase('/rest/v1/pd_comit_membros?on_conflict=comit_id,tipo,ref', 'POST', ['comit_id' => $comitId, 'tipo' => $tipo, 'ref' => $ref, 'nome' => $nome], ['Prefer: resolution=ignore-duplicates']);
    cmtLog($comitId, 'entrou', $quem, $nome); return true;
}
function cmtMsg($comitId, $tipo, $ref, $nome, $texto, $importante = false) {
    $r = sd_supabase('/rest/v1/pd_comit_msgs', 'POST', ['comit_id' => $comitId, 'autor_tipo' => $tipo, 'autor_ref' => $ref, 'autor_nome' => $nome, 'texto' => mb_substr((string)$texto, 0, 6000), 'importante' => (bool)$importante], ['Prefer: return=representation']);
    sd_supabase('/rest/v1/pd_comits?id=eq.' . urlencode((string)$comitId), 'PATCH', ['ultima_em' => gmdate('c')]);
    return $r['body'][0] ?? null;
}
function cmtMencionou($texto, $nome) { return $nome !== '' && preg_match('/(^|[^\p{L}])@?' . preg_quote(mb_strtolower($nome), '/') . '([^\p{L}]|$)/iu', mb_strtolower($texto)); }

/** avisa quem é humano no grupo: msg importante, ou citou o nome da pessoa. O zap entra só nisso (e respeita o "receber alertas"). */
function cmtAvisar($c, $mem, $autorRef, $autorNome, $texto, $importante) {
    foreach ($mem as $m) {
        if ($m['tipo'] === 'ia' || $m['ref'] === $autorRef) continue;
        $primeiro = trim(explode(' ', (string)$m['nome'])[0]);
        if (!$importante && !cmtMencionou($texto, $primeiro)) continue;
        $tit = 'Msg importante no grupo ' . $c['titulo'];
        try { pdNotificarUsuario($m['ref'], 'comit', $tit, $autorNome . ': ' . mb_substr($texto, 0, 160) . "\n\nTem msg nova importante no grupo \"" . $c['titulo'] . '". Quer saber? Responda: abre o grupo ' . $c['titulo'], 'comit:' . $c['id']); } catch (\Throwable $e) {}
    }
}
/** as IAs do grupo respondem quando chamadas pelo nome (ou quando é a única IA e uma pessoa falou). Uma IA pode chamar outra (até 2 voltas). */
function cmtIAsResponderem($c, $mem, $texto, $profundidade = 0, $autorTipo = 'humano', $abertura = false) {
    if ($profundidade > 2) return;
    $ias = array_values(array_filter($mem, fn($m) => $m['tipo'] === 'ia')); if (!$ias) return;
    $pers = pdPersonagens();
    $quem = [];
    foreach ($ias as $i) if (cmtMencionou($texto, (string)$i['nome'])) $quem[] = $i;
    if (!$quem && $autorTipo !== 'ia') {
        if (count($ias) === 1) $quem = $ias;
        elseif ($profundidade === 0) {   // PROATIVAS: ninguém foi chamado pelo nome — a IA mais útil entra por conta própria
            $desc = implode("\n", array_map(fn($i) => '- ' . $i['nome'] . ': ' . (($pers[$i['ref']][2] ?? '') . ' (' . ($pers[$i['ref']][3] ?? '') . ')'), $ias));
            [$dec] = pdClaude('Você coordena um grupo de trabalho (comit) de uma agência de branding imobiliário. Escolha quais IAs do grupo devem se manifestar AGORA (de 1 a 2), as mais úteis para esta mensagem: quem pode trazer dado, ideia, risco, próximo passo ou pergunta que destrava. Prefira contribuir a ficar calada. Responda SOMENTE JSON: {"falam":["Nome"]}.', "Grupo: " . $c['titulo'] . ($c['assunto'] ? ' — ' . $c['assunto'] : '') . "\nIAs:\n" . $desc . "\n\nMensagem: " . mb_substr($texto, 0, 1500), 120);
            $nomes = []; if ($dec && preg_match('/\{.*\}/s', $dec, $mj)) { $jj = json_decode($mj[0], true); $nomes = array_map('mb_strtolower', (array)($jj['falam'] ?? [])); }
            foreach ($ias as $i) if (in_array(mb_strtolower((string)$i['nome']), $nomes, true)) $quem[] = $i;
            if (!$quem) $quem = [$ias[0]];
            $quem = array_slice($quem, 0, 2);
        }
    }
    if (!$quem) return;
    $hist = (array)(sd_supabase('/rest/v1/pd_comit_msgs?comit_id=eq.' . urlencode($c['id']) . '&select=autor_nome,autor_tipo,texto&order=id.desc&limit=18')['body'] ?? []);
    $transcr = ''; foreach (array_reverse($hist) as $h) $transcr .= $h['autor_nome'] . ($h['autor_tipo'] === 'ia' ? ' (IA)' : '') . ': ' . $h['texto'] . "\n";
    $lista = implode('; ', array_map(fn($m) => $m['nome'] . ' (' . ($m['tipo'] === 'ia' ? 'IA' : ($m['tipo'] === 'cliente' ? 'cliente' : 'pessoa da equipe')) . ')', $mem));
    foreach ($quem as $q) {
        $p = $pers[$q['ref']] ?? null; if (!$p) continue;
        $sys = 'Você é ' . $p[0] . ', ' . $p[2] . ' (' . $p[3] . '), uma IA da casa pontodot — agência de branding do mercado imobiliário. ' . $p[4] . ' Está no grupo (comit) "' . $c['titulo'] . '"' . ($c['assunto'] ? ' sobre: ' . $c['assunto'] : '') . '. Participantes: ' . $lista . '. Sabe quem é quem, e que as outras IAs da casa são suas colegas: pode chamá-las pelo nome quando o assunto for delas. Responda em português do Brasil, curto e direto (até 5 linhas), sem se apresentar de novo. SEJA PROATIVA: não espere ser chamada. Traga o que você sabe, aponte riscos e lacunas, proponha o próximo passo e quem deve fazê-lo, faça a pergunta que destrava; se o assunto é de outra IA, passe a bola chamando-a pelo nome. Só afirme o que a conversa sustenta.';
        [$resp, , $err] = pdClaude($sys, "Conversa até agora:\n" . $transcr . ($abertura ? "\nO comit acabou de ser aberto: apresente em 2 linhas, como " . $p[0] . ', o que você traz para este assunto e proponha o primeiro passo.' : "\nResponda como " . $p[0] . ' à última mensagem.'), 700);
        if (!$resp) continue;
        cmtMsg($c['id'], 'ia', $q['ref'], $p[0], $resp);
        cmtIAsResponderem($c, $mem, $resp, $profundidade + 1, 'ia');
    }
}
function cmtFechar($c, $quemNome) {
    sd_supabase('/rest/v1/pd_comits?id=eq.' . urlencode($c['id']), 'PATCH', ['status' => 'fechado', 'fechado_em' => gmdate('c')]);
    cmtMsg($c['id'], 'sistema', null, 'PD', 'Comit fechado por ' . $quemNome . '. O histórico fica guardado.');
    cmtLog($c['id'], 'fechou', $quemNome);
}
