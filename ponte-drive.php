<?php
require_once __DIR__ . '/sd-base.php';
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
require_once __DIR__ . '/pd-lib.php';

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
        pdEvento($taskId, 'entrega_ia', 'O GPT entregou', ((int)($d['arquivos'] ?? 0)) . ' arquivo(s) na SAIDA · rodada ' . ($td['rodada'] ?? 1), 'GPT', 'produtor');
        pdBatata($taskId, 'robo');
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
    if (!empty($set['task_id']) && $ok) pdEvento($set['task_id'], 'aprovacao', 'Cliente aprovou ' . $ok . ' peça(s)',
        $aj ? $aj . ' com pedido de ajuste' : null, 'Cliente', 'cliente', ['set_id' => $set['id']]);
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
        pdEvento($set['task_id'], 'ajuste', 'Cliente pediu ajuste', $n . ' peça(s) — a correção já foi pro GPT', 'Cliente', 'cliente', ['set_id' => $set['id']]);
        pdBatata($set['task_id'], 'gpt');
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
        pdEvento($tid, 'nota', 'O robô não conseguiu montar sozinho', $motivo, 'Robô', 'robo');
        pdBatata($tid, 'humano');
        pdAlarme('revisar_entrega', 'Entrega precisa de você — ' . $tid, $motivo ?: 'importe a entrega na mão', $tid, 'task:' . $tid);
    } elseif ($tipo === 'lote') {
        if ($direto && $set && !empty($set['share_token'])) {
            $avisouCliente = avisarCliente($set['client_id'], '✓ Material pronto pra aprovação', '"' . $set['title'] . '" está pronto pra sua aprovação.',
                sd_base() . '/aprovacao-preview.php?t=' . $set['share_token']);
            avisarGestor($t, '📦 Lote enviado ao cliente — ' . $n . ' peça(s)', $quem . ' — a revisão interna desta tarefa está desligada, então foi direto.', 'drive_entrega');
            pdEvento($tid, 'liberado', 'Lote montado e enviado ao cliente', $n . ' peça(s)', 'Robô', 'robo', ['set_id' => $setId]);
            pdBatata($tid, 'cliente');
        } else {
            avisarGestor($t, '📦 Lote pronto pra revisar — ' . $n . ' peça(s)', $quem . ' — só a equipe vê. Comente, mande correções pra IA ou libere pro cliente.', 'drive_entrega');
            pdEvento($tid, 'lote', 'Lote montado — em revisão interna', $n . ' peça(s)', 'Robô', 'robo', ['set_id' => $setId]);
            pdBatata($tid, 'humano');
            pdAlarme('aprovar_lote', 'Lote pronto pra revisar — ' . $tid, $quem . ' · ' . $n . ' peça(s)', $tid, $setId ? '/aprovacao.html?set=' . $setId : 'task:' . $tid);
        }
    } else {
        if ($direto && $set) {
            sd_supabase('/rest/v1/approval_sets?id=eq.' . urlencode($setId), 'PATCH', ['interno' => false]);
            td_patch($tid, ['status' => 'liberado']);
            $link = !empty($set['share_token']) ? sd_base() . '/aprovacao-preview.php?t=' . $set['share_token'] : sd_base() . '/app.html';
            $avisouCliente = avisarCliente($set['client_id'], '↺ Material corrigido pra aprovação',
                '"' . $set['title'] . '" foi ajustado conforme o seu pedido e está pronto pra nova aprovação.', $link);
            avisarGestor($t, '↺ Correção aplicada e enviada ao cliente — ' . $n . ' peça(s)', $quem, 'drive_entrega');
            pdEvento($tid, 'liberado', 'Correção aplicada e enviada ao cliente', $n . ' peça(s)', 'Robô', 'robo', ['set_id' => $setId]);
            pdBatata($tid, 'cliente');
        } else {
            avisarGestor($t, '↺ Correção da IA aplicada — ' . $n . ' peça(s)', $quem . ' — versão nova no lote, só a equipe vê. Confira e libere pro cliente.', 'drive_entrega');
            pdEvento($tid, 'correcao', 'Correção do GPT aplicada no lote', $n . ' peça(s) — em revisão interna', 'Robô', 'robo', ['set_id' => $setId]);
            pdBatata($tid, 'humano');
            pdAlarme('aprovar_lote', 'Correção pronta pra revisar — ' . $tid, $n . ' peça(s) atualizadas', $tid, $setId ? '/aprovacao.html?set=' . $setId : 'task:' . $tid);
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
        // peça FINAL anexada no chat: vai pra SAIDA e segue o mesmo caminho da entrega da IA
        // (o leitor da pasta monta os posts separados, com tipo, título e legenda)
        if (($d['destino'] ?? '') === 'saida') {
            if (!$td['saida_id']) sd_fail('Essa tarefa não tem pasta SAIDA.');
            $g = ponte('gravar', ['folderId' => $td['saida_id'], 'nome' => mb_substr($nome, 0, 120),
                'b64' => $b64, 'mime' => (string)($d['mime'] ?? 'application/octet-stream'), 'substituir' => true]);
            echo json_encode(['ok' => true, 'arquivo' => $g, 'virou' => 'saida']);
            break;
        }
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
        pdEvento($taskId, 'chamado_ia', 'Chamado de produção enviado', null, 'Pontodot', 'sistema');
        pdBatata($taskId, 'gpt');
        $ch = avisarIA($taskId, 'PRODUZIR',
            'Nova tarefa. Leia o 00_BRIEFING_IA.txt, use os arquivos da ENTRADA e das referências e produza a entrega completa.'
            . (!empty($td['instrucoes_ia']) ? ' Instruções extras: ' . $td['instrucoes_ia'] : ''),
            ['rodada' => $rodada, 'prazo' => $d['prazo'] ?? null]);
        echo json_encode(['drive' => $drv, 'chamado' => $ch]);
        break;
    }

    // Reenviar pro visualizador: marca a tarefa como entregue de novo e solta o robô.
    // Se já existe lote, o robô atualiza o que existe; se não, monta do zero a partir da pasta.
    case 'reenfileirar': {
        $td = td_get($taskId); if (!$td) sd_fail('Essa tarefa ainda não tem pasta do Drive ligada.');
        // link de ARQUIVO (não de pasta): a ponte baixa (Slides/Docs/PPTX viram PDF) e grava na SAIDA da tarefa
        if (!empty($d['file_url'])) {
            if (!preg_match('~/d/([A-Za-z0-9_-]{15,})|[?&]id=([A-Za-z0-9_-]{15,})~', (string)$d['file_url'], $mm)) sd_fail('Não reconheci esse link de arquivo do Drive.');
            $fid = $mm[1] ?: $mm[2];
            try {   // copia DENTRO do Drive: sem baixar, qualquer tamanho
                ponte('copiar', ['fileId' => $fid, 'folderId' => $td['saida_id']]);
            } catch (Exception $e) {
                $m = $e->getMessage();
                if (strpos($m, 'Ação desconhecida') === false) sd_fail($m . ' — confira se o arquivo está compartilhado com a conta da ponte do Drive.');
                // script da ponte antigo (sem "copiar"): cai no caminho de baixar+gravar, que vai até 30 MB
                try { $b = ponte('baixar', ['fileId' => $fid]); }
                catch (Exception $e2) { sd_fail($e2->getMessage() . ' — pra arquivo grande, atualize o script da ponte no Apps Script (SD-Ponte.gs novo, nova implantação).'); }
                ponte('gravar', ['folderId' => $td['saida_id'], 'nome' => mb_substr((string)$b['name'], 0, 120),
                    'b64' => $b['b64'], 'mime' => (string)($b['mime'] ?? 'application/octet-stream'), 'substituir' => true]);
            }
        }
        $lista = ponte('listar', ['folderId' => $td['saida_id']]);
        $n = count($lista['arquivos'] ?? []);
        if (!$n) {   // peças soltas na pasta que a pessoa indicou (fora da SAIDA): conta o que for entrega
            $raiz = ponte('listar', ['folderId' => $td['folder_id']]);
            foreach (($raiz['arquivos'] ?? []) as $a) {
                $nm = (string)($a['name'] ?? '');
                if (preg_match('/^(SD_CONTROLE|00_|DUVIDAS)/i', $nm) || strpos($nm, '(SD temporário)') !== false) continue;
                if (preg_match('/^ENTRADA\//i', (string)($a['path'] ?? ''))) continue;
                $n++;
            }
        }
        if (!$n) sd_fail('Não achei arquivo nenhum nessa pasta. Confira se ela está compartilhada com a conta da ponte do Drive, ou anexe as peças aqui no chat.');
        $drv = td_patch($taskId, ['status' => 'entregue', 'ultima_entrega' => date('c'),
            'arquivos_saida' => $n, 'robo' => null, 'robo_em' => null]);
        sessaoFechar($taskId);
        echo json_encode(['ok' => true, 'arquivos' => $n, 'drive' => $drv]);
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

    // pedaço de arquivo grande (o navegador monta): a ponte baixar não passa de 30 MB
    case 'baixar_parte': {
        if (empty($d['file_id'])) sd_fail('Arquivo não informado.');
        echo json_encode(ponte('baixar_parte', ['fileId' => $d['file_id'], 'inicio' => (int)($d['inicio'] ?? 0),
            'tamanho' => min(max((int)($d['tamanho'] ?? 8388608), 1048576), 12582912)]));
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
        pdEvento($taskId, 'correcao', 'Correções mandadas pro GPT', ($d['resumo'] ?? null), $me['name'], 'humano');
        pdBatata($taskId, 'gpt');
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
        if (!preg_match('~^https://(sd\.sergioduh\.com|pontodot\.ai|www\.pontodot\.ai)/~', $link)) $link = sd_base() . '/app.html';
        $corrigido = !empty($d['corrigido']);
        $titulo = $corrigido ? '↺ Material corrigido pra aprovação' : '✓ Material pronto pra aprovação';
        $corpo = '"' . $set['title'] . '" ' . ($corrigido ? 'foi ajustado conforme o seu pedido e está pronto pra nova aprovação.'
                                                         : 'está pronto pra sua aprovação.');
        $n = avisarCliente($set['client_id'], $titulo, $corpo, $link);
        if (!empty($d['task_id'])) {
            pdEvento($d['task_id'], 'liberado', $corrigido ? 'Versão corrigida liberada pro cliente' : 'Lote liberado pro cliente', null, $me['name'], 'humano', ['set_id' => $setId]);
            pdBatata($d['task_id'], 'cliente');
        }
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
