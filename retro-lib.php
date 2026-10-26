<?php
// =====================================================================
// Pontodot — RETROSPECTIVA (relatório semanal de social e campanha)
//
// O que faz: junta os números da semana do cliente (anúncios da Meta, Instagram
// orgânico e, se houver, o funil do CRM), compara com a semana anterior e com o
// mercado (faixas públicas + a média da nossa carteira), pede a leitura à IA e
// guarda tudo num "snapshot". Quem lê o snapshot: o BI do cliente (retro.html),
// o PDF (retro-pdf.php) e o zap semanal (pd-worker.php → w_retro).
// =====================================================================

require_once __DIR__ . '/sd-base.php';
require_once __DIR__ . '/meta-ads-lib.php';

const RETRO_SEGMENTOS = ['lancamento' => 'Lançamento', 'alto_padrao' => 'Alto padrão', 'medio_padrao' => 'Médio padrão', 'mcmv' => 'MCMV'];
const RETRO_METRICAS  = [   // rótulo, unidade, melhor: menor|maior|faixa
    'cpl'  => ['Custo por lead (CPL)', 'brl', 'menor'],
    'cpm'  => ['CPM (custo por mil)', 'brl', 'menor'],
    'ctr'  => ['CTR (cliques/impressões)', 'pct', 'maior'],
    'freq' => ['Frequência', 'num', 'faixa'],
];

function retro_sp() { return new DateTime('now', new DateTimeZone('America/Sao_Paulo')); }

/** Janela de 7 dias fechados que terminam ontem, e os 7 anteriores. */
function retro_periodo($fim = null) {
    $f = $fim ? new DateTime($fim, new DateTimeZone('America/Sao_Paulo')) : retro_sp()->modify('-1 day');
    $i = (clone $f)->modify('-6 days');
    $pf = (clone $i)->modify('-1 day'); $pi = (clone $pf)->modify('-6 days');
    return ['ini' => $i->format('Y-m-d'), 'fim' => $f->format('Y-m-d'), 'p_ini' => $pi->format('Y-m-d'), 'p_fim' => $pf->format('Y-m-d')];
}

function retro_div($a, $b) { return $b > 0 ? $a / $b : null; }
function retro_var($atual, $ant) { return ($ant === null || $ant == 0 || $atual === null) ? null : round(($atual - $ant) / $ant * 100, 1); }

/** Os números derivados, iguais para a semana atual e a anterior. */
function retro_fecha($t) {
    $t['cpl']  = retro_div($t['gasto'], $t['leads']);
    $t['ctr']  = retro_div($t['cliques'] * 100, $t['impressoes']);
    $t['cpm']  = retro_div($t['gasto'] * 1000, $t['impressoes']);
    $t['freq'] = retro_div($t['impressoes'], $t['alcance']);
    foreach (['cpl', 'ctr', 'cpm', 'freq'] as $k) if ($t[$k] !== null) $t[$k] = round($t[$k], 2);
    return $t;
}
function retro_zera() { return ['gasto' => 0.0, 'leads' => 0, 'cliques' => 0, 'impressoes' => 0, 'alcance' => 0]; }

/** Anúncios de UMA conta, num intervalo: total, dia a dia e campanhas. */
function retro_ads_conta($contaId, $token, $ini, $fim) {
    $tr = json_encode(['since' => $ini, 'until' => $fim]);
    $campos = 'spend,reach,impressions,clicks,actions';
    $tot = pd_meta('GET', "/$contaId/insights", ['time_range' => $tr, 'fields' => $campos], $token, 30);
    if ($tot['code'] !== 200) return ['erro' => pd_meta_erro($tot)];
    $n = pd_numeros($tot['body']['data'][0] ?? []);
    $dia = pd_meta('GET', "/$contaId/insights", ['time_range' => $tr, 'time_increment' => 1, 'fields' => 'spend,actions,date_start', 'limit' => 40], $token, 30);
    $dias = [];
    foreach ((array)($dia['body']['data'] ?? []) as $l) $dias[$l['date_start']] = ['gasto' => round((float)($l['spend'] ?? 0), 2), 'leads' => pd_leads_de($l['actions'] ?? [])];
    $camp = pd_meta('GET', "/$contaId/insights", ['time_range' => $tr, 'level' => 'campaign', 'fields' => 'campaign_name,campaign_id,spend,impressions,clicks,actions', 'limit' => 100], $token, 30);
    $cs = [];
    foreach ((array)($camp['body']['data'] ?? []) as $c) {
        $g = round((float)($c['spend'] ?? 0), 2); $l = pd_leads_de($c['actions'] ?? []);
        $im = (int)($c['impressions'] ?? 0); $cl = (int)($c['clicks'] ?? 0);
        $cs[] = ['nome' => $c['campaign_name'] ?? '?', 'gasto' => $g, 'leads' => $l, 'cpl' => $l > 0 ? round($g / $l, 2) : null,
                 'ctr' => $im > 0 ? round($cl * 100 / $im, 2) : null];
    }
    return ['tot' => $n, 'dias' => $dias, 'camp' => $cs];
}

/** Instagram orgânico (alcance, interações, seguidores) das contas do cliente. null se não der. */
function retro_organico($clientId, $ini, $fim) {
    $contas = pd_supa('/rest/v1/social_accounts?client_id=eq.' . urlencode($clientId) . '&platform=eq.instagram&status=eq.conectada&select=id,account_name,external_account_id');
    $lista = array_values(array_filter((array)($contas['body'] ?? []), 'is_array'));
    if (!$lista) return null;
    $out = ['contas' => [], 'alcance' => 0, 'interacoes' => 0, 'seguidores' => 0, 'visitas_perfil' => 0];
    $ok = false;
    foreach ($lista as $c) {
        $tk = pd_supa('/rest/v1/social_account_tokens?social_account_id=eq.' . urlencode($c['id']) . '&select=access_token');
        $token = $tk['body'][0]['access_token'] ?? null; if (!$token) continue;
        $r = pd_meta('GET', '/' . $c['external_account_id'] . '/insights', ['metric' => 'reach,total_interactions,profile_views', 'period' => 'day', 'metric_type' => 'total_value',
            'since' => strtotime($ini . ' 00:00:00 America/Sao_Paulo'), 'until' => strtotime($fim . ' 23:59:59 America/Sao_Paulo')], $token, 30);
        if ($r['code'] !== 200) continue;
        $ok = true; $m = [];
        foreach ((array)($r['body']['data'] ?? []) as $x) $m[$x['name']] = (int)($x['total_value']['value'] ?? 0);
        $f = pd_meta('GET', '/' . $c['external_account_id'], ['fields' => 'followers_count'], $token, 20);
        $seg = (int)($f['body']['followers_count'] ?? 0);
        $out['alcance'] += $m['reach'] ?? 0; $out['interacoes'] += $m['total_interactions'] ?? 0; $out['visitas_perfil'] += $m['profile_views'] ?? 0; $out['seguidores'] += $seg;
        $out['contas'][] = ['nome' => $c['account_name'], 'alcance' => $m['reach'] ?? 0, 'interacoes' => $m['total_interactions'] ?? 0, 'seguidores' => $seg];
    }
    // posts publicados na semana
    $p = pd_supa('/rest/v1/scheduled_post_targets?status=eq.publicado&published_at=gte.' . $ini . 'T00:00:00-03:00&published_at=lte.' . $fim . 'T23:59:59-03:00&social_account_id=in.(' . implode(',', array_map(fn($c) => $c['id'], $lista)) . ')&select=id&limit=200');
    $out['posts'] = is_array($p['body'] ?? null) ? count($p['body']) : null;
    return $ok ? $out : null;
}

/** Tudo da semana do cliente: anúncios, orgânico, mercado, alertas. */
function retro_coletar($clientId, $periodo) {
    $cli = pd_supa('/rest/v1/clients?id=eq.' . urlencode($clientId) . '&select=name');
    $nome = $cli['body'][0]['name'] ?? 'Cliente';
    $cfg = pd_supa('/rest/v1/pd_retro_config?client_id=eq.' . urlencode($clientId) . '&select=segmento');
    $segmento = $cfg['body'][0]['segmento'] ?? 'lancamento';
    $d = ['cliente' => $nome, 'periodo' => $periodo, 'segmento' => $segmento, 'gerado_em' => gmdate('c'), 'ads' => null, 'organico' => null, 'funil' => null];

    $contas = pd_supa('/rest/v1/meta_ad_accounts?client_id=eq.' . urlencode($clientId) . '&select=id,nome,conectada_por');
    $vinculadaPorNome = false;
    if (empty($contas['body'])) {   // conta ainda sem vínculo, mas com o nome do cliente (ex.: "CA - GEOVILLE")
        $n = preg_replace('/[^a-z0-9]/', '', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nome)));
        if (strlen($n) >= 4) {
            $todas = pd_supa('/rest/v1/meta_ad_accounts?client_id=is.null&select=id,nome,conectada_por&limit=200');
            $ach = array_values(array_filter((array)($todas['body'] ?? []), fn($c) => is_array($c) && strpos(preg_replace('/[^a-z0-9]/', '', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string)$c['nome']))), $n) !== false));
            if ($ach) { $contas = ['body' => $ach]; $vinculadaPorNome = true; }
        }
    }
    $A = retro_zera(); $P = retro_zera(); $porDia = []; $camps = []; $erros = [];
    foreach ((array)($contas['body'] ?? []) as $c) {
        if (!is_array($c)) continue;
        $tok = pd_token_para($c['conectada_por'] ?? null);
        if (!$tok) { $erros[] = $c['nome'] . ': acesso à Meta vencido (reconecte).'; continue; }
        $a = retro_ads_conta($c['id'], $tok, $periodo['ini'], $periodo['fim']);
        $p = retro_ads_conta($c['id'], $tok, $periodo['p_ini'], $periodo['p_fim']);
        if (isset($a['erro'])) { $erros[] = $c['nome'] . ': ' . $a['erro']; continue; }
        foreach (['gasto', 'leads', 'cliques', 'impressoes', 'alcance'] as $k) { $A[$k] += $a['tot'][$k]; $P[$k] += $p['tot'][$k] ?? 0; }
        foreach ($a['dias'] as $dt => $v) { $porDia[$dt]['gasto'] = ($porDia[$dt]['gasto'] ?? 0) + $v['gasto']; $porDia[$dt]['leads'] = ($porDia[$dt]['leads'] ?? 0) + $v['leads']; }
        foreach ($a['camp'] as $cc) $camps[] = $cc;
    }
    if ($contas['body'] ?? null) {
        $A = retro_fecha($A); $P = retro_fecha($P);
        $dias = [];
        $x = new DateTime($periodo['ini']); $f = new DateTime($periodo['fim']);
        for (; $x <= $f; $x->modify('+1 day')) { $k = $x->format('Y-m-d'); $v = $porDia[$k] ?? ['gasto' => 0, 'leads' => 0];
            $dias[] = ['d' => $k, 'gasto' => round($v['gasto'], 2), 'leads' => $v['leads'], 'cpl' => $v['leads'] > 0 ? round($v['gasto'] / $v['leads'], 2) : null]; }
        usort($camps, fn($a, $b) => $b['gasto'] <=> $a['gasto']);
        $d['ads'] = ['atual' => $A, 'anterior' => $P, 'tendencia' => ['gasto' => retro_var($A['gasto'], $P['gasto']), 'leads' => retro_var($A['leads'], $P['leads']), 'cpl' => retro_var($A['cpl'], $P['cpl']),
                      'ctr' => retro_var($A['ctr'], $P['ctr'])], 'por_dia' => $dias, 'campanhas' => array_slice($camps, 0, 12), 'erros' => $erros, 'conta_por_nome' => $vinculadaPorNome];
    } elseif ($erros) $d['ads'] = ['erros' => $erros];

    $d['organico'] = retro_organico($clientId, $periodo['ini'], $periodo['fim']);
    $d['funil'] = retro_funil($clientId);
    if (!empty($d['ads']['atual'])) $d['mercado'] = retro_mercado($segmento, $d['ads']['atual'], $clientId);
    $d['alertas'] = retro_alertas($d);
    return $d;
}

/** Funil do CRM do cliente: só existe se alguém alimentou (webhook do CRM → pd_retro_funil). Hoje: null. */
function retro_funil($clientId) {
    $r = pd_supa('/rest/v1/pd_retro_funil?client_id=eq.' . urlencode($clientId) . '&order=criado_em.desc&limit=1&select=dados');
    return ($r['code'] === 200 && !empty($r['body'][0]['dados'])) ? $r['body'][0]['dados'] : null;
}

/** Percentil simples. */
function retro_pct($v, $p) { sort($v); $n = count($v); if (!$n) return null; $i = ($n - 1) * $p; $lo = (int)floor($i); $hi = (int)ceil($i); return $v[$lo] + ($v[$hi] - $v[$lo]) * ($i - $lo); }

/** Comparativo de mercado: faixa pública + média da nossa carteira (quando há 3+ clientes do mesmo segmento). */
function retro_mercado($segmento, $atual, $clientId) {
    $pub = pd_supa('/rest/v1/pd_mercado?segmento=eq.' . urlencode($segmento) . '&base=eq.publico&select=metrica,p25,mediana,p75,fonte');
    $P = []; foreach ((array)($pub['body'] ?? []) as $r) if (is_array($r)) $P[$r['metrica']] = $r;
    // nossa carteira: os últimos snapshots (8 semanas) de OUTROS clientes do mesmo segmento
    $plat = []; $nCli = 0;
    $cfgs = pd_supa('/rest/v1/pd_retro_config?segmento=eq.' . urlencode($segmento) . '&client_id=neq.' . urlencode($clientId) . '&select=client_id');
    $ids = array_values(array_filter(array_map(fn($c) => $c['client_id'] ?? null, (array)($cfgs['body'] ?? []))));
    if ($ids) {
        $desde = (new DateTime('-56 days'))->format('Y-m-d');
        $sn = pd_supa('/rest/v1/pd_retro_snaps?client_id=in.(' . implode(',', $ids) . ')&tipo=eq.semanal&fim=gte.' . $desde . '&select=client_id,dados&limit=400');
        $vals = ['cpl' => [], 'cpm' => [], 'ctr' => [], 'freq' => []]; $vistos = [];
        foreach ((array)($sn['body'] ?? []) as $s) { $a = $s['dados']['ads']['atual'] ?? null; if (!$a) continue; $vistos[$s['client_id']] = 1;
            foreach ($vals as $k => $_) if (isset($a[$k]) && $a[$k] !== null && ($a['gasto'] ?? 0) > 0) $vals[$k][] = (float)$a[$k]; }
        $nCli = count($vistos);
        if ($nCli >= 3) foreach ($vals as $k => $v) if (count($v) >= 3) $plat[$k] = ['p25' => round(retro_pct($v, .25), 2), 'mediana' => round(retro_pct($v, .5), 2), 'p75' => round(retro_pct($v, .75), 2), 'n' => $nCli];
    }
    $out = ['segmento' => $segmento, 'rotulo_segmento' => RETRO_SEGMENTOS[$segmento] ?? $segmento, 'carteira_clientes' => $nCli, 'metricas' => []];
    foreach (RETRO_METRICAS as $k => [$rot, $un, $melhor]) {
        $v = $atual[$k] ?? null; if ($v === null) continue;
        $ref = $plat[$k] ?? ($P[$k] ?? null); if (!$ref) continue;
        $med = (float)$ref['mediana']; $p25 = (float)$ref['p25']; $p75 = (float)$ref['p75'];
        if ($melhor === 'menor') $pos = $v <= $p25 ? 'melhor' : ($v <= $p75 ? 'na_media' : 'pior');
        elseif ($melhor === 'maior') $pos = $v >= $p75 ? 'melhor' : ($v >= $p25 ? 'na_media' : 'pior');
        else $pos = ($v >= $p25 && $v <= $p75) ? 'na_media' : ($v > $p75 ? 'pior' : 'na_media');
        $out['metricas'][$k] = ['rotulo' => $rot, 'unidade' => $un, 'valor' => $v, 'delta_pct' => $med > 0 ? round(($v - $med) / $med * 100, 1) : null, 'pos' => $pos,
            'ref_usada' => isset($plat[$k]) ? 'plataforma' : 'publico',
            'publico' => isset($P[$k]) ? ['p25' => (float)$P[$k]['p25'], 'mediana' => (float)$P[$k]['mediana'], 'p75' => (float)$P[$k]['p75'], 'fonte' => $P[$k]['fonte'] ?? null] : null,
            'plataforma' => $plat[$k] ?? null, 'melhor' => $melhor];
    }
    return $out;
}

/** Alertas objetivos, sem IA: o que o gestor precisa ver primeiro. */
function retro_alertas($d) {
    $al = []; $a = $d['ads'] ?? null; if (!$a || empty($a['atual'])) return $al;
    $dias = $a['por_dia']; $semLead = 0; $gastoSem = 0;
    for ($i = count($dias) - 1; $i >= 0; $i--) { if ($dias[$i]['leads'] > 0) break; if ($dias[$i]['gasto'] > 0) { $semLead++; $gastoSem += $dias[$i]['gasto']; } }
    if ($semLead >= 2) $al[] = ['tipo' => 'alerta', 'texto' => "Últimos $semLead dias sem lead, com R$ " . number_format($gastoSem, 2, ',', '.') . ' investidos sem retorno.'];
    $t = $a['tendencia'];
    if (($t['cpl'] ?? 0) > 30) $al[] = ['tipo' => 'alerta', 'texto' => 'CPL subiu ' . round($t['cpl']) . '% vs. a semana anterior.'];
    if (($t['leads'] ?? 0) < -30) $al[] = ['tipo' => 'alerta', 'texto' => 'Leads caíram ' . abs(round($t['leads'])) . '% vs. a semana anterior.'];
    foreach ($a['campanhas'] as $c) if ($c['gasto'] >= 100 && $c['leads'] == 0) { $al[] = ['tipo' => 'alerta', 'texto' => 'Campanha "' . $c['nome'] . '": R$ ' . number_format($c['gasto'], 2, ',', '.') . ' sem nenhum lead.']; break; }
    $melhor = null; foreach ($a['campanhas'] as $c) if ($c['leads'] >= 2 && $c['cpl'] !== null && ($melhor === null || $c['cpl'] < $melhor['cpl'])) $melhor = $c;
    if ($melhor) $al[] = ['tipo' => 'destaque', 'texto' => 'Melhor CPL da semana: "' . $melhor['nome'] . '" a R$ ' . number_format($melhor['cpl'], 2, ',', '.') . ' (' . $melhor['leads'] . ' leads).'];
    if (!empty($a['atual']['freq']) && $a['atual']['freq'] > 3) $al[] = ['tipo' => 'alerta', 'texto' => 'Frequência alta (' . number_format($a['atual']['freq'], 2, ',', '.') . '): o público está saturando.'];
    return $al;
}

/** A leitura da IA: comparando com a semana anterior e o mercado. Devolve texto (bullets) ou null. */
function retro_analise($d) {
    if (!defined('SD_ANTHROPIC_KEY') || !SD_ANTHROPIC_KEY) return null;
    $sys = 'Você é o gestor de tráfego e social sênior de uma agência de branding do mercado imobiliário brasileiro. '
        . 'Recebe a retrospectiva semanal de um cliente (anúncios, orgânico, comparação com a semana anterior e com o mercado) e escreve a LEITURA para o cliente. '
        . 'Formato: 1 frase de visão geral; depois 3 a 4 linhas começando com "• ", cada uma com um achado e a ação prática. Cite números. '
        . 'Compare sempre com o mercado quando houver faixa. Pt-BR, direto, sem markdown pesado, sem emoji, no máximo 900 caracteres.';
    $base = defined('PD_ANTHROPIC_BASE') ? rtrim(PD_ANTHROPIC_BASE, '/') : 'https://api.anthropic.com';
    $enxuto = $d; unset($enxuto['alertas']);
    $body = ['model' => defined('SD_ANALISTA_MODEL') ? SD_ANALISTA_MODEL : 'claude-sonnet-4-5', 'max_tokens' => 900, 'system' => $sys,
        'messages' => [['role' => 'user', 'content' => json_encode($enxuto, JSON_UNESCAPED_UNICODE)]]];
    $ch = curl_init($base . '/v1/messages');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90,
        CURLOPT_HTTPHEADER => ['x-api-key: ' . SD_ANTHROPIC_KEY, 'anthropic-version: 2023-06-01', 'content-type: application/json'], CURLOPT_POSTFIELDS => json_encode($body)]);
    $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code !== 200) { error_log('retro analise HTTP ' . $code); return null; }
    $j = json_decode((string)$raw, true); $t = '';
    foreach ((array)($j['content'] ?? []) as $b) if (($b['type'] ?? '') === 'text') $t .= $b['text'];
    return trim($t) ?: null;
}

/** Gera (ou refaz) o snapshot do cliente para a janela de 7 dias que termina ontem. */
function retro_gerar($clientId, $tipo = 'semanal', $comIA = true) {
    $per = retro_periodo();
    $d = retro_coletar($clientId, $per);
    $d['analise'] = $comIA ? retro_analise($d) : null;
    $r = pd_supa('/rest/v1/pd_retro_snaps?on_conflict=client_id,tipo,fim', 'POST',
        ['client_id' => $clientId, 'tipo' => $tipo, 'ini' => $per['ini'], 'fim' => $per['fim'], 'dados' => $d],
        ['Prefer: resolution=merge-duplicates,return=representation']);
    if ($r['code'] >= 300) { error_log('retro snap falhou: ' . json_encode($r)); return null; }
    return $r['body'][0] ?? null;
}

// ----------------------------------------------------------- texto do zap (igual ao que o mercado entrega, mais o comparativo)
function retro_brl($v) { return 'R$ ' . number_format((float)$v, 2, ',', '.'); }
function retro_n($v) { return number_format((float)$v, 0, ',', '.'); }
function retro_seta($p) { return $p === null ? '' : ($p > 0 ? '+' : '') . round($p) . '%'; }

function retro_texto_zap($d, $linkBI, $linkPDF) {
    $per = $d['periodo']; $di = date('d', strtotime($per['ini'])); $df = date('d/m/Y', strtotime($per['fim']));
    $t = "📊 *RETROSPECTIVA SEMANAL — {$d['cliente']}*\n$di a $df\n";
    if (!empty($d['ads']['atual'])) {
        $a = $d['ads']['atual']; $tn = $d['ads']['tendencia'];
        $t .= "\n*Investimento e volume*\n• Meta Ads: " . retro_brl($a['gasto']) . ' | ' . $a['leads'] . ' leads | CPL ' . ($a['cpl'] !== null ? retro_brl($a['cpl']) : '—') . "\n";
        $t .= '• Vs. semana anterior: Investimento ' . retro_seta($tn['gasto']) . ' | Leads ' . retro_seta($tn['leads']) . ' | CPL ' . retro_seta($tn['cpl']) . "\n";
        $t .= "\n*Métricas consolidadas*\n• " . retro_n($a['cliques']) . ' cliques | ' . retro_n($a['impressoes']) . ' impressões | CTR ' . number_format((float)$a['ctr'], 1, ',', '.') . '% | CPM ' . retro_brl($a['cpm']) . "\n";
        $t .= '• Frequência ' . number_format((float)$a['freq'], 2, ',', '.') . ' | Alcance ' . retro_n($a['alcance']) . "\n";
    }
    if (!empty($d['mercado']['metricas'])) {
        $t .= "\n*Você vs. mercado (" . $d['mercado']['rotulo_segmento'] . ")*\n";
        $ic = ['melhor' => '🟢 melhor que o mercado', 'na_media' => '🟡 na média', 'pior' => '🔴 acima do ideal'];
        foreach ($d['mercado']['metricas'] as $k => $m) {
            if ($k === 'freq') continue;
            $v = $m['unidade'] === 'brl' ? retro_brl($m['valor']) : number_format($m['valor'], 2, ',', '.') . '%';
            $t .= '• ' . strtoupper($k) . ' ' . $v . ' — ' . $ic[$m['pos']] . ($m['delta_pct'] !== null ? ' (' . retro_seta($m['delta_pct']) . ' vs. mediana)' : '') . "\n";
        }
    }
    if (!empty($d['organico'])) { $o = $d['organico'];
        $t .= "\n*Instagram orgânico*\n• Alcance " . retro_n($o['alcance']) . ' | Interações ' . retro_n($o['interacoes']) . ' | Seguidores ' . retro_n($o['seguidores']) . (isset($o['posts']) ? ' | ' . $o['posts'] . ' posts' : '') . "\n"; }
    if (!empty($d['alertas'])) { $t .= "\n*Atenção*\n"; foreach (array_slice($d['alertas'], 0, 4) as $al) $t .= ($al['tipo'] === 'alerta' ? '⚠️ ' : '✅ ') . $al['texto'] . "\n"; }
    if (!empty($d['analise'])) $t .= "\n*Leitura da semana*\n" . trim($d['analise']) . "\n";
    $t .= "\n📈 Veja tudo, com o comparativo de mercado:\n$linkBI\n\n📄 PDF para baixar:\n$linkPDF";
    return $t;
}

function retro_base() { return rtrim(defined('PD_BASE_URL') ? PD_BASE_URL : 'https://pontodot.ai', '/'); }
function retro_links($token, $fim) {
    $b = retro_base();
    return ['bi' => $b . '/r/' . $token, 'pdf' => $b . '/retro-pdf.php?t=' . $token . '&fim=' . $fim];
}

// ----------------------------------------------------------- envio e rotina
require_once __DIR__ . '/pd-lib.php';

/** Manda o texto pro número, em texto livre se a pessoa falou com a gente nas últimas 24 h, senão pelo modelo. */
function retro_enviar_zap($tel, $nome, $texto, $clientId, $ref) {
    $tel = pdZapNormaliza($tel); if ($tel === '') return [false, 'sem telefone'];
    if (!(defined('SD_ZAP_TOKEN') && SD_ZAP_TOKEN && defined('SD_ZAP_PHONE_ID') && SD_ZAP_PHONE_ID)) return [false, 'WhatsApp não configurado'];
    $jan = pd_supa('/rest/v1/pd_avisos_log?direcao=eq.entrada&para=eq.' . $tel . '&criado_em=gte.' . rawurlencode(gmdate('c', time() - 23 * 3600)) . '&select=id&limit=1');
    $livre = !empty($jan['body']);
    [$code, $raw] = pdZapEnviar($tel, $texto, $livre);
    $ok = $code === 200;
    pdAvisoLog('whatsapp', $tel, $nome, null, $texto, $ok ? 'enviado' : 'erro', $ok ? null : 'HTTP ' . $code . ' ' . mb_substr((string)$raw, 0, 200), 'retrospectiva', $ref, $clientId, $ok ? pdWamid($raw) : null);
    return [$ok, $ok ? ($livre ? 'texto livre' : 'modelo') : 'HTTP ' . $code];
}

/** Gera o relatório do cliente e manda: aos admins e, se configurado, aos telefones do cliente. */
function retro_rodar($clientId, $soAdmin = null) {
    $snap = retro_gerar($clientId, 'semanal', true);
    if (!$snap) return ['ok' => false, 'msg' => 'não consegui gerar'];
    $cfg = pd_supa('/rest/v1/pd_retro_config?client_id=eq.' . urlencode($clientId) . '&select=*')['body'][0] ?? null;
    if (!$cfg) return ['ok' => false, 'msg' => 'cliente sem configuração'];
    $l = retro_links($cfg['token'], $snap['fim']);
    $texto = retro_texto_zap($snap['dados'], $l['bi'], $l['pdf']);
    $dest = [];
    $q = $soAdmin ? '&id=eq.' . urlencode($soAdmin) : '&role=eq.admin';
    $ad = pd_supa('/rest/v1/profiles?active=eq.true&zap=not.is.null' . $q . '&select=name,zap');
    foreach ((array)($ad['body'] ?? []) as $p) if (is_array($p)) $dest[] = [$p['zap'], $p['name']];
    if (!$soAdmin && !empty($cfg['enviar_cliente'])) foreach ((array)$cfg['destinatarios'] as $t) $dest[] = [$t, $snap['dados']['cliente']];
    $env = 0; $falhas = [];
    foreach ($dest as [$tel, $nome]) {
        [$ok, $m] = retro_enviar_zap($tel, $nome, $texto, $clientId, 'retro:' . $clientId . ':' . $snap['fim'] . ':' . preg_replace('/\D/', '', $tel));
        $ok ? $env++ : $falhas[] = $nome . ': ' . $m;
    }
    pd_supa('/rest/v1/pd_retro_config?client_id=eq.' . urlencode($clientId), 'PATCH', ['ultimo_zap_em' => gmdate('c')]);
    return ['ok' => true, 'enviados' => $env, 'falhas' => $falhas, 'link' => $l['bi']];
}

/** Relógio: na hora certa, gera e manda a semanal; e mantém o BI diário fresquinho. */
function retro_relogio() {
    $cs = pd_supa('/rest/v1/pd_retro_config?ativo=eq.true&select=*');
    if ($cs['code'] >= 300) return 'rode o SQL v110';
    $sp = retro_sp(); $dow = (int)$sp->format('N'); $h = (int)$sp->format('G'); $hoje = $sp->format('Y-m-d');
    $z = 0; $d = 0;
    foreach ((array)$cs['body'] as $c) {
        if (!is_array($c)) continue;
        $ult = !empty($c['ultimo_zap_em']) ? (new DateTime($c['ultimo_zap_em']))->setTimezone(new DateTimeZone('America/Sao_Paulo'))->format('Y-m-d') : '';
        if ($dow === (int)$c['zap_dia'] && $h >= (int)$c['zap_hora'] && $h < (int)$c['zap_hora'] + 6 && $ult !== $hoje) { retro_rodar($c['client_id']); $z++; if ($z >= 3) break; continue; }
        if (($c['bi_freq'] ?? '') === 'diario' && $h >= 7 && $h < 10) {
            $ja = pd_supa('/rest/v1/pd_retro_snaps?client_id=eq.' . urlencode($c['client_id']) . '&tipo=eq.diario&fim=eq.' . retro_periodo()['fim'] . '&select=id&limit=1');
            if (empty($ja['body'])) { retro_gerar($c['client_id'], 'diario', false); $d++; }
        }
    }
    return "zaps $z, diários $d";
}
