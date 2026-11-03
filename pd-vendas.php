<?php
// PD · VENDAS — endpoint do CRM/Harvey. Só admin ou equipe com a ferramenta "vendas".
// POST {acao, carteira?: uuid|''|'todas', ...}
require_once __DIR__ . '/sd-base.php';
require_once __DIR__ . '/sd-auth.php';
require_once __DIR__ . '/pd-vendas-lib.php';
header('Content-Type: application/json; charset=utf-8');
@set_time_limit(240);
$u = sd_current_user();
if (!vdPode($u)) sd_fail('Vendas não está liberada pra você. Peça ao admin.', 403);
$b = sd_body(); $acao = (string)($b['acao'] ?? 'funil');
$cart = $b['carteira'] ?? ''; $cart = $cart === 'todas' ? 'todas' : (vdUuid($cart) ?: null);
$out = fn($d) => print(json_encode(['ok' => true] + $d, JSON_UNESCAPED_UNICODE));
$fCart = $cart === 'todas' ? '' : '&carteira_id=' . ($cart ? 'eq.' . $cart : 'is.null');
$pid = vdUuid($b['id'] ?? ''); $cid = vdUuid($b['conversa'] ?? '');

switch ($acao) {
    case 'funil': $out(['funil' => vdFunil($cart), 'carteiras' => vdLinhas(vdSup('/rest/v1/clients?select=id,name&active=is.true&order=name'))]); break;
    case 'listar':
        $est = preg_replace('/[^a-z,]/', '', (string)($b['estagio'] ?? ''));
        $out(['leads' => vdLinhas(vdSup('/rest/v1/pd_prospects?select=id,nome,site,cidade,segmento,estagio,nota,angulo,ia,proximo_passo,ultimo_contato,carteira_id,zap,instagram' . $fCart . ($est ? '&estagio=in.(' . $est . ')' : '') . '&order=nota.desc.nullslast,created_at.desc&limit=200'))]); break;
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
        if (!$ids) $ids = array_column(vdLinhas(vdSup('/rest/v1/pd_prospects?nota=is.null&select=id' . $fCart . '&order=created_at.desc&limit=10')), 'id');
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
