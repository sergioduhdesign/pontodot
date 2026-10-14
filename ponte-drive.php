<?php
// =====================================================================
// SD Workflow — PONTE COM O DRIVE (lado do SD)
//
// Dois tipos de chamada chegam aqui:
//
//  1) Do APPS SCRIPT (a ponte que mora na conta Google da agência),
//     autenticado pela chave SD_PONTE_KEY:
//       tarefas_ativas  → quais tarefas estão com a IA agora
//       entrega         → a IA escreveu TERMINEI: marca e avisa o gestor
//       check           → registra que a pasta foi verificada
//
//  2) Do APP (equipe logada), autenticado pela sessão:
//       conectar, play, verificar, listar, baixar,
//       enviar_correcoes, liberar, revisao, desconectar,
//       pausar, retomar, mensagem
//     O navegador NUNCA vê a chave da ponte — tudo passa por aqui.
//
//  Todo pedido pra IA também vira CHAMADO por e-mail (avisarIA) para
//  SD_IA_EMAIL (padrão sergio.duh.design+sdia@gmail.com).
//
// sd-config.php precisa de:
//   define('SD_PONTE_URL', 'https://script.google.com/macros/s/.../exec');
//   define('SD_PONTE_KEY', 'uma-chave-longa-e-aleatoria');
// =====================================================================

require_once __DIR__ . '/sd-auth.php';
require_once __DIR__ . '/push-lib.php';

@set_time_limit(180);
@ini_set('memory_limit', '512M');

if (!defined('SD_PONTE_URL') || !defined('SD_PONTE_KEY') || !SD_PONTE_KEY) {
    sd_fail('Ponte do Drive não configurada: faltam SD_PONTE_URL e SD_PONTE_KEY no sd-config.php.', 500);
}

// ---------------------------------------------------------------------
// Chamada ao Apps Script. O Google responde com um redirecionamento
// (302) pro conteúdo — por isso FOLLOWLOCATION.
// ---------------------------------------------------------------------
function ponte($acao, $dados = []) {
    $dados['acao'] = $acao;
    $dados['key']  = SD_PONTE_KEY;
    $ch = curl_init(SD_PONTE_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($dados),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 170,
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    $j = json_decode($raw, true);
    if (!is_array($j)) {
        throw new Exception('A ponte do Drive não respondeu direito (HTTP ' . $code . ($err ? ' · ' . $err : '') . '). '
            . 'Confira se o App da Web está implantado como "Qualquer pessoa".');
    }
    if (empty($j['ok'])) throw new Exception('Drive: ' . ($j['erro'] ?? 'erro desconhecido'));
    return $j;
}

function td_get($taskId) {
    $r = sd_supabase('/rest/v1/task_drive?task_id=eq.' . urlencode($taskId) . '&select=*');
    return $r['body'][0] ?? null;
}
function td_patch($taskId, $campos) {
    $campos['updated_at'] = date('c');
    $r = sd_supabase('/rest/v1/task_drive?task_id=eq.' . urlencode($taskId), 'PATCH', $campos,
        ['Prefer: return=representation']);
    // Coluna que ainda não existe no banco (migration atrasada): tira e
    // tenta de novo — o ciclo não pode travar por causa de um campo extra.
    for ($i = 0; $i < 6 && $r['code'] >= 300 && preg_match('/column "?(\w+)"?|\'(\w+)\' column/i', $r['raw'], $m); $i++) {
        $col = $m[1] ?: ($m[2] ?? '');
        if (!$col || !array_key_exists($col, $campos)) break;
        unset($campos[$col]);
        $r = sd_supabase('/rest/v1/task_drive?task_id=eq.' . urlencode($taskId), 'PATCH', $campos,
            ['Prefer: return=representation']);
    }
    if ($r['code'] >= 300) throw new Exception('Erro ao salvar a tarefa: ' . mb_substr($r['raw'], 0, 200));
    return $r['body'][0] ?? null;
}
function tarefa($taskId) {
    $r = sd_supabase('/rest/v1/tasks?id=eq.' . urlencode($taskId)
        . '&select=id,action,project,resp,obs,client_id,date_int,date_cli,clients(name),products(name)');
    return $r['body'][0] ?? null;
}

// CRONÔMETRO DA TAREFA (task_sessions) — enquanto a IA trabalha, a
// tarefa fica "rodando" no tracker, como se alguém tivesse dado play.
// Abre no Play / nas correções; fecha quando a IA escreve TERMINEI.
function sessaoAbrir($taskId, $userId) {
    $r = sd_supabase('/rest/v1/task_sessions?task_id=eq.' . urlencode($taskId) . '&ended_at=is.null&select=id');
    if (!empty($r['body'])) return;                          // já está rodando
    sd_supabase('/rest/v1/task_sessions', 'POST', ['task_id' => $taskId, 'user_id' => $userId]);
}
function sessaoFechar($taskId) {
    sd_supabase('/rest/v1/task_sessions?task_id=eq.' . urlencode($taskId) . '&ended_at=is.null', 'PATCH',
        ['ended_at' => date('c')]);
}

// O ID da pasta a partir do link colado ("…/folders/ID?usp=…" ou "id=ID")
function idDaPasta($link) {
    $link = trim((string)$link);
    if (preg_match('~/folders/([A-Za-z0-9_-]{10,})~', $link, $m)) return $m[1];
    if (preg_match('~[?&]id=([A-Za-z0-9_-]{10,})~', $link, $m)) return $m[1];
    if (preg_match('~^[A-Za-z0-9_-]{20,}$~', $link)) return $link;
    return null;
}

function briefing($t, $td = null, $referencia = null) {
    return [
        'tarefa'     => ($t['id'] ?? '') . ' — ' . ($t['action'] ?? ''),
        'cliente'    => $t['clients']['name'] ?? '',
        'projeto'    => trim(($t['products']['name'] ?? '') . ' · ' . ($t['project'] ?? ''), ' ·'),
        'pedido'     => "LEIA PRIMEIRO o arquivo 00_BRIEFING_IA.txt desta pasta — é o briefing completo de produção (formato, regras, checklist).\n\n" . ($t['obs'] ?? ''),
        'referencia' => $referencia ?? ($td['referencia_url'] ?? ''),
    ];
}

// Avisa quem cuida da tarefa (responsável) + admins: notificação no
// painel (caixa grande + som, em tempo real) e push em TODOS os aparelhos.
function avisarGestor($t, $titulo, $corpo, $tipo = 'drive_entrega') {
    $filtro = 'role=in.(admin)';
    if (!empty($t['resp'])) $filtro = 'or=(role.eq.admin,team_key.eq.' . rawurlencode($t['resp']) . ')';
    $r = sd_supabase('/rest/v1/profiles?' . $filtro . '&active=eq.true&select=id');
    $ids = array_map(fn($p) => $p['id'], $r['body'] ?? []);
    if (!$ids) return;
    sd_supabase('/rest/v1/notifications', 'POST', array_map(fn($id) => [
        'user_id' => $id, 'type' => $tipo, 'title' => $titulo,
        'body' => $corpo, 'link' => 'task:' . $t['id'],
    ], $ids));
    foreach ($ids as $id) sd_push_usuario($id, $titulo, $corpo, '/app.html?task=' . rawurlencode($t['id']), 'sd-' . $tipo);
}

// Avisa o CLIENTE (todos os contatos com login daquele cliente):
// sino + push + e-mail com o link de aprovação.
function avisarCliente($clientId, $titulo, $corpo, $link) {
    $r = sd_supabase('/rest/v1/profiles?client_id=eq.' . urlencode($clientId) . '&role=eq.client&active=eq.true&select=id,name,email');
    $n = 0;
    foreach (($r['body'] ?? []) as $p) {
        sd_supabase('/rest/v1/notifications', 'POST', ['user_id' => $p['id'], 'type' => 'aprovacao_nova',
            'title' => $titulo, 'body' => $corpo, 'link' => null]);
        sd_push_usuario($p['id'], $titulo, $corpo, '/app.html', 'sd-aprovacao');
        if (!empty($p['email'])) {
            $msg = 'Olá, ' . ($p['name'] ?: '') . "!\n\n" . $corpo . "\n\nAbra para aprovar:\n" . $link
                 . "\n\nVocê pode aprovar tudo de uma vez ou pedir ajuste peça por peça.\n\nStudio Design\nhttps://sd.sergioduh.com";
            @mail($p['email'], '=?UTF-8?B?' . base64_encode($titulo) . '?=', $msg,
                "From: Studio Design <workflow@sergioduh.com>\r\nReply-To: contato@sergioduh.com\r\nContent-Type: text/plain; charset=UTF-8");
        }
        $n++;
    }
    return $n;
}

// =====================================================================
// CHAMADO PRA IA — por E-MAIL
// Todo pedido que a IA (o Dot) precisa saber vira um e-mail pro
// endereço de trabalho dela. O e-mail é a CAMPAINHA: diz o que fazer e
// onde. A VERDADE fica na pasta — o mesmo texto é gravado como
// 00_CHAMADO_IA.txt (sempre o chamado mais recente).
//   tipo: PRODUZIR · CORRIGIR · PAUSAR · RETOMAR · MENSAGEM · PARAR
// =====================================================================
function emailIA() {
    return defined('SD_IA_EMAIL') && SD_IA_EMAIL ? SD_IA_EMAIL : 'sergio.duh.design+sdia@gmail.com';
}
// COMO ENTREGAR NA SAIDA — vale pra todo job. Vai no chamado (e-mail) e
// é colada no fim de todo 00_BRIEFING_IA.txt, venha ele do chat da frente
// ou do verso. A SAIDA vira lote de aprovação sozinha: se a IA entregar
// prancha/amostra (várias peças numa imagem), nada pode ser aprovado peça
// por peça nem publicado.
const PD_REGRA_SAIDA_TITULO = 'COMO ENTREGAR NA SAIDA (vira o lote de aprovação sozinho)';
function pdRegraSaida() {
    return implode("\n", [
        PD_REGRA_SAIDA_TITULO,
        'O Pontodot lê a SAIDA e monta o lote peça por peça; o que o cliente aprova é publicado. Por isso:',
        '1. Entregue as PEÇAS FINAIS, uma por arquivo, no tamanho final. NUNCA entregue prancha, mockup, "amostra", grade ou contact sheet com várias peças numa imagem só.',
        '2. Uma SUBPASTA por post, com o tipo no começo do nome: 01-carrossel-<tema>/ · 02-feed-<tema>/ · 03-stories-<tema>/ · 04-reels-<tema>/',
        '3. Dentro da subpasta, um arquivo por card/tela, na ordem: 01.png, 02.png, 03.png… Feed e carrossel 1080×1350 (carrossel até 10 cards). Stories 1080×1920. Reels: o .mp4 (e capa.jpg).',
        '4. O que não é post (capa de site, texto de blog, áudio, PDF, impresso) vai numa subpasta que começa com "material-" (ex.: material-capas-site/).',
        '5. Um Excel na SAIDA (legendas.xlsx), uma linha por post: ARQUIVO_OU_PASTA (nome exato da subpasta) | TIPO (FEED, CARROSSEL, STORIES, REELS ou MATERIAL) | TITULO | LEGENDA | HASHTAGS | DATA (dd/mm/aaaa) | HORA (hh:mm, Brasília).',
        '6. Nada de rascunho, versão antiga ou arquivo de trabalho (.psd, .ai, .fig) na SAIDA — o que está lá vira peça.',
        '7. Correção: mesmo nome de subpasta e de arquivo; entregue só o que mudou.',
    ]);
}

function avisarIA($taskId, $tipo, $fazer, $extra = []) {
    try {
        $td = td_get($taskId);
        $t  = tarefa($taskId);
        if (!$td || !$t) return null;
        $rodada  = (int)($extra['rodada'] ?? $td['rodada'] ?? 1) ?: 1;
        $chamado = $taskId . '-R' . $rodada . '-' . $tipo . '-' . date('ymdHis');
        // MENSAGEM não é um chamado de estado: COMPLEMENTA o chamado em andamento
        $emAndamento = $tipo === 'MENSAGEM' ? ($td['ultimo_chamado'] ?? '') : '';
        $cliente = $t['clients']['name'] ?? '';
        $produto = $t['products']['name'] ?? '';
        $base    = 'https://drive.google.com/drive/folders/';
        $L = [];
        $L[] = 'O QUE FAZER: ' . $fazer;
        $L[] = '';
        $L[] = 'Chamado: ' . $chamado;
        if ($tipo === 'MENSAGEM') $L[] = 'Complementa o chamado em andamento: ' . ($emAndamento ?: '(nenhum)');
        $L[] = 'Tarefa: ' . $taskId . ' — ' . ($t['action'] ?? '');
        $L[] = 'Cliente / Projeto: ' . trim($cliente . ' / ' . trim($produto . ' · ' . ($t['project'] ?? ''), ' ·'), ' /');
        if (!empty($td['tipo_entrega'])) $L[] = 'Tipo de entrega: ' . $td['tipo_entrega'];
        $L[] = 'Rodada: ' . $rodada;
        $prazo = $extra['prazo'] ?? ($t['date_int'] ?? ($t['date_cli'] ?? null));
        if (!empty($prazo)) $L[] = 'Prazo: ' . (preg_match('/^\d{4}-\d{2}-\d{2}/', $prazo) ? date('d/m/Y', strtotime($prazo)) : $prazo);
        $L[] = '';
        $L[] = 'Pasta da tarefa: ' . ($td['folder_url'] ?: $base . $td['folder_id']);
        $L[] = 'Briefing completo: 00_BRIEFING_IA.txt (raiz da pasta) — LEIA ANTES DE COMEÇAR';
        if (!empty($td['entrada_id'])) $L[] = 'ENTRADA (arquivos de origem): ' . $base . $td['entrada_id'];
        if (!empty($td['saida_id']))   $L[] = 'SAIDA (onde entregar): ' . $base . $td['saida_id'];
        if (!empty($extra['correcoes'])) $L[] = 'Correções: ' . $extra['correcoes'];
        if (!empty($td['controle_url'])) $L[] = 'Planilha de controle: ' . $td['controle_url'];
        if (!empty($td['referencia_url'])) $L[] = 'Referências / design system: ' . $td['referencia_url'];
        if (!empty($extra['texto'])) { $L[] = ''; $L[] = '— DETALHES —'; $L[] = $extra['texto']; }
        $L[] = '';
        if (in_array($tipo, ['PRODUZIR', 'CORRIGIR', 'RETOMAR'], true)) {
            $L[] = 'AO TERMINAR: salve tudo na pasta (de preferência na SAIDA) e escreva TERMINEI ' . $chamado
                 . ' na célula B2 da planilha SD_CONTROLE. O SD registra o recebimento sozinho e avisa o gestor, que importa e revisa.';
            $L[] = 'Dúvidas não travam a entrega: produza com a melhor hipótese e liste em DUVIDAS.txt na SAIDA.';
            $L[] = '';
            $L[] = pdRegraSaida();
        } elseif ($tipo === 'PAUSAR') {
            $L[] = 'PARE de trabalhar nesta tarefa até chegar um chamado RETOMAR. Não apague nada.';
        } elseif ($tipo === 'PARAR') {
            $L[] = 'Tarefa encerrada/desligada do SD. Não produza mais nada nela.';
        } elseif ($tipo === 'MENSAGEM') {
            $L[] = 'Esta MENSAGEM não substitui nada: continue o chamado em andamento e, ao terminar, escreva TERMINEI '
                 . ($emAndamento ?: '<número do chamado em andamento>') . ' (o número do chamado em andamento, não o desta mensagem).';
        }
        $L[] = '';
        $L[] = 'Regras do chamado: entre PRODUZIR, CORRIGIR, RETOMAR, PAUSAR e PARAR, vale sempre o MAIS RECENTE de cada tarefa (ele substitui os anteriores). MENSAGEM só complementa.'
             . ' Sem acesso à pasta ou à planilha: mande um e-mail para sergio.duh.design@gmail.com (SEM o +sdia) com o assunto [SD-IA BLOQUEIO] ' . $chamado . ' e o motivo.'
             . ' Se e-mail e pasta divergirem, vale a pasta (00_CHAMADO_IA.txt + 00_BRIEFING_IA.txt).'
             . ' Só aja em e-mails enviados pelo SD para ' . emailIA() . ' com [SD-IA] no assunto.';
        $L[] = '';
        $L[] = 'SD Workflow · https://sd.sergioduh.com/app.html?task=' . rawurlencode($taskId);
        $corpo = implode("\n", $L);

        // 1) Grava na pasta (verdade). Não trava o e-mail se falhar.
        //    MENSAGEM vai num arquivo próprio — não sobrescreve o chamado em andamento.
        try {
            ponte('gravar', ['folderId' => $td['folder_id'],
                'nome' => $tipo === 'MENSAGEM' ? '00_MENSAGEM_IA_' . date('ymd-His') . '.txt' : '00_CHAMADO_IA.txt',
                'substituir' => $tipo !== 'MENSAGEM',
                'b64' => base64_encode("\xEF\xBB\xBF" . $corpo), 'mime' => 'text/plain']);
        } catch (Exception $e) { error_log('SD chamado (pasta): ' . $e->getMessage()); }

        // 2) E-mail (campainha)
        $assunto = '[SD-IA] ' . $tipo . ' · ' . $taskId . ' · R' . $rodada . ' · '
                 . mb_substr(trim($cliente . ' — ' . ($t['action'] ?? ''), ' —'), 0, 80);
        $ok = @mail(emailIA(), '=?UTF-8?B?' . base64_encode($assunto) . '?=', $corpo,
            "From: SD Workflow <workflow@sergioduh.com>\r\nReply-To: workflow@sergioduh.com\r\n"
            . "X-SD-Chamado: " . $chamado . "\r\nContent-Type: text/plain; charset=UTF-8");

        if ($tipo !== 'MENSAGEM') td_patch($taskId, ['ultimo_chamado' => $chamado, 'ultimo_chamado_em' => date('c')]);
        return ['chamado' => $chamado, 'email' => (bool)$ok];
    } catch (Exception $e) {
        error_log('SD chamado: ' . $e->getMessage());
        return null;
    }
}

$d = sd_body();
$acao = $d['acao'] ?? '';

// =====================================================================
// 1) CHAMADAS DO APPS SCRIPT (chave)
// =====================================================================
if (isset($d['key'])) {
    if (!hash_equals(SD_PONTE_KEY, (string)$d['key'])) sd_fail('Chave inválida.', 403);

    if ($acao === 'tarefas_ativas') {
        $r = sd_supabase('/rest/v1/task_drive?status=in.(produzir,corrigir)&select=task_id,controle_id,saida_id,rodada');
        echo json_encode(['tarefas' => $r['body'] ?? []]);
        exit;
    }
    if ($acao === 'check') {
        td_patch($d['task_id'] ?? '', ['ultimo_check' => date('c')]);
        echo json_encode(['ok' => true]);
        exit;
    }
    if ($acao === 'entrega') {
        $taskId = $d['task_id'] ?? '';
        $td = td_get($taskId);
        if (!$td || !in_array($td['status'], ['produzir', 'corrigir'], true)) {
            echo json_encode(['ok' => true, 'ignorado' => true]); exit;
        }
        td_patch($taskId, ['status' => 'entregue', 'ultima_entrega' => date('c'),
            'ultimo_check' => date('c'), 'arquivos_saida' => (int)($d['arquivos'] ?? 0),
            'robo' => null, 'robo_em' => null]);
        sessaoFechar($taskId);
        $t = tarefa($taskId);
        if ($t) avisarGestor($t, '⇣ A IA entregou — ' . $t['id'], ($t['clients']['name'] ?? '') . ' · ' . $t['action']
            . ' — ' . (!empty($td['set_id']) ? 'o Pontodot está aplicando a correção no lote.' : 'o Pontodot está montando o lote.'));
        echo json_encode(['ok' => true]);
        exit;
    }
    sd_fail('Ação desconhecida.', 400);
}

// =====================================================================
// 1b) CORREÇÃO DO CLIENTE → IA, na hora
// Chamado pela tela de aprovação do cliente (link público, sem login)
// logo depois que ele pede ajuste. Autenticado pelo token do link: só
// mexe na tarefa daquele lote. O Excel vai pra ENTRADA, o status vira
// CORRIGIR, o cronômetro liga e o gestor é avisado.
// =====================================================================
// Cliente decidiu pelo link público (aprovou / pediu ajuste): avisa o
// gestor com alerta + som + push. A tela do cliente junta os cliques e
// manda UM aviso por rodada de decisões.
if ($acao === 'cliente_decidiu') {
    $tok = (string)($d['token'] ?? '');
    if (!preg_match('/^[0-9a-fA-F-]{36}$/', $tok)) sd_fail('Link inválido.', 400);
    $r = sd_supabase('/rest/v1/approval_sets?share_token=eq.' . urlencode($tok) . '&select=id,task_id,title,client_id');
    $set = $r['body'][0] ?? null;
    if (!$set) sd_fail('Lote não encontrado.', 404);
    $ok = (int)($d['aprovadas'] ?? 0); $aj = (int)($d['ajustes'] ?? 0);
    if (!$ok && !$aj) { echo json_encode(['ok' => true]); exit; }
    $t = !empty($set['task_id']) ? tarefa($set['task_id']) : null;
    if (!$t) $t = ['id' => $set['task_id'] ?: '', 'resp' => null, 'action' => $set['title'], 'clients' => ['name' => '']];
    $temDrive = !empty($set['task_id']) && td_get($set['task_id']);
    // Ajuste em tarefa com Drive já é avisado pelo correcao_cliente — aqui só a aprovação
    if ($aj && !$temDrive) {
        avisarGestor($t, '↺ Cliente pediu ajuste em ' . $aj . ' peça(s)', '"' . $set['title'] . '"'
            . ($ok ? ' · ' . $ok . ' aprovada(s)' : ''), 'cliente_ajuste');
    } elseif ($ok) {
        avisarGestor($t, '✓ Cliente aprovou ' . $ok . ' peça(s)', '"' . $set['title'] . '"'
            . ($aj ? ' · ' . $aj . ' com ajuste (já foi pra IA)' : ''), 'aprovacao_ok');
    }
    echo json_encode(['ok' => true]);
    exit;
}

if ($acao === 'correcao_cliente') {
    $tok = (string)($d['token'] ?? '');
    if (!preg_match('/^[0-9a-fA-F-]{36}$/', $tok)) sd_fail('Link inválido.', 400);
    $r = sd_supabase('/rest/v1/approval_sets?share_token=eq.' . urlencode($tok) . '&select=id,task_id,title');
    $set = $r['body'][0] ?? null;
    if (!$set || empty($set['task_id'])) { echo json_encode(['ok' => true, 'sem_drive' => true]); exit; }
    $td = td_get($set['task_id']);
    if (!$td) { echo json_encode(['ok' => true, 'sem_drive' => true]); exit; }
    $b64 = (string)($d['b64'] ?? '');
    if (!$b64 || strlen($b64) > 4 * 1024 * 1024) sd_fail('Excel inválido.', 400);
    try {
        ponte('gravar', ['folderId' => $td['entrada_id'], 'nome' => 'correcoes.xlsx', 'b64' => $b64,
            'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
        // Várias correções seguidas do cliente = a MESMA rodada (o arquivo
        // é regravado com tudo). Rodada só sobe quando abre uma nova.
        $nova = $td['status'] !== 'corrigir';
        $rodada = (int)$td['rodada'] + ($nova ? 1 : 0);
        $campos = ['status' => 'corrigir', 'rodada' => $rodada];
        if ($nova) $campos['marco'] = date('c');
        ponte('status', ['controleId' => $td['controle_id'], 'status' => 'CORRIGIR', 'rodada' => $rodada,
            'obs' => 'Cliente pediu ajuste · ' . (int)($d['n'] ?? 0) . ' peça(s)']);
        td_patch($set['task_id'], $campos);
        if (!empty($td['created_by'])) sessaoAbrir($set['task_id'], $td['created_by']);
        $n = (int)($d['n'] ?? 0);
        $ch = avisarIA($set['task_id'], 'CORRIGIR',
            ($nova ? 'O CLIENTE pediu ajuste em ' . $n . ' peça(s). Aplique as correções'
                   : 'O CLIENTE completou o pedido de ajuste (agora ' . $n . ' peça(s)). Este chamado SUBSTITUI o anterior da mesma rodada — aplique tudo')
            . ' do arquivo correcoes.xlsx (pasta ENTRADA): coluna CORRECAO_CRIATIVO = arte, CORRECAO_COPY = texto. '
            . 'Mexa SÓ nas peças com correção; as aprovadas ficam como estão. Mantenha o nome de cada arquivo.',
            ['rodada' => $rodada, 'correcoes' => 'ENTRADA/correcoes.xlsx (' . $n . ' peça(s))']);
        $t = tarefa($set['task_id']);
        if ($t && !$nova) {
            avisarGestor($t, '↺ Cliente completou o pedido de ajuste', ($t['clients']['name'] ?? '') . ' · ' . $t['action']
                . ' — agora ' . $n . ' peça(s). A IA recebeu o chamado atualizado.', 'cliente_ajuste');
        }
        if ($nova) {
            if ($t) avisarGestor($t, '↺ Cliente pediu ajuste — já foi pra IA',
                ($t['clients']['name'] ?? '') . ' · ' . $t['action'] . ' — ' . $n
                . ' peça(s). A IA já recebeu o chamado por e-mail; a nova versão volta pra sua revisão antes do cliente.', 'cliente_ajuste');
        }
        echo json_encode(['ok' => true, 'enviado' => true, 'chamado' => $ch['chamado'] ?? null]);
    } catch (Exception $e) {
        sd_fail($e->getMessage(), 502);
    }
    exit;
}

// =====================================================================
// 2) CHAMADAS DO APP (sessão — só equipe)
// =====================================================================
$me = sd_current_user();
if (!in_array($me['role'], ['admin', 'team'], true)) sd_fail('Só a equipe usa a ponte do Drive.', 403);

// ---------------------------------------------------------------------
// 2a) AÇÕES DO CLIENTE — não falam de tarefa, falam de PASTA DE CLIENTE.
// É o endereço fixo de cada cliente no Drive: onde as instruções moram,
// onde nascem as pastas das tarefas e onde cai o que sobe pelo chat.
// ---------------------------------------------------------------------
if (in_array($acao, ['cliente_pasta', 'cliente_subir', 'cliente_instrucoes'], true)) {
    $clientId = $d['client_id'] ?? '';
    if (!$clientId) sd_fail('Preciso saber de qual cliente é isso.');
    $rc = sd_supabase('/rest/v1/clients?id=eq.' . urlencode($clientId)
        . '&select=id,name,drive_id,drive_url,instrucoes_id,instrucoes_url');
    $cli = $rc['body'][0] ?? null;
    if (!$cli) sd_fail('Cliente não encontrado.', 404);

    // garante a pasta do cliente: usa a que já está gravada, o link que
    // veio agora, ou cria dentro da pasta mestre (RAIZ_CLIENTES no GAS).
    $garantirPasta = function () use ($cli, $d, $clientId) {
        $id = $cli['drive_id'] ?? null;
        if (!empty($d['drive_url'])) { $novo = idDaPasta($d['drive_url']); if ($novo) $id = $novo; }
        if (!$id) {
            $raiz = defined('SD_DRIVE_CLIENTES') ? SD_DRIVE_CLIENTES : '';
            if (!$raiz) throw new Exception('Sem a pasta mestre dos clientes: defina SD_DRIVE_CLIENTES no sd-config.php ou cole o link da pasta deste cliente.');
            $p = ponte('pasta_garantir', ['pai_id' => $raiz, 'nome' => $cli['name'], 'criar' => true]);
            $id = $p['id'] ?? null;
            if (!$id) throw new Exception('Não consegui criar a pasta do cliente.');
        }
        // confirma e pega a URL de verdade
        $p = ponte('pasta_garantir', ['pai_id' => $id, 'nome' => 'ENTRADA', 'criar' => false]);
        $url = $cli['drive_url'] ?: ('https://drive.google.com/drive/folders/' . $id);
        if (($cli['drive_id'] ?? null) !== $id || empty($cli['drive_url'])) {
            sd_supabase('/rest/v1/clients?id=eq.' . urlencode($clientId), 'PATCH',
                ['drive_id' => $id, 'drive_url' => $url]);
        }
        return ['id' => $id, 'url' => $url];
    };

    try {
        switch ($acao) {

        // Diz (ou define) onde mora este cliente no Drive.
        case 'cliente_pasta': {
            $p = $garantirPasta();
            echo json_encode(['ok' => true, 'pasta' => $p, 'cliente' => $cli['name']]);
            break;
        }

        // Sobe um arquivo pra pasta do cliente — é o que o chat da SD usa.
        // Sem cliente não chega aqui: o chat barra antes.
        case 'cliente_subir': {
            $nome = trim((string)($d['nome'] ?? ''));
            $b64  = (string)($d['b64'] ?? '');
            if (!$nome || !$b64) sd_fail('Faltou o arquivo.');
            if (strlen($b64) > 28 * 1024 * 1024) sd_fail('Arquivo grande demais pra subir por aqui (limite ~20 MB).');
            $p = $garantirPasta();
            $destino = $p['id'];
            // subpasta opcional (ex.: "INSTRUCOES", "ENTRADA", nome da tarefa)
            $sub = trim((string)($d['subpasta'] ?? ''));
            if ($sub) {
                $sp = ponte('pasta_garantir', ['pai_id' => $p['id'], 'nome' => mb_substr($sub, 0, 80), 'criar' => true]);
                if (!empty($sp['id'])) $destino = $sp['id'];
            }
            $g = ponte('gravar', ['folderId' => $destino, 'nome' => mb_substr($nome, 0, 120),
                'b64' => $b64, 'mime' => (string)($d['mime'] ?? 'application/octet-stream'),
                'substituir' => false]);
            echo json_encode(['ok' => true, 'arquivo' => $g, 'pasta' => $p, 'cliente' => $cli['name']]);
            break;
        }

        // Lista o que a casa sabe deste cliente (pasta de instruções).
        case 'cliente_instrucoes': {
            $p = $garantirPasta();
            $base = $cli['instrucoes_id'] ?: $p['id'];
            $r = ponte('docs_listar', ['pasta_id' => $base, 'recursivo' => true, 'limite' => 40]);
            echo json_encode(['ok' => true, 'arquivos' => $r['arquivos'] ?? [], 'pasta' => $p]);
            break;
        }
        }
    } catch (Exception $e) {
        sd_fail($e->getMessage(), 502);
    }
    exit;
}

// ---------------------------------------------------------------------
// 2b) O ROBÔ DO LOTE
// A IA escreveu TERMINEI → a tarefa fica "entregue". Quem da equipe
// estiver com o Pontodot aberto (computador ou celular) monta o lote —
// ou aplica a correção no lote que já existe — com as MESMAS telas e
// regras de quando se faz na mão, só que sem ninguém clicar. Um aparelho
// pega a tarefa por vez (trava aqui no servidor).
// ---------------------------------------------------------------------
if ($acao === 'robo_fila') {
    $r = sd_supabase('/rest/v1/task_drive?status=eq.entregue&select=task_id,set_id,robo,robo_em,revisao_interna');
    // Sem as colunas do robô (SQL da v60 não rodou) ele fica desligado: sem
    // trava, cada aparelho tentaria a mesma tarefa a cada minuto e meio.
    if ($r['code'] >= 300) { echo json_encode(['fila' => [], 'falta_sql' => true]); exit; }
    $limite = time() - 15 * 60; $fila = [];
    foreach ((array)($r['body'] ?? []) as $x) {
        $robo = (string)($x['robo'] ?? '');
        if (str_starts_with($robo, 'parado') || $robo === 'feito') continue;          // já precisa de gente
        if (str_starts_with($robo, 'pegou') && strtotime((string)($x['robo_em'] ?? '')) > $limite) continue;
        $setId = $x['set_id'] ?? null;
        if (!$setId) {   // lote feito na mão antes, sem passar pela ponte
            $s2 = sd_supabase('/rest/v1/approval_sets?task_id=eq.' . urlencode($x['task_id']) . '&select=id&order=created_at.desc&limit=1');
            $setId = $s2['body'][0]['id'] ?? null;
        }
        if ($setId) {   // lote inteiro aprovado: o que chega agora é entrega nova, não correção
            $it = sd_supabase('/rest/v1/approval_items?set_id=eq.' . urlencode($setId) . '&select=status');
            $sts = array_map(fn($i) => $i['status'] ?? '', (array)($it['body'] ?? []));
            if ($sts && !array_diff($sts, ['aprovado'])) $setId = null;
        }
        $fila[] = ['task_id' => $x['task_id'], 'set_id' => $setId];
    }
    echo json_encode(['fila' => array_slice($fila, 0, 3)]);
    exit;
}
if ($acao === 'robo_pegar') {
    $tid = (string)($d['task_id'] ?? '');
    $antes = gmdate('Y-m-d\TH:i:s\Z', time() - 15 * 60);
    $r = sd_supabase('/rest/v1/task_drive?task_id=eq.' . urlencode($tid) . '&status=eq.entregue'
        . '&or=(robo.is.null,and(robo.like.pegou*,robo_em.lt.' . $antes . '))', 'PATCH',
        ['robo' => 'pegou:' . $me['id'], 'robo_em' => gmdate('c')], ['Prefer: return=representation']);
    if ($r['code'] >= 300) {   // banco sem as colunas do robô (SQL da v60 não rodou): segue sem trava
        echo json_encode(['pegou' => (bool)td_get($tid), 'sem_trava' => true]); exit;
    }
    echo json_encode(['pegou' => !empty($r['body'][0])]);
    exit;
}
if ($acao === 'robo_fim') {
    $tid = (string)($d['task_id'] ?? '');
    $td = td_get($tid); $t = tarefa($tid);
    if (!$td || !$t) sd_fail('Tarefa não encontrada.', 404);
    $ok = !empty($d['ok']); $tipo = ($d['tipo'] ?? '') === 'correcao' ? 'correcao' : 'lote';
    $n = (int)($d['n'] ?? 0); $setId = (string)($d['set_id'] ?? ($td['set_id'] ?? ''));
    $motivo = mb_substr(trim((string)($d['motivo'] ?? '')), 0, 200);
    $quem = ($t['clients']['name'] ?? '') . ' · ' . $t['action'];
    td_patch($tid, ['robo' => $ok ? 'feito' : 'parado: ' . ($motivo ?: 'sem motivo'), 'robo_em' => date('c')]);
    $set = null;
    if ($setId) { $rs = sd_supabase('/rest/v1/approval_sets?id=eq.' . urlencode($setId) . '&select=id,client_id,title,share_token,interno'); $set = $rs['body'][0] ?? null; }
    $direto = isset($td['revisao_interna']) && $td['revisao_interna'] === false;
    $avisouCliente = 0;

    if (!$ok) {
        avisarGestor($t, '⇣ A IA entregou — precisa de você', $quem . ' — ' . ($motivo ?: 'não consegui montar sozinho') . '. Abra a tarefa e importe a entrega.', 'drive_entrega');
    } elseif ($tipo === 'lote') {
        if ($direto && $set && !empty($set['share_token'])) {
            $avisouCliente = avisarCliente($set['client_id'], '✓ Material pronto pra aprovação', '"' . $set['title'] . '" está pronto pra sua aprovação.',
                'https://sd.sergioduh.com/aprovacao-preview.php?t=' . $set['share_token']);
            avisarGestor($t, '📦 Lote enviado ao cliente — ' . $n . ' peça(s)', $quem . ' — a revisão interna desta tarefa está desligada, então foi direto.', 'drive_entrega');
        } else {
            avisarGestor($t, '📦 Lote pronto pra revisar — ' . $n . ' peça(s)', $quem . ' — só a equipe vê. Comente, mande correções pra IA ou libere pro cliente.', 'drive_entrega');
        }
    } else {
        if ($direto && $set) {
            sd_supabase('/rest/v1/approval_sets?id=eq.' . urlencode($setId), 'PATCH', ['interno' => false]);
            td_patch($tid, ['status' => 'liberado']);
            $link = !empty($set['share_token']) ? 'https://sd.sergioduh.com/aprovacao-preview.php?t=' . $set['share_token'] : 'https://sd.sergioduh.com/app.html';
            $avisouCliente = avisarCliente($set['client_id'], '↺ Material corrigido pra aprovação',
                '"' . $set['title'] . '" foi ajustado conforme o seu pedido e está pronto pra nova aprovação.', $link);
            avisarGestor($t, '↺ Correção aplicada e enviada ao cliente — ' . $n . ' peça(s)', $quem, 'drive_entrega');
        } else {
            avisarGestor($t, '↺ Correção da IA aplicada — ' . $n . ' peça(s)', $quem . ' — versão nova no lote, só a equipe vê. Confira e libere pro cliente.', 'drive_entrega');
        }
    }
    echo json_encode(['ok' => true, 'avisou_cliente' => $avisouCliente]);
    exit;
}

$taskId = $d['task_id'] ?? '';
if (!$taskId) sd_fail('Tarefa não informada.');

try {
    switch ($acao) {

    // Liga a tarefa a uma pasta: cola o link de uma pasta existente OU
    // pede pra criar uma nova. Em ambos os casos a ponte garante ENTRADA,
    // SAIDA e a planilha SD_CONTROLE com o briefing.
    case 'conectar': {
        $t = tarefa($taskId);
        if (!$t) sd_fail('Tarefa não encontrada.', 404);
        $folderId = null;
        if (!empty($d['folder_url'])) {
            $folderId = idDaPasta($d['folder_url']);
            if (!$folderId) sd_fail('Não reconheci esse link de pasta do Drive.');
        }
        $ref = trim((string)($d['referencia_url'] ?? ''));
        $nome = $t['id'] . ' · ' . ($t['clients']['name'] ?? '') . ' · ' . $t['action'];
        // sem link colado, a pasta nasce DENTRO da pasta do cliente (o
        // endereço fixo dele) — e só cai na raiz geral se ele não tiver uma
        $parentId = null;
        if (!$folderId && !empty($t['client_id'])) {
            $rc = sd_supabase('/rest/v1/clients?id=eq.' . urlencode($t['client_id']) . '&select=drive_id');
            $parentId = $rc['body'][0]['drive_id'] ?? null;
        }
        $p = ponte('preparar', ['folderId' => $folderId, 'parentId' => $parentId,
            'nome' => mb_substr($nome, 0, 120),
            'briefing' => briefing($t, null, $ref)]);
        $linha = [
            'task_id' => $taskId, 'folder_id' => $p['folderId'], 'folder_url' => $p['folderUrl'],
            'entrada_id' => $p['entradaId'], 'saida_id' => $p['saidaId'],
            'controle_id' => $p['controleId'], 'controle_url' => $p['controleUrl'],
            'referencia_url' => $ref ?: null,
            'revisao_interna' => array_key_exists('revisao_interna', $d) ? (bool)$d['revisao_interna'] : true,
            'created_by' => $me['id'], 'updated_at' => date('c'),
        ];
        $r = sd_supabase('/rest/v1/task_drive?on_conflict=task_id', 'POST', $linha,
            ['Prefer: resolution=merge-duplicates,return=representation']);
        if ($r['code'] >= 300) sd_fail('Erro ao salvar: ' . $r['raw'], 500);
        echo json_encode(['drive' => $r['body'][0] ?? $linha]);
        break;
    }

    // Subir material direto na tarefa — é o que o chat da SD usa quando você
    // joga um pacote de posts ou um excel de correções na conversa.
    // .xlsx de correção entra pelo caminho de correção (rodada nova + chamado);
    // o resto cai na ENTRADA e avisa a IA que chegou material.
    case 'tarefa_subir': {
        $td = td_get($taskId); if (!$td) sd_fail('Essa tarefa ainda não tem pasta do Drive. Conecte uma antes.');
        $nome = trim((string)($d['nome'] ?? ''));
        $b64  = (string)($d['b64'] ?? '');
        if (!$nome || !$b64) sd_fail('Faltou o arquivo.');
        if (strlen($b64) > 28 * 1024 * 1024) sd_fail('Arquivo grande demais (limite ~20 MB).');
        $t = tarefa($taskId);
        $ehCorrecao = (bool)($d['correcao'] ?? false)
            || (bool)preg_match('/correc|corrig|ajuste/i', $nome);
        $ehExcel = (bool)preg_match('/\.xlsx?$/i', $nome);

        $destino = $td['entrada_id'] ?: $td['folder_id'];
        $g = ponte('gravar', ['folderId' => $destino, 'nome' => mb_substr($nome, 0, 120),
            'b64' => $b64, 'mime' => (string)($d['mime'] ?? 'application/octet-stream'),
            'substituir' => $ehCorrecao && $ehExcel]);

        // correção em planilha: a tarefa vira rodada nova e a IA é chamada
        if ($ehCorrecao && $ehExcel) {
            $nova = ($td['status'] ?? '') !== 'corrigir';
            $rodada = (int)($td['rodada'] ?? 0) + ($nova ? 1 : 0);
            ponte('status', ['controleId' => $td['controle_id'], 'status' => 'CORRIGIR', 'rodada' => $rodada,
                'obs' => 'Correções subidas por ' . $me['name'] . ' pelo chat da SD']);
            sessaoAbrir($taskId, $me['id']);
            $campos = ['status' => 'corrigir', 'rodada' => $rodada];
            if ($nova) $campos['marco'] = date('c');
            td_patch($taskId, $campos);
            $chamado = avisarIA($taskId, 'CORRIGIR', 'Correções novas na ENTRADA (' . $nome . '), subidas por '
                . $me['name'] . '. Aplique tudo e devolva na SAIDA.', ['rodada' => $rodada]);
            echo json_encode(['ok' => true, 'arquivo' => $g, 'virou' => 'correcao',
                'rodada' => $rodada, 'chamado' => $chamado]);
            break;
        }

        // material de origem: fica na ENTRADA esperando o play
        $avisar = (bool)($d['avisar_ia'] ?? false);
        $chamado = null;
        if ($avisar && in_array(($td['status'] ?? ''), ['produzir', 'corrigir'], true)) {
            $chamado = avisarIA($taskId, 'MENSAGEM', 'Chegou material novo na ENTRADA: ' . $nome . '. Use junto com o que já está fazendo.');
        }
        echo json_encode(['ok' => true, 'arquivo' => $g, 'virou' => 'entrada', 'chamado' => $chamado]);
        break;
    }

    // ▶ Dar play: a IA pode começar. Rodada 1.
    case 'play': {
        $td = td_get($taskId); if (!$td) sd_fail('Tarefa sem pasta do Drive.');
        $t = tarefa($taskId);
        $rodada = max(1, (int)$td['rodada']);
        ponte('status', ['controleId' => $td['controle_id'], 'status' => 'PRODUZIR', 'rodada' => $rodada,
            'obs' => 'Play dado por ' . $me['name'], 'briefing' => briefing($t, $td)]);
        sessaoAbrir($taskId, $me['id']);
        $drv = td_patch($taskId, ['status' => 'produzir', 'rodada' => $rodada, 'marco' => date('c')]);
        $ch = avisarIA($taskId, 'PRODUZIR',
            'Nova tarefa. Leia o 00_BRIEFING_IA.txt, use os arquivos da ENTRADA e das referências e produza a entrega completa.'
            . (!empty($td['instrucoes_ia']) ? ' Instruções extras: ' . $td['instrucoes_ia'] : ''),
            ['rodada' => $rodada, 'prazo' => $d['prazo'] ?? null]);
        echo json_encode(['drive' => $drv, 'chamado' => $ch]);
        break;
    }

    // "Verificar agora" — não espera a varredura de 15 min.
    case 'verificar': {
        $td = td_get($taskId); if (!$td) sd_fail('Tarefa sem pasta do Drive.');
        $s = ponte('ler_status', ['controleId' => $td['controle_id']]);
        $st = strtoupper(trim($s['status'] ?? ''));
        $lista = ponte('listar', ['folderId' => $td['saida_id']]);
        $n = count($lista['arquivos'] ?? []);
        $campos = ['ultimo_check' => date('c'), 'arquivos_saida' => $n];
        $terminou = (str_starts_with($st, 'TERMINEI') || $st === 'PRONTO' || $st === 'ENTREGUE');
        if ($terminou && in_array($td['status'], ['produzir', 'corrigir'], true)) {
            $campos['status'] = 'entregue'; $campos['ultima_entrega'] = date('c');
            ponte('status', ['controleId' => $td['controle_id'], 'status' => 'RECEBIDO PELO SD',
                'rodada' => $td['rodada'], 'obs' => $n . ' arquivo(s) na SAIDA']);
            sessaoFechar($taskId);
            $campos['robo'] = null; $campos['robo_em'] = null;
            $t = tarefa($taskId);
            if ($t) avisarGestor($t, '⇣ A IA entregou — ' . $t['id'], ($t['clients']['name'] ?? '') . ' · ' . $t['action']
                . ' — ' . $n . ' arquivo(s) na SAIDA.');
        }
        echo json_encode(['drive' => td_patch($taskId, $campos), 'status_planilha' => $st, 'arquivos' => $n]);
        break;
    }

    case 'listar': {
        $td = td_get($taskId); if (!$td) sd_fail('Tarefa sem pasta do Drive.');
        $qual = $d['pasta'] ?? 'saida';
        $pasta = $qual === 'entrada' ? $td['entrada_id'] : ($qual === 'raiz' ? $td['folder_id'] : $td['saida_id']);
        echo json_encode(ponte('listar', ['folderId' => $pasta]));
        break;
    }

    case 'baixar': {
        if (empty($d['file_id'])) sd_fail('Arquivo não informado.');
        echo json_encode(ponte('baixar', ['fileId' => $d['file_id']]));
        break;
    }

    // Gestor/cliente apontou correções: o Excel vai pra ENTRADA e a
    // IA recebe o sinal CORRIGIR (rodada + 1).
    case 'enviar_correcoes': {
        $td = td_get($taskId); if (!$td) sd_fail('Tarefa sem pasta do Drive.');
        if (empty($d['b64'])) sd_fail('Excel não recebido.');
        $nome = $d['nome'] ?? 'correcoes.xlsx';
        ponte('gravar', ['folderId' => $td['entrada_id'], 'nome' => $nome, 'b64' => $d['b64'],
            'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
        $nova = $td['status'] !== 'corrigir';
        $rodada = (int)$td['rodada'] + ($nova ? 1 : 0);
        ponte('status', ['controleId' => $td['controle_id'], 'status' => 'CORRIGIR', 'rodada' => $rodada,
            'obs' => ($d['origem'] ?? 'correções') . ' · ' . ($d['resumo'] ?? '') . ' · por ' . $me['name']]);
        sessaoAbrir($taskId, $me['id']);
        $drv = td_patch($taskId, ['status' => 'corrigir', 'rodada' => $rodada, 'marco' => date('c')]);
        $quem = ($d['origem'] ?? '') === 'revisão interna' ? 'A REVISÃO INTERNA (gestor)' : 'O CLIENTE (repassado pelo gestor)';
        $ch = avisarIA($taskId, 'CORRIGIR',
            $quem . ' apontou correções' . (!empty($d['resumo']) ? ' — ' . $d['resumo'] : '') . '. Aplique tudo do arquivo '
            . $nome . ' (pasta ENTRADA): CORRECAO_CRIATIVO = arte, CORRECAO_COPY = texto. '
            . 'Mexa SÓ nas peças com correção e mantenha o nome de cada arquivo.',
            ['rodada' => $rodada, 'correcoes' => 'ENTRADA/' . $nome]);
        echo json_encode(['drive' => $drv, 'chamado' => $ch]);
        break;
    }

    // ⏸ Pausar a IA (tarefa sai da varredura; e-mail PAUSAR)
    case 'pausar': {
        $td = td_get($taskId); if (!$td) sd_fail('Tarefa sem pasta do Drive.');
        if (!in_array($td['status'], ['produzir', 'corrigir'], true)) sd_fail('A IA não está trabalhando nesta tarefa.');
        ponte('status', ['controleId' => $td['controle_id'], 'status' => 'PAUSADO', 'rodada' => $td['rodada'],
            'obs' => 'Pausado por ' . $me['name']]);
        sessaoFechar($taskId);
        $drv = td_patch($taskId, ['status' => 'pausado', 'status_antes' => $td['status']]);
        $ch = avisarIA($taskId, 'PAUSAR', 'PAUSE esta tarefa' . (!empty($d['motivo']) ? ' — ' . $d['motivo'] : '') . '.');
        echo json_encode(['drive' => $drv, 'chamado' => $ch]);
        break;
    }

    // ▶ Retomar a IA de onde parou
    case 'retomar': {
        $td = td_get($taskId); if (!$td) sd_fail('Tarefa sem pasta do Drive.');
        if ($td['status'] !== 'pausado') sd_fail('A tarefa não está pausada.');
        $volta = in_array($td['status_antes'] ?? '', ['produzir', 'corrigir'], true) ? $td['status_antes'] : 'produzir';
        ponte('status', ['controleId' => $td['controle_id'], 'status' => strtoupper($volta), 'rodada' => $td['rodada'],
            'obs' => 'Retomado por ' . $me['name']]);
        sessaoAbrir($taskId, $me['id']);
        $drv = td_patch($taskId, ['status' => $volta]);
        $ch = avisarIA($taskId, 'RETOMAR', 'RETOME a tarefa de onde parou ('
            . ($volta === 'corrigir' ? 'correções da rodada ' . $td['rodada'] . ' — arquivo correcoes.xlsx na ENTRADA' : 'produção')
            . '). Confira o 00_BRIEFING_IA.txt: ele pode ter mudado.');
        echo json_encode(['drive' => $drv, 'chamado' => $ch]);
        break;
    }

    // ✉ Mensagem do gestor pra IA (resposta a dúvidas, ajuste de rumo…)
    case 'mensagem': {
        $td = td_get($taskId); if (!$td) sd_fail('Tarefa sem pasta do Drive.');
        $txt = trim((string)($d['texto'] ?? ''));
        if ($txt === '') sd_fail('Escreva a mensagem.');
        $ch = avisarIA($taskId, 'MENSAGEM', in_array($td['status'], ['produzir', 'corrigir'], true)
            ? 'Leia a mensagem do gestor abaixo e aplique no trabalho em andamento.'
            : 'Leia a mensagem do gestor abaixo. Só produza algo se ela pedir explicitamente.',
            ['texto' => mb_substr($txt, 0, 6000) . "
— " . $me['name']]);
        echo json_encode(['ok' => true, 'chamado' => $ch]);
        break;
    }

    // Importou a entrega no lote → revisão interna (ou direto pro cliente)
    case 'revisao': {
        $td = td_get($taskId); if (!$td) sd_fail('Tarefa sem pasta do Drive.');
        $campos = ['status' => ($d['liberado'] ?? false) ? 'liberado' : 'revisao'];
        if (!empty($d['set_id'])) $campos['set_id'] = $d['set_id'];
        ponte('status', ['controleId' => $td['controle_id'],
            'status' => $campos['status'] === 'liberado' ? 'COM O CLIENTE' : 'EM REVISÃO',
            'rodada' => $td['rodada'], 'obs' => 'Importado por ' . $me['name']]);
        echo json_encode(['drive' => td_patch($taskId, $campos)]);
        break;
    }

    // Gestor liberou: avisa o cliente (sino + push + e-mail com o link)
    case 'avisar_cliente': {
        $setId = (string)($d['set_id'] ?? '');
        $r = sd_supabase('/rest/v1/approval_sets?id=eq.' . urlencode($setId) . '&select=id,client_id,title');
        $set = $r['body'][0] ?? null;
        if (!$set) sd_fail('Lote não encontrado.', 404);
        $link = (string)($d['link'] ?? '');
        if (!preg_match('~^https://sd\.sergioduh\.com/~', $link)) $link = 'https://sd.sergioduh.com/app.html';
        $corrigido = !empty($d['corrigido']);
        $titulo = $corrigido ? '↺ Material corrigido pra aprovação' : '✓ Material pronto pra aprovação';
        $corpo = '"' . $set['title'] . '" ' . ($corrigido ? 'foi ajustado conforme o seu pedido e está pronto pra nova aprovação.'
                                                         : 'está pronto pra sua aprovação.');
        $n = avisarCliente($set['client_id'], $titulo, $corpo, $link);
        echo json_encode(['ok' => true, 'avisados' => $n]);
        break;
    }

    case 'liberar': {
        $td = td_get($taskId); if (!$td) sd_fail('Tarefa sem pasta do Drive.');
        ponte('status', ['controleId' => $td['controle_id'], 'status' => 'COM O CLIENTE',
            'rodada' => $td['rodada'], 'obs' => 'Liberado por ' . $me['name']]);
        echo json_encode(['drive' => td_patch($taskId, ['status' => 'liberado'])]);
        break;
    }

    // Grava o briefing completo (montado no SD) na raiz da pasta da tarefa
    case 'briefing': {
        $td = td_get($taskId); if (!$td) sd_fail('Tarefa sem pasta do Drive.');
        $b64 = (string)($d['b64'] ?? '');
        if (!$b64 || strlen($b64) > 2 * 1024 * 1024) sd_fail('Briefing inválido.');
        $txt = base64_decode($b64, true);
        if ($txt !== false && strpos($txt, PD_REGRA_SAIDA_TITULO) === false) {
            $b64 = base64_encode(rtrim($txt) . "\n\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n" . pdRegraSaida() . "\n");
        }
        ponte('gravar', ['folderId' => $td['folder_id'], 'nome' => '00_BRIEFING_IA.txt', 'b64' => $b64,
            'mime' => 'text/plain']);
        echo json_encode(['ok' => true]);
        break;
    }

    case 'salvar': {   // referência / revisão interna / tipo / instruções
        $campos = [];
        if (array_key_exists('tipo_entrega', $d)) $campos['tipo_entrega'] = trim((string)$d['tipo_entrega']) ?: null;
        if (array_key_exists('instrucoes_ia', $d)) $campos['instrucoes_ia'] = trim((string)$d['instrucoes_ia']) ?: null;
        if (array_key_exists('referencia_url', $d)) $campos['referencia_url'] = trim((string)$d['referencia_url']) ?: null;
        if (array_key_exists('revisao_interna', $d)) $campos['revisao_interna'] = (bool)$d['revisao_interna'];
        echo json_encode(['drive' => td_patch($taskId, $campos)]);
        break;
    }

    case 'desconectar': {
        $td = td_get($taskId);
        if ($td && in_array($td['status'], ['produzir', 'corrigir', 'pausado'], true))
            avisarIA($taskId, 'PARAR', 'PARE: esta tarefa foi desligada do SD.');
        sd_supabase('/rest/v1/task_drive?task_id=eq.' . urlencode($taskId), 'DELETE');
        echo json_encode(['ok' => true]);
        break;
    }

    case 'ping': {
        echo json_encode(ponte('ping'));
        break;
    }

    default:
        sd_fail('Ação desconhecida.', 400);
    }
} catch (Exception $e) {
    sd_fail($e->getMessage(), 502);
}
