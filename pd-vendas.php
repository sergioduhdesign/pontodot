<?php
// PD · VENDAS — endpoint do CRM do Tumi. Só admin ou equipe com a ferramenta "vendas".
// POST {acao, carteira?: uuid|''|'todas', ...}
require_once __DIR__ . '/sd-base.php';
require_once __DIR__ . '/sd-auth.php';
require_once __DIR__ . '/pd-vendas-lib.php';
require_once __DIR__ . '/pd-dig-lib.php';
require_once __DIR__ . '/pd-pessoas-lib.php';
require_once __DIR__ . '/pd-lib.php';
header('Content-Type: application/json; charset=utf-8');
@set_time_limit(240);
$u = sd_current_user();
if (!vdPode($u)) sd_fail('Vendas não está liberada pra você. Peça ao admin.', 403);
$b = sd_body(); $acao = (string)($b['acao'] ?? 'funil');
$cart = $b['carteira'] ?? ''; $cart = $cart === 'todas' ? 'todas' : (vdUuid($cart) ?: vdCasaId());   // sem carteira = SD/STRAT (a casa é um cliente como os outros)
$out = fn($d) => print(json_encode(['ok' => true] + $d, JSON_UNESCAPED_UNICODE));
$fCart = $cart === 'todas' ? '' : '&carteira_id=' . ($cart ? 'eq.' . $cart : 'is.null');
$pid = vdUuid($b['id'] ?? ''); $cid = vdUuid($b['conversa'] ?? '');

switch ($acao) {
    case 'funil': $out(['pedidos' => vdLinhas(vdSup('/rest/v1/pd_ia_pedidos?para_ia=eq.tumi&order=criado_em.desc&limit=12&select=pedido,estado,resposta,de_ia,contexto,criado_em,concluido_em')), 'funil' => vdFunil($cart), 'carteiras' => vdLinhas(vdSup('/rest/v1/clients?select=id,name,logo_url,color&active=is.true&order=name')),
        'fila' => count(vdLinhas(vdSup('/rest/v1/pd_prospects?lote_id=not.is.null&enriquecido_em=is.null&select=id&limit=1000'))), 'imap' => (bool)vdCaixasTodas(), 'caixas' => array_map(fn($x) => $x['client_id'], vdLinhas(vdSup('/rest/v1/pd_vendas_caixas?ativo=is.true&select=client_id')))]); break;
    case 'listar':
        $qs = vdFiltroQs($b, $cart);
        $out(['leads' => vdLinhas(vdSup('/rest/v1/pd_prospects?select=id,nome,site,cidade,uf,segmento,estagio,nota,angulo,ia,proximo_passo,ultimo_contato,carteira_id,zap,instagram,lote_id,enriquecido_em' . $qs . '&order=nota.desc.nullslast,created_at.desc&limit=100&offset=' . max(0, (int)($b['de'] ?? 0)))), 'total' => vdContar($qs)]); break;
    case 'resumo':
        $r = sd_supabase('/rest/v1/rpc/pd_vendas_resumo', 'POST', new stdClass()); $d = (($r['code'] ?? 500) < 300 && is_array($r['body'])) ? $r['body'] : ['total' => 0, 'novos' => 0, 'engajados' => 0, 'ganhos' => 0, 'caixa' => 0, 'respostas' => 0, 'carteiras' => [], 'sem_sql' => true];
        $cl = []; foreach (vdLinhas(vdSup('/rest/v1/clients?select=id,name,logo_url,color')) as $c) $cl[$c['id']] = $c;
        $d['carteiras'] = array_map(fn($x) => $x + ['nome' => $x['id'] ? ($cl[$x['id']]['name'] ?? 'Cliente') : 'Casa', 'logo' => $x['id'] ? ($cl[$x['id']]['logo_url'] ?? null) : null, 'cor' => $x['id'] ? ($cl[$x['id']]['color'] ?? null) : null], (array)($d['carteiras'] ?? []));
        // ENTREGAS DO TUMI (últimos 14 dias): cada garimpo = um lote "Descoberta …", com o cliente e quantas empresas
        $lts = vdLinhas(vdSup('/rest/v1/pd_lotes?nome=like.Descoberta*&created_at=gte.' . rawurlencode(gmdate('c', time() - 14 * 86400)) . '&select=id,nome,motivo,carteira_id,created_at&order=created_at.desc&limit=80')); $ent = [];
        foreach ($lts as $l) { $n = count(vdLinhas(vdSup('/rest/v1/pd_prospects?lote_id=eq.' . $l['id'] . '&select=id&limit=500'))); if (!$n) continue; $c = $l['carteira_id'] ? ($cl[$l['carteira_id']] ?? null) : null;
            $ent[] = ['lote' => $l['id'], 'cart' => $l['carteira_id'], 'cliente' => $c['name'] ?? 'SD / Stratesign', 'logo' => $c['logo_url'] ?? null, 'cor' => $c['color'] ?? null, 'n' => $n, 'motivo' => mb_substr((string)$l['motivo'], 0, 160), 'em' => $l['created_at']]; }
        // garimpos de MUITA carteira não escondem os de outra: guarda os 3 mais recentes de cada cliente e limita o total
        $cont = []; $ent = array_values(array_filter($ent, function ($e) use (&$cont) { $k = (string)$e['cart']; $cont[$k] = ($cont[$k] ?? 0) + 1; return $cont[$k] <= 3; })); $ent = array_slice($ent, 0, 24);
        $d['entregas'] = $ent;
        $out($d); break;
    case 'exportar':   // as empresas de um lote (ou do filtro atual), prontas para Excel / PDF
        $qs = vdFiltroQs($b, $cart); $rows = vdLinhas(vdSup('/rest/v1/pd_prospects?select=nome,site,cidade,uf,segmento,porte,estagio,nota,angulo,proximo_passo,instagram,zap,lote_id,pd_prospect_contatos(nome,cargo,email,zap)&order=nota.desc.nullslast' . $qs . '&limit=500'));
        $o = []; foreach ($rows as $r) { $ct = (array)($r['pd_prospect_contatos'] ?? []); $c0 = $ct[0] ?? [];
            $o[] = ['empresa' => $r['nome'], 'site' => $r['site'], 'cidade' => trim(($r['cidade'] ?? '') . ($r['uf'] ? '/' . $r['uf'] : '')), 'segmento' => $r['segmento'], 'porte' => $r['porte'], 'nota' => $r['nota'], 'estagio' => $r['estagio'], 'por_que' => $r['angulo'], 'proximo_passo' => $r['proximo_passo'], 'contato' => $c0['nome'] ?? '', 'cargo' => $c0['cargo'] ?? '', 'email' => $c0['email'] ?? '', 'zap' => $c0['zap'] ?? ($r['zap'] ?? ''), 'instagram' => $r['instagram']]; }
        $lt = vdUuid($b['lote'] ?? ''); $lnome = ''; if ($lt) { $lr = vdLinhas(vdSup('/rest/v1/pd_lotes?id=eq.' . $lt . '&select=nome,motivo,carteira_id')); $lnome = $lr[0]['motivo'] ?? ($lr[0]['nome'] ?? ''); $cc = $lr[0]['carteira_id'] ?? null; }
        $out(['linhas' => $o, 'titulo' => $lnome, 'cliente' => isset($cc) && $cc ? (vdLinhas(vdSup('/rest/v1/clients?id=eq.' . $cc . '&select=name'))[0]['name'] ?? '') : 'SD / Stratesign']); break;
    case 'lotes': $out(['lotes' => vdLinhas(vdSup('/rest/v1/pd_lotes?select=id,nome,motivo,created_at' . ($cart === 'todas' ? '' : '&carteira_id=' . ($cart ? 'eq.' . $cart : 'is.null')) . '&order=created_at.desc&limit=30'))]); break;
    case 'importar_linhas':   // pedaço de planilha grande (o navegador manda de 400 em 400)
        $rows = array_slice((array)($b['rows'] ?? []), 0, 500); $lote = vdUuid($b['lote_id'] ?? '');
        if (!$lote && !empty($b['lote'])) { $lr = vdLinhas(vdSup('/rest/v1/pd_lotes', 'POST', ['nome' => mb_substr((string)$b['lote'], 0, 120), 'carteira_id' => $cart === 'todas' ? null : $cart, 'criado_por' => $u['id']])); $lote = $lr[0]['id'] ?? null; }
        $r = vdImportarLinhas($rows, $cart === 'todas' ? null : $cart, null, $u['id'], 'planilha'); if (!empty($r['erro'])) sd_fail($r['erro'], 500); unset($r['ids']); $out(['r' => $r, 'lote_id' => $lote]); break;
    case 'lote_criar':
        $r = vdLoteCriar(vdFiltroQs($b, $cart), trim((string)($b['nome'] ?? '')) ?: 'Lote ' . date('d/m H:i'), (int)($b['max'] ?? 500), $cart, $u['id'], (string)($b['motivo'] ?? '') ?: null);
        if (!empty($r['erro'])) sd_fail($r['erro'], 500); $out(['r' => $r]); break;
    case 'pedidos_reabrir':   // pedidos ao Tumi que morreram com erro nas últimas 24h voltam pra fila agora (sem pedir de novo)
        $ps = vdLinhas(vdSup('/rest/v1/pd_ia_pedidos?para_ia=eq.tumi&estado=eq.erro&criado_em=gte.' . rawurlencode(gmdate('c', time() - 86400)) . '&select=id,contexto')); $n = 0;
        foreach ($ps as $pp) { $cx = is_array($pp['contexto'] ?? null) ? $pp['contexto'] : (json_decode((string)($pp['contexto'] ?? ''), true) ?: []); unset($cx['proxima_em']); $cx['tentativas'] = 0;
            $w = vdSup('/rest/v1/pd_ia_pedidos?id=eq.' . $pp['id'], 'PATCH', ['estado' => 'novo', 'iniciado_em' => null, 'concluido_em' => null, 'resposta' => 'Reaberto: tentando de novo agora.', 'contexto' => $cx]); if (($w['code'] ?? 500) < 300) $n++; }
        if ($n && function_exists('pdAcordaRelogio')) pdAcordaRelogio();
        $out(['reabertos' => $n]); break;
    case 'descobrir':
        $r = vdDescobrir((string)($b['consulta'] ?? ''), (int)($b['quantos'] ?? 10), $cart, $u['id']); if (empty($r['ok'])) sd_fail($r['erro'] ?? 'não consegui', 422); $out(['r' => $r]); break;
    case 'pessoas_descobrir':
        $r = vdDescobrirPessoas((string)($b['consulta'] ?? ''), (int)($b['quantos'] ?? 10), $cart, $u['id']); if (empty($r['ok'])) sd_fail($r['erro'] ?? 'não consegui', 422); $out(['r' => $r]); break;
    case 'pessoas_triar':
        $r = vdTriarPessoas((string)($b['texto'] ?? ''), (string)($b['criterio'] ?? ''), $cart, $u['id']); if (empty($r['ok'])) sd_fail($r['erro'] ?? 'não consegui', 422); $out(['r' => $r]); break;
    case 'inbox': $r = vdInboxLer(25, $cart === 'todas' ? null : $cart); $out(['r' => $r]); break;
    case 'auto_ler':
        $o = ['auto' => vdLinhas(vdSup('/rest/v1/pd_vendas_auto?select=*'))];
        if ($cart && $cart !== 'todas') $o['hoje'] = ['enviados' => vdEnviadosHoje($cart)];
        $o['teto_geral'] = vdTetoGeral(); $o['hoje_geral'] = vdEnviadosHoje(); $out($o); break;
    case 'teto_salvar':
        if (($u['role'] ?? '') !== 'admin') sd_fail('Só o admin muda o teto.', 403);
        $v = ['envios' => max(1, min(2000, (int)($b['envios'] ?? 100))), 'buscas' => max(0, min(200, (int)($b['buscas'] ?? 30)))];
        sd_supabase('/rest/v1/pd_vendas_estado?on_conflict=chave', 'POST', ['chave' => 'teto_geral', 'valor' => $v, 'atualizado_em' => gmdate('c')], ['Prefer: resolution=merge-duplicates,return=minimal']);
        $out(['teto' => $v]); break;
    case 'auto_salvar':
        if (($u['role'] ?? '') !== 'admin') sd_fail('Só o admin liga o automático.', 403);
        if (!$cart || $cart === 'todas') sd_fail('escolha um cliente');
        $at = vdAutoCfg($cart); $ativo = !empty($b['ativo']); $of = trim(mb_substr((string)($b['oferta'] ?? ''), 0, 1500));
        if ($ativo && $of === '') sd_fail('Antes de ligar, diga o que o Tumi pode oferecer (produtos de agência, simples).');
        if ($ativo && !vdCaixaDe($cart)) sd_fail('Antes de ligar, ligue o e-mail desse cliente (Caixa de saída › E-mail dos clientes).');
        $hi = max(6, min(20, (int)($b['hora_ini'] ?? 9))); $hf = max($hi + 1, min(22, (int)($b['hora_fim'] ?? 18)));
        $row = ['client_id' => $cart, 'ativo' => $ativo, 'oferta' => $of, 'consulta' => trim(mb_substr((string)($b['consulta'] ?? ''), 0, 300)), 'descobrir_dia' => max(0, min(15, (int)($b['descobrir_dia'] ?? 10))),
            'enviar_dia' => max(1, min(VD_TETO_DIA, (int)($b['enviar_dia'] ?? 20))), 'nota_min' => max(0, min(100, (int)($b['nota_min'] ?? 0))), 'hora_ini' => $hi, 'hora_fim' => $hf, 'atualizado_em' => gmdate('c')];
        $r = $at ? vdSup('/rest/v1/pd_vendas_auto?client_id=eq.' . $cart, 'PATCH', $row) : vdSup('/rest/v1/pd_vendas_auto', 'POST', $row);
        if (($r['code'] ?? 500) >= 300) sd_fail('Rode o SQL v151 no Supabase.', 500);
        $out(['ok' => true]); break;
    case 'termometro': $out(['t' => vdTermometro()]); break;
    case 'caixas':   // sem a senha: ela nunca volta pro navegador
        $out(['caixas' => array_map(function ($x) { $x['tem_senha'] = !empty($x['senha_cifrada']); $x['senha_tam'] = $x['tem_senha'] ? mb_strlen(vdDecifrar($x['senha_cifrada'])) : 0; unset($x['senha_cifrada']); return $x; }, vdLinhas(vdSup('/rest/v1/pd_vendas_caixas?select=id,client_id,email,usuario,imap_host,imap_porta,smtp_host,smtp_porta,ativo,ultimo_erro,ultima_leitura,senha_cifrada')))]); break;
    case 'caixa_salvar': case 'caixa_testar': case 'caixa_remover': case 'caixa_confiar':
        if (($u['role'] ?? '') !== 'admin') sd_fail('Só o admin mexe no e-mail dos clientes.', 403);
        $cli = vdUuid($b['client_id'] ?? ''); if (!$cli) sd_fail('falta o cliente');
        $at = vdLinhas(vdSup('/rest/v1/pd_vendas_caixas?client_id=eq.' . $cli . '&select=*&limit=1'))[0] ?? null;
        if ($acao === 'caixa_remover') { if ($at) vdSup('/rest/v1/pd_vendas_caixas?client_id=eq.' . $cli, 'DELETE'); $out(['removida' => true]); break; }
        if ($acao === 'caixa_confiar') {   // o admin viu de quem é o certificado e confirmou: guarda a impressão digital desse servidor
            if (!$at) sd_fail('esse cliente ainda não tem e-mail ligado');
            $lado = ($b['lado'] ?? '') === 'saida' ? 'smtp' : 'imap'; $h = trim((string)($b['host'] ?? '')); $po = (int)($b['porta'] ?? 0);
            $c = vdTlsLer($h, $po); if (!$c || !hash_equals($c['fp'], (string)($b['fp'] ?? ''))) sd_fail('o certificado mudou desde que você viu. Teste de novo.');
            vdSup('/rest/v1/pd_vendas_caixas?client_id=eq.' . $cli, 'PATCH', ['tls_pin_' . $lado => $c['fp'], $lado . '_host' => $h, $lado . '_porta' => $po]);
            $cx = vdCaixaDe($cli); $out(['teste' => $cx ? vdCaixaTestar($cx) : null]); break; }
        if ($acao === 'caixa_testar') { if (!$at) sd_fail('esse cliente ainda não tem e-mail ligado'); $at['senha'] = vdDecifrar($at['senha_cifrada']); unset($at['senha_cifrada']); $out(['teste' => vdCaixaTestar($at)]); break; }
        $em = strtolower(trim((string)($b['email'] ?? ''))); if (!filter_var($em, FILTER_VALIDATE_EMAIL)) sd_fail('e-mail inválido');
        $dom = substr(strrchr($em, '@'), 1); $senha = (string)($b['senha'] ?? '');
        if ($senha === '' && !$at) sd_fail('falta a senha do e-mail');
        $row = ['client_id' => $cli, 'email' => $em, 'usuario' => trim((string)($b['usuario'] ?? '')) ?: $em,
            'imap_host' => trim((string)($b['imap_host'] ?? '')), 'imap_porta' => (int)($b['imap_porta'] ?? 993) ?: 993,
            'smtp_host' => trim((string)($b['smtp_host'] ?? '')), 'smtp_porta' => (int)($b['smtp_porta'] ?? 465) ?: 465,
            'ativo' => true, 'ultimo_erro' => null, 'atualizado_em' => gmdate('c')];
        if ($senha !== '') $row['senha_cifrada'] = vdCifrar($senha);
        if ($at) { $r = vdSup('/rest/v1/pd_vendas_caixas?client_id=eq.' . $cli, 'PATCH', $row); } else { $row['ultimo_uid'] = 0; $r = vdSup('/rest/v1/pd_vendas_caixas', 'POST', $row); }
        if (($r['code'] ?? 500) >= 300) sd_fail('não consegui salvar: ' . mb_substr(json_encode($r['body'] ?? $r['raw'] ?? '', JSON_UNESCAPED_UNICODE), 0, 200), 500);
        $cx = vdCaixaDe($cli); $out(['salva' => true, 'teste' => ($cx && empty($b['sem_teste'])) ? vdCaixaTestar($cx) : null]); break;
    case 'dig_catalogo': $out(['jornadas' => digJornadas()]); break;
    case 'dig_viabilidade':
        $out(['v' => digViabilidade((string)($b['briefing'] ?? ''), (string)($b['modo'] ?? 'achar')), 'pendencias' => digPendencias((string)($b['modo'] ?? 'achar'), vdUuid($b['client_id'] ?? '') ?: vdCasaId())]); break;
    case 'dig_criar': $r = digCriar($b, $u['id']); if (!empty($r['erro']) && ($r['erro'] !== 'pendencia')) sd_fail($r['erro'], 422); $out($r); break;
    case 'dig_rodar': $did = vdUuid($b['dig'] ?? ''); if (!$did) sd_fail('falta a Dig'); $r = digExecutar($did, $u['id']); if (!empty($r['erro'])) sd_fail($r['erro'], 422); $out(['r' => $r]); break;
    case 'dig_listar':
        $q = '/rest/v1/pd_digs?select=*&order=created_at.desc&limit=60' . ($cart === 'todas' ? '' : '&client_id=eq.' . $cart);
        $out(['digs' => vdLinhas(vdSup($q))]); break;
    case 'dig_apagar': $did = vdUuid($b['dig'] ?? ''); if ($did) vdSup('/rest/v1/pd_digs?id=eq.' . $did, 'DELETE'); $out([]); break;
    case 'ver':
        if (!$pid) sd_fail('falta o lead');
        $out(['lead' => vdLinhas(vdSup('/rest/v1/pd_prospects?id=eq.' . $pid . '&select=*'))[0] ?? null,
              'contatos' => vdLinhas(vdSup('/rest/v1/pd_prospect_contatos?prospect_id=eq.' . $pid . '&select=*&order=principal.desc')),
              'conversas' => vdLinhas(vdSup('/rest/v1/pd_prospect_conversas?prospect_id=eq.' . $pid . '&select=*&order=created_at.asc&limit=60'))]); break;
    case 'caixa':   // rascunhos esperando aprovação
        $rs = vdLinhas(vdSup('/rest/v1/pd_prospect_conversas?status=in.(aguardando,aprovada)&direcao=eq.saida&select=*,pd_prospects!inner(id,nome,carteira_id,estagio)&order=created_at.asc&limit=100'));
        if ($cart !== 'todas') $rs = array_values(array_filter($rs, fn($x) => ($x['pd_prospects']['carteira_id'] ?? null) === $cart));
        $out(['caixa' => $rs, 'hoje' => vdEnviadosHoje(), 'teto' => VD_TETO_DIA]); break;
    case 'importar':
        $out(['r' => vdImportar((string)($b['linhas'] ?? ''), $cart === 'todas' ? null : $cart, trim((string)($b['lote'] ?? '')) ?: null, $u['id'])]); break;
    case 'enriquecer':
        if ($pid) { $out(['r' => vdEnriquecer($pid)]); break; }
        $ids = array_slice(array_filter(array_map('vdUuid', (array)($b['ids'] ?? []))), 0, 12); $rs = [];
        if (!$ids) $ids = array_column(vdLinhas(vdSup('/rest/v1/pd_prospects?enriquecido_em=is.null&select=id' . $fCart . '&order=created_at.desc&limit=10')), 'id');
        foreach ($ids as $i) $rs[$i] = vdEnriquecer($i);
        $out(['feitos' => count($rs), 'r' => $rs]); break;
    case 'escrever':
        if (!$pid) sd_fail('falta o lead');
        $canal = in_array($b['canal'] ?? '', ['email', 'zap'], true) ? $b['canal'] : 'email';
        $r = vdEscrever($pid, $canal, max(1, min(3, (int)($b['seq'] ?? 1))), null, (string)($b['oferta'] ?? ''));
        if (empty($r['ok'])) sd_fail($r['erro'] ?? 'não consegui escrever', 422); $out(['r' => $r]); break;
    case 'escrever_lote':   // abre a fila: escreve a abertura dos N melhores leads novos que ainda não têm mensagem
        $canal = in_array($b['canal'] ?? '', ['email', 'zap'], true) ? $b['canal'] : 'email'; $n = max(1, min(10, (int)($b['quantos'] ?? 5))); $feitos = [];
        $leads = vdLinhas(vdSup('/rest/v1/pd_prospects?estagio=eq.novo&select=id,nome' . $fCart . '&order=nota.desc.nullslast&limit=60'));
        foreach ($leads as $l) {
            if (count($feitos) >= $n) break;
            if (vdLinhas(vdSup('/rest/v1/pd_prospect_conversas?prospect_id=eq.' . $l['id'] . '&direcao=eq.saida&select=id&limit=1'))) continue;
            $r = vdEscrever($l['id'], $canal, 1, null, (string)($b['oferta'] ?? '')); $feitos[] = ['lead' => $l['nome'], 'ok' => !empty($r['ok']), 'erro' => $r['erro'] ?? null];
        }
        $out(['feitos' => $feitos]); break;
    case 'editar':
        if (!$cid) sd_fail('falta a mensagem');
        $up = []; if (isset($b['corpo'])) $up['corpo'] = mb_substr((string)$b['corpo'], 0, 4000); if (isset($b['assunto'])) $up['assunto'] = mb_substr((string)$b['assunto'], 0, 120);
        if ($up) vdSup('/rest/v1/pd_prospect_conversas?id=eq.' . $cid . '&status=in.(aguardando,aprovada,rascunho)', 'PATCH', $up); $out([]); break;
    case 'rejeitar':
        if (!$cid) sd_fail('falta a mensagem'); vdSup('/rest/v1/pd_prospect_conversas?id=eq.' . $cid, 'PATCH', ['status' => 'rejeitada']); $out([]); break;
    case 'enviar':   // aprovar + enviar (ids[] ou uma só). A aprovação é de quem clica.
        $ids = $cid ? [$cid] : array_slice(array_filter(array_map('vdUuid', (array)($b['ids'] ?? []))), 0, 25); $rs = [];
        foreach ($ids as $i) { $r = vdEnviar($i, $u['id']); $rs[] = ['id' => $i] + $r; if (!empty($r['teto'])) break; }
        $out(['r' => $rs, 'hoje' => vdEnviadosHoje()]); break;
    case 'entrada':   // colou a resposta que o lead mandou
        if (!$pid || trim((string)($b['texto'] ?? '')) === '') sd_fail('falta o lead ou o texto');
        $out(['r' => vdEntrada($pid, in_array($b['canal'] ?? '', ['email', 'zap', 'dm', 'ligacao', 'reuniao'], true) ? $b['canal'] : 'email', (string)$b['texto'])]); break;
    case 'mover':
        if (!$pid) sd_fail('falta o lead'); $e = (string)($b['estagio'] ?? ''); if (!in_array($e, VD_ESTAGIOS, true)) sd_fail('estágio inválido');
        $up = ['estagio' => $e]; if ($e === 'perdido') $up['motivo_perda'] = mb_substr((string)($b['motivo'] ?? ''), 0, 200) ?: null; if (in_array($e, ['ganho', 'perdido'], true)) $up['proximo_passo'] = null;
        vdSup('/rest/v1/pd_prospects?id=eq.' . $pid, 'PATCH', $up); $out([]); break;
    case 'contato':
        if (!$pid) sd_fail('falta o lead'); $em = strtolower(trim((string)($b['email'] ?? ''))); $zp = pdZapNormaliza($b['zap'] ?? '');
        if ($em !== '' && !filter_var($em, FILTER_VALIDATE_EMAIL)) sd_fail('e-mail inválido');
        vdSup('/rest/v1/pd_prospect_contatos', 'POST', ['prospect_id' => $pid, 'nome' => trim((string)($b['nome'] ?? '')) ?: 'Contato', 'cargo' => ($b['cargo'] ?? '') ?: null, 'email' => $em ?: null, 'zap' => strlen($zp) >= 12 ? $zp : null, 'email_status' => 'palpite', 'principal' => true, 'dono' => !empty($b['dono'])]); $out([]); break;
    case 'ganhou':   // vira cliente com um clique
        if (!$pid) sd_fail('falta o lead'); $p = vdLinhas(vdSup('/rest/v1/pd_prospects?id=eq.' . $pid . '&select=nome,client_id'))[0] ?? null; if (!$p) sd_fail('lead não encontrado');
        $clId = $p['client_id'];
        if (!$clId) { $c = vdLinhas(vdSup('/rest/v1/clients', 'POST', ['name' => $p['nome'], 'active' => true]))[0] ?? null; $clId = $c['id'] ?? null; if (!$clId) sd_fail('não consegui criar o cliente', 500); }
        vdSup('/rest/v1/pd_prospects?id=eq.' . $pid, 'PATCH', ['estagio' => 'ganho', 'client_id' => $clId, 'proximo_passo' => null]); $out(['client_id' => $clId]); break;
    case 'cadencia': $out(['feitos' => vdCadencia($cart, 8)]); break;
    default: sd_fail('ação desconhecida');
}
