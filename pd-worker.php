<?php
// =====================================================================
// Pontodot — O RELÓGIO DA CASA (cron do cPanel, a cada 5 minutos)
//
//   /pd-worker.php?key=SD_CRON_KEY
//
// O que ele faz, nesta ordem:
//   1. ACORDA AS DERIVADAS — tarefa-mãe terminou? as filhas do maestro
//      com produção de GPT ganham pasta, briefing e play sozinhas.
//   2. ESTEIRAS SEMANAIS — rotina programada uma vez (dia + hora) vira
//      tarefa nova com o GPT acionado, toda semana, sem ninguém pedir.
//   3. CÓPIA PRO DRIVE — lote todo aprovado: as artes que moram no
//      Supabase ganham cópia no Drive (conferida; nada se apaga aqui).
//   4. FAXINA MENSAL — do dia 25 em diante, UM alarme pro gestor:
//      "bora dar um flush" (a limpeza só sai pelo botão, e só apaga o
//      que já tem cópia conferida no Drive).
//   5. ZAP — manda o que está na fila (API oficial, se configurada;
//      sem ela, vira aviso com a mensagem pronta pra mandar na mão).
//   6. RELATÓRIO DIÁRIO DE CAMPANHA — números da Meta + leitura
//      estratégica (com SD_ANTHROPIC_KEY no sd-config; sem ela, só os
//      números) por cliente com conta de anúncio vinculada.
//
// Config opcional no sd-config.php:
//   SD_ANTHROPIC_KEY   — liga a leitura estratégica do relatório
//   SD_ZAP_TOKEN / SD_ZAP_PHONE_ID — liga o envio real de WhatsApp
// =====================================================================

require_once __DIR__ . '/sd-auth.php';
require_once __DIR__ . '/push-lib.php';
require_once __DIR__ . '/pd-lib.php';
require_once __DIR__ . '/pd-site-lib.php';

if (!defined('SD_CRON_KEY') || SD_CRON_KEY === 'SUA_CRON_KEY_AQUI') { http_response_code(500); die('SD_CRON_KEY nao configurada.'); }
if (($_GET['key'] ?? '') !== SD_CRON_KEY) { http_response_code(403); die('Chave invalida.'); }
@set_time_limit(280);
require_once __DIR__ . '/pd-saude-lib.php'; pdBatimento('pd-worker');
header('Content-Type: text/plain; charset=utf-8');
echo "Pontodot — relógio da casa — " . date('c') . "\n" . str_repeat('-', 60) . "\n";

function agoraSP() { return new DateTime('now', new DateTimeZone('America/Sao_Paulo')); }

// =====================================================================
// 1) ACORDA AS DERIVADAS
// =====================================================================
function w_derivadas() {
    $r = sd_supabase('/rest/v1/tasks?status=eq.BRIEFING&tarefa_mae_id=not.is.null&prompt_ia=not.is.null'
        . '&select=id,tarefa_mae_id,prompt_ia,batata&limit=40');
    $filhas = array_values(array_filter((array)($r['body'] ?? []), fn($t) => ($t['batata'] ?? '') !== 'gpt'));
    if (!$filhas) return 0;

    // só as que nasceram do maestro (têm o evento do plano)
    $ids = array_map(fn($t) => $t['id'], $filhas);
    $ev = sd_supabase('/rest/v1/task_events?tipo=eq.debriefing&task_id=in.(' . implode(',', array_map('rawurlencode', $ids)) . ')&select=task_id');
    $doMaestro = array_flip(array_map(fn($e) => $e['task_id'], (array)($ev['body'] ?? [])));

    $maes = array_values(array_unique(array_map(fn($t) => $t['tarefa_mae_id'], $filhas)));
    $m = sd_supabase('/rest/v1/tasks?id=in.(' . implode(',', array_map('rawurlencode', $maes)) . ')&select=id,status');
    $prontas = [];
    foreach ((array)($m['body'] ?? []) as $x) if (($x['status'] ?? '') === 'ENTREGUE') $prontas[$x['id']] = true;

    $n = 0;
    foreach ($filhas as $t) {
        if ($n >= 3) break;                                    // devagar: 3 por volta
        if (!isset($doMaestro[$t['id']]) || !isset($prontas[$t['tarefa_mae_id']])) continue;
        if (td_get($t['id'])) continue;                        // alguém já começou na mão
        try {
            pdProduzir($t['id'], (string)$t['prompt_ia'], 'Maestro');
            $tt = tarefa($t['id']);
            if ($tt) avisarGestor($tt, '▶ Derivada acordou — ' . $t['id'],
                'A tarefa-mãe terminou; o GPT já recebeu o chamado desta.', 'drive_entrega');
            $n++;
        } catch (Exception $e) {
            pdEvento($t['id'], 'nota', 'Não consegui acordar a derivada', $e->getMessage());
            pdBatata($t['id'], 'humano');
            pdAlarme('revisar_entrega', 'Derivada travou — ' . $t['id'], $e->getMessage(), $t['id'], 'task:' . $t['id']);
            $n++;
        }
    }
    return $n;
}

// =====================================================================
// 2) ESTEIRAS SEMANAIS
// =====================================================================
function w_esteiras() {
    $sp = agoraSP();
    $diaSemana = (int)$sp->format('N');                        // 1=segunda
    $hora = (int)$sp->format('G');
    $r = sd_supabase('/rest/v1/pd_esteiras?ativa=eq.true&select=*,clients(name)&limit=60');
    if ($r['code'] >= 300) return ['rodadas' => 0, 'sem_tabela' => true];
    $n = 0;
    foreach ((array)($r['body'] ?? []) as $e) {
        if ((int)$e['dia'] !== $diaSemana || $hora < (int)$e['hora']) continue;
        if (!empty($e['ultima_em']) && (time() - strtotime($e['ultima_em'])) < 5 * 86400) continue;   // já rodou esta semana
        try {
            $nid = sd_supabase('/rest/v1/rpc/next_task_id', 'POST', (object)[]);
            $taskId = is_string($nid['body']) ? trim($nid['body'], '"') : ($nid['body'] ?? null);
            if ($nid['code'] >= 300 || !$taskId) throw new Exception('sem id novo');
            $resp = 'SERGIO';
            if (!empty($e['criado_por'])) {
                $p = sd_supabase('/rest/v1/profiles?id=eq.' . urlencode($e['criado_por']) . '&select=team_key,name');
                $resp = strtoupper($p['body'][0]['team_key'] ?? (explode(' ', $p['body'][0]['name'] ?? 'SERGIO')[0]));
            }
            $nova = ['id' => $taskId, 'action' => $e['nome'] . ' — semana de ' . $sp->format('d/m'),
                'status' => 'EM ANDAMENTO', 'resp' => $resp, 'project' => strtoupper(mb_substr($e['nome'], 0, 48)),
                'week' => 'ESTA SEMANA', 'client_id' => $e['client_id'],
                'prompt_ia' => $e['prompt'], 'prompt_ia_em' => date('c'),
                'date_int' => $sp->modify('+4 days')->format('Y-m-d')];
            $ins = sd_supabase('/rest/v1/tasks', 'POST', $nova);
            if ($ins['code'] >= 300) {                          // banco sem prompt_ia? tenta sem
                unset($nova['prompt_ia'], $nova['prompt_ia_em']);
                $ins = sd_supabase('/rest/v1/tasks', 'POST', $nova);
                if ($ins['code'] >= 300) throw new Exception('não criei a tarefa (HTTP ' . $ins['code'] . ')');
            }
            pdEvento($taskId, 'briefing', 'Esteira semanal: ' . $e['nome'],
                'Programada uma vez, roda toda semana.', 'Esteira', 'sistema');
            $extra = '';
            if (in_array($e['tipo'], ['site', 'misto'], true)) {
                $sc = sd_supabase('/rest/v1/pd_sites?client_id=eq.' . urlencode((string)$e['client_id']) . '&select=seo');
                $seo = $sc['body'][0]['seo'] ?? null;
                if (is_array($seo) && $seo) $extra = "\n\nESTRATÉGIA VIVA DE SEO (siga à risca; títulos 50-60, descrição 140-160, palavra-chave no início):\n"
                    . json_encode($seo, JSON_UNESCAPED_UNICODE);
            }
            pdProduzir($taskId, "ESTEIRA SEMANAL — " . $e['nome'] . "\nCliente: " . ($e['clients']['name'] ?? 'SD')
                . "\nSemana de " . $sp->format('d/m/Y') . "\n\n" . $e['prompt'] . $extra, 'Esteira');
            $t = tarefa($taskId);
            if ($t) avisarGestor($t, '🗓 Esteira rodou — ' . $taskId,
                ($e['clients']['name'] ?? '') . ' · ' . $e['nome'] . ' — o GPT já recebeu o chamado da semana.', 'drive_entrega');
            $n++;
        } catch (Exception $ex) {
            pdAlarme('outro', 'Esteira falhou — ' . $e['nome'], $ex->getMessage(), null, null);
        }
        sd_supabase('/rest/v1/pd_esteiras?id=eq.' . (int)$e['id'], 'PATCH', ['ultima_em' => date('c')]);
    }
    return ['rodadas' => $n];
}

// =====================================================================
// 3) CÓPIA PRO DRIVE (lote fechado) — nada é apagado aqui
// =====================================================================
function w_assina($id) { return substr(hash_hmac('sha256', 'arte:' . $id, SD_SECRET_KEY), 0, 24); }
function w_copias() {
    $r = sd_supabase('/rest/v1/approval_items?status=eq.aprovado&media=not.is.null&select=id,set_id,media&order=created_at.desc&limit=300');
    if ($r['code'] >= 300) return 0;
    $porSet = [];
    foreach ((array)($r['body'] ?? []) as $it) {
        $media = is_array($it['media']) ? $it['media'] : json_decode((string)$it['media'], true);
        if (!is_array($media)) continue;
        foreach ($media as $i => $m) {
            if (is_array($m) && empty($m['drive_id']) && strpos((string)($m['url'] ?? ''), '/storage/v1/object/public/aprovacao/') !== false) {
                $porSet[$it['set_id']][] = ['item' => $it['id'], 'idx' => $i, 'media' => $media, 'm' => $m];
                break;   // um por item por volta já anda
            }
        }
    }
    if (!$porSet) return 0;
    $feitos = 0;
    foreach ($porSet as $setId => $cands) {
        if ($feitos >= 10) break;
        // o lote precisa estar FECHADO: toda peça aprovada
        $st = sd_supabase('/rest/v1/approval_items?set_id=eq.' . urlencode($setId) . '&select=status');
        $sts = array_map(fn($x) => $x['status'] ?? '', (array)($st['body'] ?? []));
        if (!$sts || array_diff($sts, ['aprovado'])) continue;
        $cs = sd_supabase('/rest/v1/approval_sets?id=eq.' . urlencode($setId) . '&select=client_id,task_id,clients(name)');
        $cliente = $cs['body'][0]['clients']['name'] ?? 'SEM CLIENTE';
        foreach (array_slice($cands, 0, 5) as $c) {
            try {
                $g = ponte('aprov_puxar', ['arquivos' => [['ref' => 'w', 'url' => $c['m']['url'],
                    'nome' => $c['m']['name'] ?? 'arte', 'cliente' => $cliente, 'set_id' => $setId]]]);
                $mig = $g['migrados'][0] ?? null;
                if (!$mig || !empty($mig['erro']) || empty($mig['drive_id'])) continue;
                $id = $mig['drive_id']; $k = w_assina($id);
                $media = $c['media'];
                $media[$c['idx']]['url_supabase'] = $media[$c['idx']]['url'];
                $p = strpos($media[$c['idx']]['url'], '/public/aprovacao/');
                $media[$c['idx']]['path_supabase'] = $p === false ? '' : rawurldecode(substr($media[$c['idx']]['url'], $p + 18));
                $media[$c['idx']]['drive_id'] = $id;
                $media[$c['idx']]['url'] = '/arte.php?i=' . rawurlencode($id) . '&k=' . $k . '&w=1800';
                $media[$c['idx']]['thumb'] = '/arte.php?i=' . rawurlencode($id) . '&k=' . $k . '&w=600';
                $media[$c['idx']]['source'] = 'drive';
                sd_supabase('/rest/v1/approval_items?id=eq.' . urlencode($c['item']), 'PATCH', ['media' => $media]);
                $feitos++;
                $taskId = $cs['body'][0]['task_id'] ?? null;
                if ($taskId && $feitos === 1) pdEvento($taskId, 'site', 'Arte final copiada pro Drive',
                    'Lote fechado: as artes aprovadas ganharam cópia no acervo.', 'Robô', 'robo');
            } catch (Exception $e) { /* próxima volta tenta de novo */ }
        }
    }
    return $feitos;
}

// =====================================================================
// 4) FAXINA MENSAL (só o alarme — a limpeza é botão de gente)
// =====================================================================
function w_faxina() {
    $sp = agoraSP();
    if ((int)$sp->format('j') < 25) return false;
    $desde = $sp->format('Y-m-01');
    $r = sd_supabase('/rest/v1/pd_alarmes?tipo=eq.flush_mensal&criado_em=gte.' . $desde . '&select=id&limit=1');
    if (!empty($r['body'][0])) return false;
    pdAlarme('flush_mensal', 'Bora dar um flush nos arquivos do mês?',
        'Os lotes fechados já têm cópia conferida no Drive. A limpeza só apaga o que foi conferido — e só sai pelo botão.',
        null, '/app.html');
    return true;
}

// =====================================================================
// 5) ZAP — a fila
// =====================================================================

/**
 * Manda UMA mensagem pela Cloud API do WhatsApp.
 * 1) texto livre — só a Meta aceita se a pessoa falou com o número nas últimas 24h;
 * 2) se recusar por isso (erro 131047) e houver template configurado, manda o
 *    template aprovado, com o texto inteiro no parâmetro {{1}}.
 * Config (sd-config.php): SD_ZAP_TOKEN, SD_ZAP_PHONE_ID e, pro aviso que ABRE a
 * conversa, SD_ZAP_TEMPLATE (nome) + SD_ZAP_TEMPLATE_LANG (padrão pt_BR).
 * Template "hello_world" (o de teste da Meta) não tem parâmetro: vai sem.
 */
function pdZapEnviar($para, $texto) {
    $base = (defined('PD_META_BASE') ? PD_META_BASE : 'https://graph.facebook.com/') . 'v25.0';
    $url  = $base . '/' . rawurlencode(SD_ZAP_PHONE_ID) . '/messages';
    $post = function ($corpo) use ($url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . SD_ZAP_TOKEN, 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($corpo, JSON_UNESCAPED_UNICODE)]);
        $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        return [$code, $raw];
    };
    // Alerta de sistema é mensagem que ABRE conversa: a Meta pode aceitar o texto livre na hora (HTTP 200)
    // e só recusar depois, por webhook (131047, fora das 24h) — aí ninguém tenta o template. Por isso,
    // com template configurado, ele vai DIRETO; texto livre só quando não há template.
    $usaTemplate = defined('SD_ZAP_TEMPLATE') && SD_ZAP_TEMPLATE;
    if (!$usaTemplate) {
        return $post(['messaging_product' => 'whatsapp', 'to' => $para, 'type' => 'text',
            'text' => ['preview_url' => true, 'body' => mb_substr($texto, 0, 3900)]]);
    }
    $lang = defined('SD_ZAP_TEMPLATE_LANG') && SD_ZAP_TEMPLATE_LANG ? SD_ZAP_TEMPLATE_LANG : 'pt_BR';
    $tpl = ['name' => SD_ZAP_TEMPLATE, 'language' => ['code' => $lang]];
    if (SD_ZAP_TEMPLATE !== 'hello_world') {
        // parâmetro de template não pode ter quebra de linha nem sequência longa de espaços
        $param = trim(preg_replace('/\s*[\r\n\t]+\s*/u', ' · ', $texto));
        $param = preg_replace('/ {4,}/', '   ', $param);
        $tpl['components'] = [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => mb_substr($param, 0, 900)]]]];
    } else {
        $tpl['language'] = ['code' => 'en_US'];
    }
    [$code, $raw] = $post(['messaging_product' => 'whatsapp', 'to' => $para, 'type' => 'template', 'template' => $tpl]);
    return [$code, $raw];
}

function w_zap() {
    $r = sd_supabase('/rest/v1/pd_zap?status=eq.fila&select=*&order=criado_em.asc&limit=10');
    if ($r['code'] >= 300) return ['enviados' => 0, 'sem_tabela' => true];
    $tem = defined('SD_ZAP_TOKEN') && SD_ZAP_TOKEN && defined('SD_ZAP_PHONE_ID') && SD_ZAP_PHONE_ID;
    $enviados = 0; $manuais = 0;
    foreach ((array)($r['body'] ?? []) as $z) {
        $texto = trim($z['corpo'] . (!empty($z['link']) ? "\n" . $z['link'] : ''));
        if (!$tem) {
            sd_supabase('/rest/v1/pd_zap?id=eq.' . (int)$z['id'], 'PATCH', ['status' => 'manual']);
            $tel = preg_replace('/\D/', '', (string)$z['para']);
            pdAlarme('outro', 'Zap pra mandar na mão — ' . ($z['quem'] ?: $z['para']),
                mb_substr($texto, 0, 180), $z['task_id'] ?? null,
                'https://wa.me/' . $tel . '?text=' . rawurlencode(mb_substr($texto, 0, 900)), true);
            $manuais++;
            pdAvisoLog('whatsapp', $z['para'], $z['quem'] ?? null, null, $texto, 'manual', 'sem API de WhatsApp configurada', empty($z['client_id']) ? 'equipe' : 'cliente', 'z' . $z['id'], $z['client_id'] ?? null);
            if (empty($z['client_id'])) pdEmailEspelho($tel, $texto, 'z' . $z['id']);
            continue;
        }
        [$code, $raw] = pdZapEnviar(preg_replace('/\D/', '', (string)$z['para']), $texto);
        if ($code === 200) {
            sd_supabase('/rest/v1/pd_zap?id=eq.' . (int)$z['id'], 'PATCH', ['status' => 'enviado', 'enviado_em' => date('c')]);
            if (!empty($z['task_id'])) pdEvento($z['task_id'], 'zap', 'Zap enviado', ($z['quem'] ?: $z['para']) . ': ' . mb_substr($z['corpo'], 0, 120), 'Robô', 'robo');
            $enviados++;
            $dg = preg_replace('/\D/', '', (string)$z['para']);
            pdAvisoLog('whatsapp', $z['para'], $z['quem'] ?? null, null, $texto, 'enviado', null, empty($z['client_id']) ? 'equipe' : 'cliente', 'z' . $z['id'], $z['client_id'] ?? null);
            if (empty($z['client_id'])) pdEmailEspelho($dg, $texto, 'z' . $z['id']);
        } else {
            $t = (int)($z['tentativas'] ?? 0) + 1;
            if ($t >= 3) {
                pdAvisoLog('whatsapp', $z['para'], $z['quem'] ?? null, null, $texto, 'erro', 'HTTP ' . $code . ' ' . mb_substr((string)$raw, 0, 200), empty($z['client_id']) ? 'equipe' : 'cliente', 'z' . $z['id'], $z['client_id'] ?? null);
                if (empty($z['client_id'])) pdEmailEspelho(preg_replace('/\D/', '', (string)$z['para']), $texto, 'z' . $z['id']);
            }
            sd_supabase('/rest/v1/pd_zap?id=eq.' . (int)$z['id'], 'PATCH',
                ['tentativas' => $t, 'status' => $t >= 3 ? 'erro' : 'fila', 'erro' => 'HTTP ' . $code . ' ' . mb_substr((string)$raw, 0, 300)]);
        }
    }
    return ['enviados' => $enviados, 'manuais' => $manuais];
}

// =====================================================================
// 8) FEED DA CASA — uma imagem da pasta dos personagens a cada 2 dias
// =====================================================================
function w_feed_casa() {
    if (!defined('PD_PASTA_PERSONAGENS') || PD_PASTA_PERSONAGENS === '' || !defined('SD_PONTE_URL') || !defined('SD_PONTE_KEY')) return 'sem pasta/ponte';
    $u = sd_supabase('/rest/v1/posts?body=like.' . rawurlencode('🏠 Da casa%') . '&select=created_at&order=created_at.desc&limit=1');
    if ($u['code'] >= 300) return 'erro posts';
    $ult = $u['body'][0]['created_at'] ?? null;
    if ($ult && (time() - strtotime($ult)) < 2 * 86400 - 600) return 'cedo';
    if ((int)agoraSP()->format('G') < 9) return 'cedo (antes das 9h)';
    $ch = curl_init(SD_PONTE_URL);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode(['acao' => 'listar', 'folderId' => PD_PASTA_PERSONAGENS, 'key' => SD_PONTE_KEY]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 70]);
    $j = json_decode((string)curl_exec($ch), true); curl_close($ch);
    if (!is_array($j) || empty($j['ok'])) return 'ponte fora';
    $cand = [];
    foreach ((array)($j['arquivos'] ?? []) as $f) {
        if (!preg_match('#^image/(jpeg|png|webp)$#', (string)($f['mime'] ?? '')) || (int)($f['size'] ?? 0) > 25000000) continue;
        $path = (string)($f['path'] ?? ($f['name'] ?? '')); $base = strtolower(basename($path));
        if (preg_match('/avatar/', $base) || !preg_match('/vida|nascimento|cena|fundo|mobile|desktop/', strtolower($path))) continue;
        $id = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($f['id'] ?? '')); if ($id === '') continue;
        $cand[$id] = $path;
    }
    if (!$cand) return 'sem imagens';
    $ja = sd_supabase('/rest/v1/posts?image_url=like.' . rawurlencode('%arte.php?i=%') . '&select=image_url&limit=500');
    $usadas = '';
    foreach ((array)($ja['body'] ?? []) as $p) $usadas .= ' ' . $p['image_url'];
    $livres = array_filter($cand, fn($p, $id) => strpos($usadas, 'i=' . $id . '&') === false, ARRAY_FILTER_USE_BOTH);
    if (!$livres) $livres = $cand;   // acabou o estoque: recomeça
    $ids = array_keys($livres); $id = $ids[array_rand($ids)];
    $quem = trim(preg_replace('/^\d+[\s_\-]*/', '', explode('/', $livres[$id])[0]));
    $adm = sd_supabase('/rest/v1/profiles?role=eq.admin&active=eq.true&select=id&order=created_at.asc&limit=1');
    $uid = $adm['body'][0]['id'] ?? null; if (!$uid) return 'sem admin';
    $url = '/arte.php?i=' . $id . '&k=' . w_assina($id) . '&w=1200';
    $r = sd_supabase('/rest/v1/posts', 'POST', ['user_id' => $uid, 'body' => '🏠 Da casa' . ($quem !== '' ? ' · ' . $quem : ''), 'image_url' => $url, 'pinned' => false]);
    return $r['code'] < 300 ? 'publicado' : 'erro ' . $r['code'];
}

// =====================================================================
// 9) ALUNOS DE CATUM reagem aos posts HUMANOS do feed (curtem e comentam)
//    Uma reação nova por post a cada rodada; teto de 6 curtidas e 3 comentários.
// =====================================================================
function w_alunos() {
    $u = @json_decode((string)@file_get_contents(__DIR__ . '/pd/universo.json'), true);
    $chars = []; foreach ((array)($u['characters'] ?? []) as $c) if (!empty($c['id'])) $chars[$c['id']] = $c;
    if (!$chars) return 'sem universo';
    $desde = gmdate('c', time() - 7 * 86400);
    $p = sd_supabase('/rest/v1/posts?created_at=gte.' . rawurlencode($desde) . '&body=not.like.' . rawurlencode('🏠 Da casa%') . '&select=id,body,created_at&order=created_at.desc&limit=15');
    if ($p['code'] >= 300) return 'erro posts';
    $feitas = 0; $chamadas = 0;
    foreach ((array)$p['body'] as $post) {
        if (time() - strtotime($post['created_at']) < 600) continue;          // espera 10 min: ninguém reage no ato
        $r = sd_supabase('/rest/v1/pd_post_reacoes?post_id=eq.' . $post['id'] . '&select=aluno,tipo');
        if ($r['code'] >= 300) return 'rode o SQL v98';
        $cur = 0; $com = 0; $ja = [];
        foreach ((array)$r['body'] as $x) { $ja[$x['aluno']] = ($ja[$x['aluno']] ?? []); $ja[$x['aluno']][] = $x['tipo']; if ($x['tipo'] === 'curtida') $cur++; else $com++; }
        if ($cur >= 6 && $com >= 3) continue;
        if (mt_rand(1, 100) > 55) continue;                                    // ritmo humano: nem toda rodada
        $livres = array_values(array_diff(array_keys($chars), array_keys($ja)));
        if (!$livres) continue;
        $al = $livres[array_rand($livres)];
        $comentar = $com < 3 && $chamadas < 3 && defined('SD_ANTHROPIC_KEY') && SD_ANTHROPIC_KEY && mt_rand(1, 100) <= 40;
        $texto = null;
        if ($comentar) {
            $c = $chars[$al]; $chamadas++;
            $sys = 'Você é ' . $c['name'] . ', ' . ($c['profile_title'] ?? 'aluno') . ' da escola de Catum (um mundo fictício de IAs). ' . ($c['bio'] ?? '')
                . ' Comente, em pt-BR, no máximo 20 palavras, de forma calorosa e específica, um post de uma pessoa da equipe de uma agência. Sem aspas, sem hashtags, no máximo um emoji.';
            $body = ['model' => defined('SD_ALUNOS_MODEL') ? SD_ALUNOS_MODEL : 'claude-haiku-4-5', 'max_tokens' => 120, 'system' => $sys,
                'messages' => [['role' => 'user', 'content' => "Post:\n" . mb_substr((string)$post['body'], 0, 600)]]];
            $base = defined('PD_ANTHROPIC_BASE') ? rtrim(PD_ANTHROPIC_BASE, '/') : 'https://api.anthropic.com';
            $ch = curl_init($base . '/v1/messages');
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40,
                CURLOPT_HTTPHEADER => ['x-api-key: ' . SD_ANTHROPIC_KEY, 'anthropic-version: 2023-06-01', 'content-type: application/json'],
                CURLOPT_POSTFIELDS => json_encode($body)]);
            $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
            $j = json_decode((string)$raw, true);
            if ($code === 200) foreach ((array)($j['content'] ?? []) as $b) if (($b['type'] ?? '') === 'text') $texto .= $b['text'];
            $texto = $texto ? trim(trim($texto), "\"'“” ") : null;
            if (!$texto) $comentar = false;
        }
        if (!$comentar && $cur >= 6) continue;
        $res = sd_supabase('/rest/v1/pd_post_reacoes', 'POST', ['post_id' => $post['id'], 'aluno' => $al,
            'tipo' => $comentar ? 'comentario' : 'curtida', 'texto' => $comentar ? mb_substr($texto, 0, 280) : null]);
        if ($res['code'] < 300) $feitas++;
    }
    return $feitas . ' reação(ões)';
}

// =====================================================================
// 6) RELATÓRIO DIÁRIO DE CAMPANHA
// =====================================================================
function w_relatorios() {
    $sp = agoraSP();
    if ((int)$sp->format('G') < 7) return 0;
    $hoje = $sp->format('Y-m-d');
    $contas = sd_supabase('/rest/v1/meta_ad_accounts?client_id=not.is.null&select=id,nome,client_id,clients(name)');
    if ($contas['code'] >= 300 || empty($contas['body'])) return 0;
    if (!is_file(__DIR__ . '/meta-ads-lib.php')) return 0;
    require_once __DIR__ . '/meta-ads-lib.php';
    $tk = sd_supabase('/rest/v1/meta_user_tokens?select=access_token&order=atualizado_em.desc&limit=1');
    $token = $tk['body'][0]['access_token'] ?? null;
    if (!$token) return 0;

    $porCliente = [];
    foreach ((array)$contas['body'] as $c) $porCliente[$c['client_id']][] = $c;
    $feitos = 0;
    foreach ($porCliente as $clientId => $cs) {
        if ($feitos >= 3) break;                                // 3 clientes por volta
        $ja = sd_supabase('/rest/v1/pd_relatorios?client_id=eq.' . urlencode($clientId) . '&dia=eq.' . $hoje . '&select=id&limit=1');
        if (!empty($ja['body'][0])) continue;
        $nomeCli = $cs[0]['clients']['name'] ?? 'Cliente';
        $corpo = ['contas' => []];
        foreach ($cs as $c) {
            foreach ([['ontem', 'yesterday'], ['7d', 'last_7d']] as [$rot, $preset]) {
                $r = pd_meta('GET', '/' . $c['id'] . '/insights',
                    ['date_preset' => $preset, 'fields' => 'spend,reach,impressions,clicks,actions'], $token, 25);
                $corpo['contas'][$c['id']][$rot] = $r['code'] === 200 ? pd_numeros($r['body']['data'][0] ?? []) : ['erro' => pd_meta_erro($r)];
            }
            $camp = pd_meta('GET', '/' . $c['id'] . '/insights',
                ['date_preset' => 'yesterday', 'level' => 'campaign', 'fields' => 'campaign_name,spend,actions'], $token, 25);
            if ($camp['code'] === 200) foreach ((array)($camp['body']['data'] ?? []) as $cc) {
                $corpo['contas'][$c['id']]['campanhas'][] = ['nome' => $cc['campaign_name'] ?? '?',
                    'gasto' => (float)($cc['spend'] ?? 0), 'leads' => pd_leads_de($cc['actions'] ?? [])];
            }
        }
        $leads = sd_supabase('/rest/v1/meta_leads?client_id=eq.' . urlencode($clientId)
            . '&recebido_em=gte.' . $sp->modify('-1 day')->format('Y-m-d') . '&select=id');
        $sp->modify('+1 day');
        $corpo['leads_24h'] = count((array)($leads['body'] ?? []));

        $analise = null;
        if (defined('SD_ANTHROPIC_KEY') && SD_ANTHROPIC_KEY) {
            $analise = w_analisar($nomeCli, $corpo);
        }
        sd_supabase('/rest/v1/pd_relatorios', 'POST', ['client_id' => $clientId, 'dia' => $hoje,
            'corpo' => $corpo, 'analise' => $analise]);
        $gasto = 0; foreach ($corpo['contas'] as $x) $gasto += (float)($x['ontem']['gasto'] ?? 0);
        pub_avisa_relatorio($nomeCli, 'R$ ' . number_format($gasto, 2, ',', '.') . ' ontem · ' . $corpo['leads_24h'] . ' lead(s) nas últimas 24h'
            . ($analise ? ' · com leitura estratégica' : ''), $clientId);
        $feitos++;
    }
    return $feitos;
}
function pub_avisa_relatorio($nomeCli, $resumo, $clientId) {
    $r = sd_supabase('/rest/v1/profiles?role=in.(admin,team)&active=eq.true&select=id');
    foreach ((array)($r['body'] ?? []) as $p) {
        sd_supabase('/rest/v1/notifications', 'POST', ['user_id' => $p['id'], 'type' => 'relatorio_campanha',
            'title' => '📊 Campanhas · ' . $nomeCli, 'body' => $resumo, 'link' => null]);
        @sd_push_usuario($p['id'], '📊 Campanhas · ' . $nomeCli, $resumo, '/index.html#relatorios', 'pd-relatorio');
    }
}
function w_analisar($cliente, $corpo) {
    $sys = 'Você é o gestor de tráfego sênior de uma agência de branding do mercado imobiliário brasileiro. '
        . 'Recebe os números do dia das campanhas de um cliente e devolve a LEITURA pro gestor humano: '
        . 'o que os números dizem, o que fazer hoje, e um olhar de mercado (segmento do cliente, sazonalidade, '
        . 'comportamento de lead imobiliário). Pesquise na web quando ajudar a ancorar o cenário. '
        . 'Responda em pt-BR, 5 a 8 frases curtas, direto, sem enrolação, sem markdown pesado.';
    $body = ['model' => defined('SD_ANALISTA_MODEL') ? SD_ANALISTA_MODEL : 'claude-sonnet-4-5',
        'max_tokens' => 1200, 'system' => $sys,
        'tools' => [['type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => 3]],
        'messages' => [['role' => 'user', 'content' => 'Cliente: ' . $cliente . "\nNúmeros de ontem e 7 dias:\n"
            . json_encode($corpo, JSON_UNESCAPED_UNICODE)]]];
    $base = defined('PD_ANTHROPIC_BASE') ? rtrim(PD_ANTHROPIC_BASE, '/') : 'https://api.anthropic.com';
    $ch = curl_init($base . '/v1/messages');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120,
        CURLOPT_HTTPHEADER => ['x-api-key: ' . SD_ANTHROPIC_KEY, 'anthropic-version: 2023-06-01', 'content-type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($body)]);
    $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code !== 200) { error_log('Pontodot relatorio - anthropic HTTP ' . $code); return null; }
    $j = json_decode((string)$raw, true);
    $txt = '';
    foreach ((array)($j['content'] ?? []) as $b) if (($b['type'] ?? '') === 'text') $txt .= $b['text'];
    return trim($txt) ?: null;
}

// =====================================================================
// 7) BLOG E CAPA PRO SITE DO CLIENTE
// Peça kind=blog/capa_site APROVADA, lote visível pro cliente, cliente
// com site configurado e ativo → publica (WP agora; html/bloco servem
// pelo pd-site-feed). O mesmo caminho da aprovação do social.
// =====================================================================
function w_sites() {
    $sites = sd_supabase('/rest/v1/pd_sites?ativo=eq.true&select=client_id,tipo,url');
    if ($sites['code'] >= 300 || empty($sites['body'])) return 0;
    $porCliente = [];
    foreach ((array)$sites['body'] as $x) $porCliente[$x['client_id']] = $x;

    $it = sd_supabase('/rest/v1/approval_items?status=eq.aprovado&kind=in.(blog,capa_site)'
        . '&select=id,set_id,kind,title,caption,hashtags,media,publish_at&order=created_at.desc&limit=60');
    if ($it['code'] >= 300 || empty($it['body'])) return 0;

    // já publicados ficam de fora
    $ids = array_map(fn($i) => $i['id'], (array)$it['body']);
    $ja = sd_supabase('/rest/v1/pd_site_posts?item_id=in.(' . implode(',', $ids) . ')&select=item_id,status');
    $feito = [];
    foreach ((array)($ja['body'] ?? []) as $x) if ($x['status'] === 'publicado') $feito[$x['item_id']] = true;

    $n = 0;
    foreach ((array)$it['body'] as $i) {
        if ($n >= 5) break;
        if (isset($feito[$i['id']])) continue;
        if (!empty($i['publish_at']) && pd_instante_site($i['publish_at']) > time()) continue;   // ainda não é a hora
        $st = sd_supabase('/rest/v1/approval_sets?id=eq.' . urlencode($i['set_id']) . '&select=client_id,task_id,interno');
        $set = $st['body'][0] ?? null;
        if (!$set || !empty($set['interno'])) continue;                 // cliente ainda nem viu
        $site = $porCliente[$set['client_id']] ?? null;
        if (!$site || ($site['tipo'] ?? '') !== 'wp') continue;         // html/bloco servem pelo feed, sem empurrar

        $media = is_array($i['media']) ? $i['media'] : json_decode((string)$i['media'], true);
        $img = null;
        foreach ((array)$media as $m) if (is_array($m) && !empty($m['url'])) { $img = $m['url']; break; }
        try {
            $r = pdWPPublicar($set['client_id'], [
                'titulo' => $i['title'] ?: 'Post', 'slug' => null,
                'html' => pdTextoHtml(trim(($i['caption'] ?? '') . "\n\n" . ($i['hashtags'] ?? ''))),
                'resumo' => mb_substr(trim((string)($i['caption'] ?? '')), 0, 180),
                'imagem_url' => $img, 'agendar_para' => null,
            ]);
            sd_supabase('/rest/v1/pd_site_posts', 'POST', ['item_id' => $i['id'], 'client_id' => $set['client_id'],
                'destino' => 'wp', 'external_id' => $r['id'], 'url' => $r['url'], 'status' => 'publicado']);
            if (!empty($set['task_id'])) pdEvento($set['task_id'], 'site', 'Post publicado no site do cliente',
                ($i['title'] ?: '') . ' → ' . $r['url'], 'Robô', 'robo');
            $n++;
        } catch (Exception $e) {
            sd_supabase('/rest/v1/pd_site_posts', 'POST', ['item_id' => $i['id'], 'client_id' => $set['client_id'],
                'destino' => 'wp', 'status' => 'erro', 'erro' => mb_substr($e->getMessage(), 0, 300)]);
            pdAlarme('erro_publicacao', 'Site do cliente recusou o post', ($i['title'] ?: '') . ' — ' . $e->getMessage(),
                $set['task_id'] ?? null, null);
            $n++;
        }
    }
    return $n;
}
function pd_instante_site($v) {
    $v = trim((string)$v);
    if ($v === '') return 0;
    try {
        if (preg_match('/(Z|[+\-]\d\d(:?\d\d)?)$/i', $v)) return (new DateTime($v))->getTimestamp();
        return (new DateTime($v, new DateTimeZone('America/Sao_Paulo')))->getTimestamp();
    } catch (Exception $e) { return 0; }
}

// =====================================================================
$n1 = w_derivadas();                 echo "Derivadas acordadas: $n1\n";
$e  = w_esteiras();                  echo "Esteiras rodadas: " . ($e['rodadas'] ?? 0) . (!empty($e['sem_tabela']) ? ' (rode o SQL da v62)' : '') . "\n";
$n3 = w_copias();                    echo "Artes copiadas pro Drive: $n3\n";
$n4 = w_faxina();                    echo "Faxina do mês: " . ($n4 ? 'alarme criado' : 'nada a fazer') . "\n";
$z  = w_zap();                       echo "Zap: " . ($z['enviados'] ?? 0) . " enviado(s), " . ($z['manuais'] ?? 0) . " manual(is)" . (!empty($z['sem_tabela']) ? ' (rode o SQL da v62)' : '') . "\n";
$n6 = w_relatorios();                echo "Relatórios de campanha: $n6\n";
$n7 = w_sites();                     echo "Posts publicados em site de cliente: $n7\n";
$n8 = w_feed_casa();                 echo "Feed da casa: $n8\n";
$n9 = w_alunos();                    echo "Alunos reagem: $n9\n";
echo "\nConcluido.\n";
