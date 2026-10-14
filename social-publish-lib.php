<?php
// =====================================================================
// Pontodot — biblioteca de publicação (Instagram e Facebook)
//
// Todo o "como publicar de verdade" mora aqui. Usam esta biblioteca:
//   · o robô do relógio (social-publish-worker.php)
//   · o botão "Publicar agora" (social-publish-now.php)
//
// O QUE MUDOU (v59):
//   · Graph v25.0 com appsecret_proof (a v21 morre em jan/2027).
//   · ARTE DO DRIVE PUBLICA: a Meta precisa de um endereço público e
//     completo. Antes do post sair, cada arte vira um arquivo de verdade
//     em /pub-midia/ (nome impossível de adivinhar, apagado em 3 dias).
//     Vídeo sai inteiro — não a miniatura que o arte.php mostra na tela.
//   · Vídeo é reconhecido pelo tipo do arquivo, não só pela extensão.
//   · Story com várias telas: sai TODAS, na ordem.
//   · Carrossel aceita vídeo no meio; mais de 10 peças avisa antes.
//   · Facebook: stories (foto e vídeo) e reels de verdade.
//   · Peça que saiu de "aprovada" depois de agendada não publica.
//   · Erro avisa a equipe no celular; tentativas são contadas.
// =====================================================================

require_once __DIR__ . '/../sd-private/sd-config.php';

const META_VERSAO  = 'v25.0';
const PD_PUB_DIR   = __DIR__ . '/pub-midia';
const PD_PUB_DIAS  = 3;
const PD_PUB_MAX   = 300 * 1048576;     // 300 MB por arquivo
const PD_IG_LIMITE = 10;                // peças num carrossel do Instagram

function pd_base_url() {
    return rtrim(defined('PD_BASE_URL') ? PD_BASE_URL : 'https://sd.sergioduh.com', '/');
}
function pd_espera() { return defined('PD_ESPERA_SEG') ? (int)PD_ESPERA_SEG : 5; }

function meta_call($method, $path, $params = [], $timeout = 90) {
    $tok = $params['access_token'] ?? '';
    if ($tok && defined('SD_META_APP_SECRET') && SD_META_APP_SECRET) {
        $params['appsecret_proof'] = hash_hmac('sha256', $tok, SD_META_APP_SECRET);
    }
    $url = (defined('PD_META_BASE') ? PD_META_BASE : 'https://graph.facebook.com/') . META_VERSAO . $path;
    $ch = curl_init();
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout];
    if ($method === 'GET') {
        $opts[CURLOPT_URL] = $url . '?' . http_build_query($params);
    } else {
        $opts[CURLOPT_URL] = $url;
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($params);
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => json_decode((string)$raw, true)];
}

/** O motivo da Meta, curto e em português quando dá. */
function meta_motivo($r) {
    $e = $r['body']['error'] ?? null;
    if (!is_array($e)) return 'HTTP ' . ($r['code'] ?? '?');
    $cod = (int)($e['code'] ?? 0);
    if ($cod === 190) return 'o acesso à conta venceu — reconecte a Meta';
    if (in_array($cod, [10, 200, 294], true)) return 'a Meta não deu permissão para isso nesta conta';
    if (in_array($cod, [4, 17, 32, 613], true)) return 'limite de chamadas da Meta — tento de novo depois';
    $m = (string)($e['error_user_msg'] ?? $e['message'] ?? 'erro');
    return mb_substr($m, 0, 220);
}

function supa($path, $method, $body = null, $extra = []) {
    $ch = curl_init();
    $headers = array_merge(['apikey: ' . SD_SECRET_KEY, 'Content-Type: application/json'], $extra);
    curl_setopt_array($ch, [
        CURLOPT_URL => SD_SUPABASE_URL . $path, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => json_decode((string)$raw, true), 'raw' => $raw];
}

// =========================================================================
// AVISOS PRA EQUIPE (sino + push)
// =========================================================================
function pub_avisar_equipe($titulo, $corpo, $tipo = 'publicacao') {
    $r = supa('/rest/v1/profiles?role=eq.admin&active=eq.true&select=id', 'GET');
    $ids = array_values(array_filter(array_map(fn($p) => $p['id'] ?? null, (array)($r['body'] ?? []))));
    if (!$ids) return;
    supa('/rest/v1/notifications', 'POST', array_map(fn($id) => [
        'user_id' => $id, 'type' => $tipo, 'title' => mb_substr($titulo, 0, 140), 'body' => mb_substr($corpo, 0, 400), 'link' => null,
    ], $ids));
    if (!is_file(__DIR__ . '/push-lib.php')) return;
    if (!function_exists('sd_supabase')) {
        // a push-lib usa sd_supabase(); no relógio não tem sessão, empresta a do servidor
        function sd_supabase($p, $m = 'GET', $b = null, $e = []) { return supa($p, $m, $b, $e); }
    }
    require_once __DIR__ . '/push-lib.php';
    if (!function_exists('sd_push_usuario')) return;
    foreach ($ids as $id) @sd_push_usuario($id, $titulo, $corpo, '/index.html#publicacao', 'pd-' . $tipo);
}

function marcarErro($targetId, $mensagem, $tentativas = null, $avisar = true) {
    $dados = ['status' => 'erro', 'error_message' => mb_substr($mensagem, 0, 500)];
    if ($tentativas !== null) $dados['attempts'] = (int)$tentativas + 1;
    supa('/rest/v1/scheduled_post_targets?id=eq.' . urlencode($targetId), 'PATCH', $dados);
    error_log('Pontodot publish - erro no alvo ' . $targetId . ': ' . $mensagem);
    if ($avisar) pub_avisar_equipe('Não publicou', $mensagem, 'publicacao_erro');
}

function marcarPublicado($targetId, $postId, $platformPostId, $permalink, $tentativas = null) {
    $dados = ['status' => 'publicado', 'platform_post_id' => $platformPostId,
              'permalink' => $permalink, 'published_at' => gmdate('c'), 'error_message' => null];
    if ($tentativas !== null) $dados['attempts'] = (int)$tentativas + 1;
    supa('/rest/v1/scheduled_post_targets?id=eq.' . urlencode($targetId), 'PATCH', $dados);
    // Última peça do lote publicada = tarefa ENTREGUE. Só OLHA o que já
    // aconteceu; nunca dispara publicação nenhuma.
    verificarEAtualizarTarefa($postId);
}

/** "ap:<id>" ou "ap:<id>:copia" → <id> da peça de aprovação. */
function pd_item_do_lote($batchName) {
    if (strpos((string)$batchName, 'ap:') !== 0) return null;
    $id = explode(':', substr($batchName, 3))[0];
    return preg_match('/^[0-9a-f\-]{36}$/i', $id) ? $id : null;
}

// =========================================================================
// Lote todo publicado → tarefa vai para ENTREGUE sozinha.
// =========================================================================
function verificarEAtualizarTarefa($postId) {
    $p = supa('/rest/v1/scheduled_posts?id=eq.' . urlencode($postId) . '&select=batch_name', 'GET');
    $itemId = pd_item_do_lote($p['body'][0]['batch_name'] ?? '');
    if (!$itemId) return;

    $it = supa('/rest/v1/approval_items?id=eq.' . urlencode($itemId) . '&select=set_id', 'GET');
    $setId = $it['body'][0]['set_id'] ?? null;
    if (!$setId) return;

    $st = supa('/rest/v1/approval_sets?id=eq.' . urlencode($setId) . '&select=task_id,title', 'GET');
    $taskId = $st['body'][0]['task_id'] ?? null;
    if (!$taskId) return;

    $itens = supa('/rest/v1/approval_items?set_id=eq.' . urlencode($setId) . '&select=id', 'GET');
    $itemIds = array_values(array_filter(array_map(fn($i) => $i['id'] ?? null, (array)($itens['body'] ?? []))));
    if (!$itemIds) return;

    // Só os posts DESTE lote (antes: os 1000 primeiros de todos — e o
    // lote 1001 nunca virava ENTREGUE).
    $ou = implode(',', array_map(fn($id) => 'batch_name.like.ap:' . $id . '*', $itemIds));
    $posts = supa('/rest/v1/scheduled_posts?or=(' . $ou . ')&select=id', 'GET');
    $postIds = array_values(array_filter(array_map(fn($x) => $x['id'] ?? null, (array)($posts['body'] ?? []))));
    if (!$postIds) return;

    $targets = supa('/rest/v1/scheduled_post_targets?post_id=in.(' . implode(',', $postIds) . ')&select=status', 'GET');
    $statuses = array_map(fn($t) => $t['status'] ?? '', (array)($targets['body'] ?? []));
    if (!$statuses) return;

    $pendentes = array_filter($statuses, fn($s) => in_array($s, ['agendado', 'publicando'], true));
    if (empty($pendentes) && !in_array('erro', $statuses, true) && in_array('publicado', $statuses, true)) {
        supa('/rest/v1/tasks?id=eq.' . urlencode($taskId), 'PATCH', ['status' => 'ENTREGUE']);
        pub_avisar_equipe('Lote publicado', ($st['body'][0]['title'] ?? 'Lote') . ' saiu inteiro. A tarefa foi pra ENTREGUE.', 'publicacao_ok');
    }
}

// =========================================================================
// A ARTE VIRA ENDEREÇO PÚBLICO
// =========================================================================
const PD_EXT_VIDEO = '/\.(mp4|mov|m4v|webm)(\?|$)/i';

function pd_eh_video($m) {
    $mime = strtolower((string)($m['mime'] ?? $m['type'] ?? ''));
    if (strpos($mime, 'video/') === 0) return true;
    if (strpos($mime, 'image/') === 0) return false;
    foreach (['name', 'url', 'original'] as $k) if (preg_match(PD_EXT_VIDEO, (string)($m[$k] ?? ''))) return true;
    return false;
}

/** O id do arquivo no Drive, venha de onde vier o endereço. */
function pd_drive_id($m) {
    if (!empty($m['drive_id'])) return preg_replace('/[^A-Za-z0-9_\-]/', '', $m['drive_id']);
    foreach (['url', 'original', 'drive_url'] as $k) {
        $u = (string)($m[$k] ?? '');
        if (preg_match('#/arte\.php\?(?:.*&)?i=([A-Za-z0-9_\-]{10,})#', $u, $x)) return $x[1];
        if (preg_match('#drive\.google\.com/(?:file/d/|thumbnail\?id=|open\?id=|uc\?(?:.*&)?id=)([A-Za-z0-9_\-]{10,})#', $u, $x)) return $x[1];
    }
    return null;
}

function pd_extensao($m, $video) {
    $nome = strtolower((string)($m['name'] ?? ''));
    if ($video) return preg_match('/\.(mov)$/', $nome) ? 'mov' : 'mp4';
    return 'jpg';    // o Instagram só aceita JPEG; PNG/WebP são convertidos
}

/** Lista de mídias → [['url'=>público, 'video'=>bool], ...] */
function pd_midias_publicas($midias) {
    $out = [];
    foreach ((array)$midias as $m) {
        if (is_string($m)) $m = ['url' => $m];
        if (!is_array($m)) continue;
        $video = pd_eh_video($m);
        $id = pd_drive_id($m);
        if ($id) { $out[] = ['url' => pd_materializar($id, $video, $m), 'video' => $video]; continue; }
        $u = (string)($m['url'] ?? '');
        if ($u !== '' && $u[0] === '/') $u = pd_base_url() . $u;
        if (!preg_match('#^https?://#', $u)) throw new Exception('Uma das artes está sem endereço (' . ($m['name'] ?? '?') . ').');
        $out[] = ['url' => $u, 'video' => $video];
    }
    if (!$out) throw new Exception('Post sem nenhuma arte.');
    return $out;
}

/** Baixa o arquivo do Drive inteiro, uma vez, e devolve o endereço público. */
function pd_materializar($id, $video, $m = []) {
    if (!is_dir(PD_PUB_DIR)) @mkdir(PD_PUB_DIR, 0755, true);
    pd_pub_htaccess();
    $nome = substr(hash_hmac('sha256', 'pub:' . $id, SD_SECRET_KEY), 0, 32) . '.' . pd_extensao($m, $video);
    $final = PD_PUB_DIR . '/' . $nome;
    if (is_file($final) && filesize($final) > 0) { @touch($final); return pd_base_url() . '/pub-midia/' . $nome; }

    $tmp = PD_PUB_DIR . '/.baixando-' . bin2hex(random_bytes(6));
    try {
        pd_baixar_drive($id, $tmp);
        pd_conferir($tmp, $video, $m['name'] ?? $id);
        if (!$video) pd_para_jpeg($tmp);
        if (!@rename($tmp, $final)) throw new Exception('Não consegui guardar a arte para publicar.');
    } finally {
        if (is_file($tmp)) @unlink($tmp);
    }
    return pd_base_url() . '/pub-midia/' . $nome;
}

function pd_pub_htaccess() {
    $h = PD_PUB_DIR . '/.htaccess';
    if (is_file($h)) return;
    @file_put_contents($h, "Options -Indexes\n"
        . "<FilesMatch \"\\.(php|phtml|phar|html?|js|svg)$\">\n  Require all denied\n</FilesMatch>\n"
        . "<FilesMatch \"^\\.\">\n  Require all denied\n</FilesMatch>\n"
        . "AddType video/mp4 .mp4\nAddType video/quicktime .mov\nAddType image/jpeg .jpg\n");
}

/** Pela ponte (em pedaços, serve pra arquivo de qualquer pasta) e, se a
 *  ponte ainda não tiver a ação nova, pelo link público do Drive. */
function pd_baixar_drive($id, $destino) {
    $erroPonte = null;
    if (defined('SD_PONTE_URL') && defined('SD_PONTE_KEY') && SD_PONTE_KEY) {
        try { pd_baixar_pela_ponte($id, $destino); return; }
        catch (Exception $e) { $erroPonte = $e->getMessage(); @unlink($destino); }
    }
    $base = defined('PD_DRIVE_DL') ? PD_DRIVE_DL : 'https://drive.usercontent.google.com/download';
    $fp = fopen($destino, 'wb');
    $ch = curl_init($base . '?id=' . rawurlencode($id) . '&export=download&confirm=t');
    curl_setopt_array($ch, [CURLOPT_FILE => $fp, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 600,
        CURLOPT_USERAGENT => 'Pontodot/1.0', CURLOPT_MAXFILESIZE => PD_PUB_MAX]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch); fclose($fp);
    if ($code !== 200 || !filesize($destino)) {
        throw new Exception('Não consegui baixar a arte do Drive (' . ($erroPonte ?: 'HTTP ' . $code) . ').');
    }
}

function pd_baixar_pela_ponte($id, $destino) {
    $fp = fopen($destino, 'wb'); $ini = 0; $voltas = 0;
    try {
        do {
            $dados = json_encode(['acao' => 'baixar_parte', 'key' => SD_PONTE_KEY, 'fileId' => $id,
                                  'inicio' => $ini, 'tamanho' => 15 * 1048576]);
            $ch = curl_init(SD_PONTE_URL);
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $dados,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 170]);
            $j = json_decode((string)curl_exec($ch), true);
            curl_close($ch);
            if (!is_array($j) || empty($j['ok'])) throw new Exception(is_array($j) ? ($j['erro'] ?? 'ponte recusou') : 'ponte não respondeu');
            $bytes = base64_decode((string)($j['b64'] ?? ''), true);
            if ($bytes === false) throw new Exception('ponte devolveu pedaço estragado');
            fwrite($fp, $bytes);
            $ini += strlen($bytes);
            if ($ini > PD_PUB_MAX) throw new Exception('arquivo maior que 300 MB');
            $total = (int)($j['total'] ?? 0);
            if (++$voltas > 40) throw new Exception('arquivo grande demais');
        } while (empty($j['fim']) && strlen($bytes) > 0 && $ini < $total);
    } finally { fclose($fp); }
    if (!filesize($destino)) throw new Exception('arquivo vazio no Drive');
}

/** Garante que veio o arquivo — e não uma página de erro do Google. */
function pd_conferir($arq, $video, $nome) {
    $cab = (string)file_get_contents($arq, false, null, 0, 64);
    if (stripos(ltrim($cab), '<!doctype') === 0 || stripos(ltrim($cab), '<html') === 0) {
        throw new Exception('O Drive não liberou "' . $nome . '" (o arquivo precisa estar acessível pela ponte).');
    }
    if ($video && substr($cab, 4, 4) !== 'ftyp' && substr($cab, 0, 4) !== "\x1A\x45\xDF\xA3") {
        throw new Exception('"' . $nome . '" não parece um vídeo MP4/MOV.');
    }
    if (!$video && !@getimagesize($arq)) throw new Exception('"' . $nome . '" não parece uma imagem.');
}

function pd_para_jpeg($arq) {
    $info = @getimagesize($arq);
    if (!$info || $info[2] === IMAGETYPE_JPEG || !function_exists('imagecreatefromstring')) return;
    $img = @imagecreatefromstring((string)file_get_contents($arq));
    if (!$img) return;
    $w = imagesx($img); $h = imagesy($img);
    $fundo = imagecreatetruecolor($w, $h);
    imagefill($fundo, 0, 0, imagecolorallocate($fundo, 255, 255, 255));
    imagecopy($fundo, $img, 0, 0, 0, 0, $w, $h);
    imagejpeg($fundo, $arq, 92);
    imagedestroy($img); imagedestroy($fundo);
}

/** Apaga o que já foi publicado há mais de 3 dias. */
function pd_limpar_publicas() {
    if (!is_dir(PD_PUB_DIR)) return 0;
    $n = 0; $limite = time() - PD_PUB_DIAS * 86400;
    foreach (glob(PD_PUB_DIR . '/*') ?: [] as $f) {
        if (is_file($f) && filemtime($f) < $limite) { @unlink($f); $n++; }
    }
    foreach (glob(PD_PUB_DIR . '/.baixando-*') ?: [] as $f) if (filemtime($f) < time() - 3600) @unlink($f);
    return $n;
}

// =========================================================================
// A PEÇA APROVADA MANDA
//
// Ninguém edita o post agendado direto: quem muda é a peça do lote
// (correção aplicada, data nova, legenda nova). A cada volta do relógio,
// o que ainda não saiu é acertado pela peça — texto, artes e horário.
// Peça que tinha sido segurada (saiu de aprovada) e voltou a ser
// aprovada volta pra fila sozinha.
// =========================================================================

/** Data da peça → instante. Sem fuso = horário de Brasília. */
function pd_instante($v) {
    $v = trim((string)$v);
    if ($v === '') return null;
    try {
        if (preg_match('/(Z|[+\-]\d\d(:?\d\d)?)$/i', $v)) return (new DateTime($v))->getTimestamp();
        return (new DateTime($v, new DateTimeZone('America/Sao_Paulo')))->getTimestamp();
    } catch (Exception $e) { return null; }
}

function pd_legenda_da_peca($it) {
    $partes = array_filter([trim((string)($it['caption'] ?? '')) ?: trim((string)($it['headline'] ?? '')), trim((string)($it['hashtags'] ?? ''))]);
    return $partes ? implode("\n\n", $partes) : null;
}

function pd_reconciliar() {
    $r = supa('/rest/v1/scheduled_post_targets?or=(status.eq.agendado,and(status.eq.erro,error_message.like.Segurei*))'
        . '&select=id,status,post_id,scheduled_posts(id,batch_name,scheduled_at,caption,media_urls)', 'GET');
    if ($r['code'] !== 200) return ['erro' => 'HTTP ' . $r['code']];
    $posts = []; $segurados = [];
    foreach ((array)$r['body'] as $t) {
        $p = $t['scheduled_posts'] ?? null;
        if (!$p || !($id = pd_item_do_lote($p['batch_name'] ?? ''))) continue;
        $posts[$p['id']] = $p + ['_item' => $id];
        if ($t['status'] === 'erro') $segurados[] = ['alvo' => $t['id'], 'item' => $id];
    }
    if (!$posts) return ['acertados' => 0, 'voltaram' => 0];

    $ids = array_values(array_unique(array_column($posts, '_item')));
    $itens = [];
    foreach (array_chunk($ids, 60) as $lote) {
        $q = supa('/rest/v1/approval_items?id=in.(' . implode(',', $lote) . ')&select=id,status,caption,headline,hashtags,media,publish_at', 'GET');
        foreach ((array)($q['body'] ?? []) as $it) $itens[$it['id']] = $it;
    }

    $acertados = 0;
    foreach ($posts as $pid => $p) {
        $it = $itens[$p['_item']] ?? null;
        if (!$it) continue;
        $mudar = [];
        $leg = pd_legenda_da_peca($it);
        if ((string)$leg !== (string)($p['caption'] ?? '')) $mudar['caption'] = $leg;
        $media = is_array($it['media'] ?? null) ? $it['media'] : (json_decode((string)($it['media'] ?? ''), true) ?: []);
        if ($media && json_encode($media) !== json_encode($p['media_urls'] ?? [])) $mudar['media_urls'] = $media;
        $copia = substr_count((string)$p['batch_name'], ':') > 1;     // cópia de outra semana: a data é dela
        $quando = $copia ? null : pd_instante($it['publish_at'] ?? '');
        if ($quando && abs($quando - (int)pd_instante($p['scheduled_at'] ?? '')) > 59) $mudar['scheduled_at'] = gmdate('c', $quando);
        if ($mudar) {
            $u = supa('/rest/v1/scheduled_posts?id=eq.' . urlencode($pid), 'PATCH', $mudar);
            if ($u['code'] < 300) $acertados++;
        }
    }

    $voltaram = 0;
    foreach ($segurados as $s) {
        if (($itens[$s['item']]['status'] ?? '') !== 'aprovado') continue;
        $u = supa('/rest/v1/scheduled_post_targets?id=eq.' . urlencode($s['alvo']) . '&status=eq.erro', 'PATCH',
                  ['status' => 'agendado', 'error_message' => null]);
        if ($u['code'] < 300) $voltaram++;
    }
    return ['acertados' => $acertados, 'voltaram' => $voltaram];
}

// =========================================================================
// AGENDAR SOZINHO
// Cliente com "agendar sozinho" ligado: toda peça APROVADA de social, com
// data ainda por vir, num lote que o cliente vê, entra na fila nas contas
// escolhidas. Peça sem data, ou com a data já passada, espera alguém.
// =========================================================================
const PD_KIND_PLACEMENT = ['feed' => 'feed', 'carrossel' => 'feed', 'stories' => 'stories', 'reels' => 'reels'];

function pd_agendar_sozinho() {
    $cfg = supa('/rest/v1/pd_publicacao?agendar_sozinho=eq.true&select=client_id,contas', 'GET');
    if ($cfg['code'] !== 200) return ['agendadas' => 0, 'sem_tabela' => true];
    $total = 0; $porCliente = [];
    // "agora - 30 min", no relógio de Brasília e com o fuso escrito:
    // vale tanto se a coluna guarda fuso quanto se não guarda.
    $desde = (new DateTime('@' . (time() - 1800)))->setTimezone(new DateTimeZone('America/Sao_Paulo'))->format('Y-m-d\TH:i:sP');

    foreach ((array)$cfg['body'] as $c) {
        $cid = $c['client_id'] ?? ''; $contas = array_values(array_filter((array)($c['contas'] ?? [])));
        if (!$cid || !$contas) continue;
        $ac = supa('/rest/v1/social_accounts?id=in.(' . implode(',', array_map('rawurlencode', $contas)) . ')&client_id=eq.' . urlencode($cid)
            . '&status=eq.conectada&select=id,platform', 'GET');
        $alvos = array_values(array_filter((array)($ac['body'] ?? []), fn($a) => in_array($a['platform'] ?? '', ['instagram', 'facebook'], true)));
        if (!$alvos) continue;

        $ss = supa('/rest/v1/approval_sets?client_id=eq.' . urlencode($cid) . '&interno=not.is.true&select=id&order=created_at.desc&limit=80', 'GET');
        $sets = array_values(array_filter(array_map(fn($x) => $x['id'] ?? null, (array)($ss['body'] ?? []))));
        if (!$sets) continue;

        $it = supa('/rest/v1/approval_items?set_id=in.(' . implode(',', $sets) . ')&status=eq.aprovado&kind=in.(feed,carrossel,stories,reels)'
            . '&publish_at=gte.' . rawurlencode($desde) . '&select=id,kind,caption,headline,hashtags,media,publish_at', 'GET');
        $itens = array_values(array_filter((array)($it['body'] ?? []), fn($i) => !empty($i['id']) && pd_instante($i['publish_at'] ?? '') >= time() - 1800));
        if (!$itens) continue;

        $ja = supa('/rest/v1/scheduled_posts?batch_name=in.(' . implode(',', array_map(fn($i) => 'ap:' . $i['id'], $itens)) . ')&select=batch_name', 'GET');
        $tem = array_flip(array_map(fn($x) => $x['batch_name'] ?? '', (array)($ja['body'] ?? [])));

        foreach ($itens as $i) {
            if (isset($tem['ap:' . $i['id']])) continue;
            $media = is_array($i['media'] ?? null) ? $i['media'] : (json_decode((string)($i['media'] ?? ''), true) ?: []);
            if (!$media) continue;
            $p = supa('/rest/v1/scheduled_posts', 'POST', [
                'client_id' => $cid, 'batch_name' => 'ap:' . $i['id'], 'caption' => pd_legenda_da_peca($i),
                'media_urls' => $media, 'post_type' => $i['kind'], 'scheduled_at' => gmdate('c', pd_instante($i['publish_at'])),
            ], ['Prefer: return=representation']);
            $pid = $p['body'][0]['id'] ?? null;
            if ($p['code'] >= 300 || !$pid) { error_log('Pontodot agendar sozinho - post ' . $i['id'] . ': HTTP ' . $p['code']); continue; }
            $place = PD_KIND_PLACEMENT[$i['kind']] ?? 'feed';
            $t = supa('/rest/v1/scheduled_post_targets', 'POST', array_map(fn($a) => [
                'post_id' => $pid, 'social_account_id' => $a['id'], 'platform' => $a['platform'], 'placement' => $place,
            ], $alvos));
            if ($t['code'] >= 300) { supa('/rest/v1/scheduled_posts?id=eq.' . urlencode($pid), 'DELETE'); continue; }
            $total++; $porCliente[$cid] = ($porCliente[$cid] ?? 0) + 1;
        }
    }
    if ($total) {
        $nomes = [];
        $cl = supa('/rest/v1/clients?id=in.(' . implode(',', array_keys($porCliente)) . ')&select=id,name', 'GET');
        foreach ((array)($cl['body'] ?? []) as $x) $nomes[] = ($x['name'] ?? '?') . ' (' . ($porCliente[$x['id']] ?? 0) . ')';
        pub_avisar_equipe('📅 ' . $total . ' peça(s) aprovada(s) entraram na fila', 'Agendadas sozinhas: ' . (implode(', ', $nomes) ?: 'clientes com agendar sozinho ligado') . '.', 'publicacao_fila');
    }
    return ['agendadas' => $total];
}

// =========================================================================
// Publica UM alvo — usado pelo relógio e pelo botão manual.
// =========================================================================
function publicarAlvo($t) {
    $targetId = $t['id'];
    $post = $t['scheduled_posts'] ?? [];
    $tent = $t['attempts'] ?? 0;
    $legenda = trim((string)($t['caption_override'] ?? $post['caption'] ?? ''));

    // Peça de lote: só sai se continua APROVADA (o cliente pode ter
    // pedido ajuste depois do agendamento).
    $batch = $post['batch_name'] ?? null;
    if ($batch === null && !empty($post['id'])) {
        $b = supa('/rest/v1/scheduled_posts?id=eq.' . urlencode($post['id']) . '&select=batch_name', 'GET');
        $batch = $b['body'][0]['batch_name'] ?? '';
    }
    $itemId = pd_item_do_lote($batch);
    if ($itemId) {
        $it = supa('/rest/v1/approval_items?id=eq.' . urlencode($itemId) . '&select=status,title,caption,headline,hashtags,media', 'GET');
        $stIt = $it['body'][0]['status'] ?? null;
        // o que sai é a versão ATUAL da peça (correção aplicada depois do agendamento vale)
        if (!empty($it['body'][0])) {
            $peca = $it['body'][0];
            if (empty($t['caption_override'])) $legenda = (string)pd_legenda_da_peca($peca);
            $mp = is_array($peca['media'] ?? null) ? $peca['media'] : json_decode((string)($peca['media'] ?? ''), true);
            if ($mp) $post['media_urls'] = $mp;
        }
        if ($stIt && $stIt !== 'aprovado') {
            marcarErro($targetId, 'Segurei: "' . ($it['body'][0]['title'] ?? 'a peça') . '" não está mais aprovada (' . $stIt
                . '). Quando aprovarem de novo, a data atualiza e ela volta pra fila.', $tent);
            return false;
        }
    }

    $conta = supa('/rest/v1/social_accounts?id=eq.' . urlencode($t['social_account_id'])
        . '&select=external_account_id,account_name', 'GET');
    $idMeta = $conta['body'][0]['external_account_id'] ?? null;
    $nomeConta = $conta['body'][0]['account_name'] ?? 'conta';
    if (!$idMeta) { marcarErro($targetId, 'Não encontrei a conta na Meta. Reconecte em Redes sociais.', $tent); return false; }

    $tk = supa('/rest/v1/social_account_tokens?social_account_id=eq.' . urlencode($t['social_account_id'])
        . '&select=access_token', 'GET');
    $token = $tk['body'][0]['access_token'] ?? null;
    if (!$token) { marcarErro($targetId, 'Sem chave de acesso para ' . $nomeConta . '. Reconecte a Meta.', $tent); return false; }

    try {
        $midias = pd_midias_publicas($post['media_urls'] ?? []);
        $placement = $t['placement'] ?: 'feed';
        if ($t['platform'] === 'instagram') {
            [$pid, $permalink] = publicarInstagram($idMeta, $midias, $legenda, $placement, $token);
        } else {
            [$pid, $permalink] = publicarFacebook($idMeta, $midias, $legenda, $placement, $token);
        }
        marcarPublicado($targetId, $post['id'], $pid, $permalink, $tent);
        return true;
    } catch (Exception $e) {
        $plat = $t['platform'] === 'instagram' ? 'Instagram' : 'Facebook';
        marcarErro($targetId, $plat . ' · ' . $nomeConta . ': ' . $e->getMessage(), $tent);
        return false;
    }
}

// =========================================================================
// INSTAGRAM
// =========================================================================
function ig_container($ig, $params, $token, $oque) {
    $r = meta_call('POST', "/{$ig}/media", $params + ['access_token' => $token]);
    if ($r['code'] !== 200 || empty($r['body']['id'])) throw new Exception('não aceitou ' . $oque . ' (' . meta_motivo($r) . ')');
    return $r['body']['id'];
}

function ig_esperar($id, $token, $video) {
    $voltas = $video ? 60 : 18;
    for ($i = 0; $i < $voltas; $i++) {
        $s = meta_call('GET', "/{$id}", ['fields' => 'status_code', 'access_token' => $token]);
        $sc = $s['body']['status_code'] ?? '';
        if ($sc === 'FINISHED') return;
        if ($sc === 'ERROR' || $sc === 'EXPIRED') throw new Exception('a Meta não conseguiu processar ' . ($video ? 'o vídeo' : 'a imagem')
            . ' (formato, tamanho ou proporção fora do aceito)');
        if ($i < $voltas - 1) sleep(pd_espera());
    }
    throw new Exception('a Meta ainda está processando ' . ($video ? 'o vídeo' : 'a mídia') . '. Use "Tentar de novo" em alguns minutos.');
}

function ig_publicar($ig, $creation, $token) {
    $p = meta_call('POST', "/{$ig}/media_publish", ['creation_id' => $creation, 'access_token' => $token]);
    if ($p['code'] !== 200 || empty($p['body']['id'])) throw new Exception('não publicou (' . meta_motivo($p) . ')');
    return $p['body']['id'];
}

function publicarInstagram($ig, $midias, $legenda, $placement, $token) {
    // STORIES: cada tela é um story, na ordem
    if ($placement === 'stories') {
        $ids = [];
        foreach ($midias as $n => $m) {
            $c = ig_container($ig, ['media_type' => 'STORIES', ($m['video'] ? 'video_url' : 'image_url') => $m['url']],
                              $token, 'a tela ' . ($n + 1) . ' do story');
            ig_esperar($c, $token, $m['video']);
            $ids[] = ig_publicar($ig, $c, $token);
        }
        return [implode(',', $ids), null];
    }

    if (count($midias) > 1) {
        if (count($midias) > PD_IG_LIMITE) {
            throw new Exception('carrossel com ' . count($midias) . ' peças — o Instagram aceita até ' . PD_IG_LIMITE . '. Divida em dois posts.');
        }
        $filhos = [];
        foreach ($midias as $n => $m) {
            $p = $m['video'] ? ['media_type' => 'VIDEO', 'video_url' => $m['url']] : ['image_url' => $m['url']];
            $c = ig_container($ig, $p + ['is_carousel_item' => 'true'], $token, 'a peça ' . ($n + 1) . ' do carrossel');
            if ($m['video']) ig_esperar($c, $token, true);
            $filhos[] = $c;
        }
        $c = ig_container($ig, ['media_type' => 'CAROUSEL', 'children' => implode(',', $filhos), 'caption' => $legenda],
                          $token, 'o carrossel');
        ig_esperar($c, $token, false);
    } elseif ($midias[0]['video']) {
        $c = ig_container($ig, ['media_type' => 'REELS', 'video_url' => $midias[0]['url'], 'caption' => $legenda],
                          $token, 'o reels');
        ig_esperar($c, $token, true);
    } else {
        $c = ig_container($ig, ['image_url' => $midias[0]['url'], 'caption' => $legenda], $token, 'a imagem');
        ig_esperar($c, $token, false);
    }

    $mediaId = ig_publicar($ig, $c, $token);
    $perm = meta_call('GET', "/{$mediaId}", ['fields' => 'permalink', 'access_token' => $token]);
    return [$mediaId, $perm['body']['permalink'] ?? null];
}

// =========================================================================
// FACEBOOK
// =========================================================================

/** Vídeo em 3 fases (reels e stories): começa, a Meta busca o arquivo
 *  pelo endereço público, termina. */
function fb_video_fases($page, $rota, $fileUrl, $token, $fim = []) {
    $ini = meta_call('POST', "/{$page}/{$rota}", ['upload_phase' => 'start', 'access_token' => $token]);
    $vid = $ini['body']['video_id'] ?? null;
    if ($ini['code'] !== 200 || !$vid) throw new Exception('não abriu o envio do vídeo (' . meta_motivo($ini) . ')');
    $up = $ini['body']['upload_url'] ?? ('https://rupload.facebook.com/video-upload/' . META_VERSAO . '/' . $vid);

    $ch = curl_init($up);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => '', CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 300,
        CURLOPT_HTTPHEADER => ['Authorization: OAuth ' . $token, 'file_url: ' . $fileUrl]]);
    $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $j = json_decode((string)$raw, true);
    if ($code !== 200 || empty($j['success'])) throw new Exception('a Meta não conseguiu buscar o vídeo (' . meta_motivo(['code' => $code, 'body' => $j]) . ')');

    $f = meta_call('POST', "/{$page}/{$rota}", $fim + ['upload_phase' => 'finish', 'video_id' => $vid, 'access_token' => $token]);
    if ($f['code'] !== 200 || (empty($f['body']['success']) && empty($f['body']['post_id']))) {
        throw new Exception('não terminou o envio do vídeo (' . meta_motivo($f) . ')');
    }
    return $f['body']['post_id'] ?? $vid;
}

function fb_foto_escondida($page, $url, $token) {
    $r = meta_call('POST', "/{$page}/photos", ['url' => $url, 'published' => 'false', 'access_token' => $token]);
    if ($r['code'] !== 200 || empty($r['body']['id'])) throw new Exception('não aceitou a foto (' . meta_motivo($r) . ')');
    return $r['body']['id'];
}

function publicarFacebook($page, $midias, $legenda, $placement, $token) {
    if ($placement === 'stories') {
        $ids = [];
        foreach ($midias as $m) {
            if ($m['video']) { $ids[] = fb_video_fases($page, 'video_stories', $m['url'], $token); continue; }
            $foto = fb_foto_escondida($page, $m['url'], $token);
            $r = meta_call('POST', "/{$page}/photo_stories", ['photo_id' => $foto, 'access_token' => $token]);
            if ($r['code'] !== 200 || (empty($r['body']['success']) && empty($r['body']['post_id']))) {
                throw new Exception('não publicou o story (' . meta_motivo($r) . ')');
            }
            $ids[] = $r['body']['post_id'] ?? $foto;
        }
        return [implode(',', $ids), 'https://www.facebook.com/' . $page];
    }

    if (count($midias) === 1 && $midias[0]['video']) {
        if ($placement === 'reels') {
            $id = fb_video_fases($page, 'video_reels', $midias[0]['url'], $token,
                                 ['video_state' => 'PUBLISHED', 'description' => $legenda]);
            return [$id, 'https://www.facebook.com/reel/' . $id];
        }
        $r = meta_call('POST', "/{$page}/videos", ['file_url' => $midias[0]['url'], 'description' => $legenda, 'access_token' => $token], 300);
        if ($r['code'] !== 200 || empty($r['body']['id'])) throw new Exception('não publicou o vídeo (' . meta_motivo($r) . ')');
        return [$r['body']['id'], 'https://www.facebook.com/' . $r['body']['id']];
    }

    if (count($midias) > 1) {
        foreach ($midias as $m) if ($m['video']) {
            throw new Exception('post com várias peças no Facebook só aceita fotos. Publique o vídeo separado ou tire o Facebook deste post.');
        }
        $params = ['message' => $legenda, 'access_token' => $token];
        foreach ($midias as $i => $m) $params["attached_media[$i]"] = json_encode(['media_fbid' => fb_foto_escondida($page, $m['url'], $token)]);
        $r = meta_call('POST', "/{$page}/feed", $params);
    } else {
        $r = meta_call('POST', "/{$page}/photos", ['url' => $midias[0]['url'], 'caption' => $legenda, 'access_token' => $token]);
    }
    if ($r['code'] !== 200 || empty($r['body']['id'])) throw new Exception('não publicou (' . meta_motivo($r) . ')');
    $postId = $r['body']['post_id'] ?? $r['body']['id'];
    return [$postId, 'https://www.facebook.com/' . $postId];
}
