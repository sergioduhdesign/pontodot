<?php
// =====================================================================
// Pontodot — O WHATSAPP AVISA: entregou? leu? falhou?
//   GET  = a Meta conferindo o endereço (uma vez, na configuração)
//   POST = os status de cada mensagem enviada (sent/delivered/read/failed)
// sd-config.php:
//   define('SD_ZAP_VERIFY', 'uma-senha-que-voce-inventa');   // a mesma vai no painel da Meta
//   define('SD_ZAP_APP_SECRET', '...');   // opcional: segredo do app do WhatsApp (se não for o mesmo SD_META_APP_SECRET)
// =====================================================================
require_once __DIR__ . '/sd-auth.php';
require_once __DIR__ . '/pd-lib.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $tok = (string)($_GET['hub_verify_token'] ?? ''); $des = (string)($_GET['hub_challenge'] ?? '');
    if (($_GET['hub_mode'] ?? '') === 'subscribe' && defined('SD_ZAP_VERIFY') && SD_ZAP_VERIFY !== ''
        && hash_equals(SD_ZAP_VERIFY, $tok) && preg_match('/^[A-Za-z0-9_\-]{1,200}$/', $des)) { header('Content-Type: text/plain'); echo $des; exit; }
    http_response_code(403); echo 'nao'; exit;
}

$cru = file_get_contents('php://input');
$segs = array_values(array_filter([defined('SD_ZAP_APP_SECRET') ? SD_ZAP_APP_SECRET : '', defined('SD_META_APP_SECRET') ? SD_META_APP_SECRET : '']));
$assin = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
$vale = false;
foreach ($segs as $seg) if ($assin && hash_equals('sha256=' . hash_hmac('sha256', (string)$cru, $seg), $assin)) { $vale = true; break; }
if (!$vale) { error_log('wa-webhook: assinatura não confere (confira SD_ZAP_APP_SECRET = segredo do app do WhatsApp)'); http_response_code(403); echo 'assinatura'; exit; }
http_response_code(200); header('Content-Type: application/json'); echo '{"ok":true}';
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();

$j = json_decode((string)$cru, true);
if (!is_array($j)) exit;
foreach ((array)($j['entry'] ?? []) as $e) foreach ((array)($e['changes'] ?? []) as $c) {
    // ---- mensagens que CHEGAM (alguém respondeu o número da plataforma) ----
    $nomes = [];
    foreach ((array)($c['value']['contacts'] ?? []) as $ct) $nomes[preg_replace('/\D/', '', (string)($ct['wa_id'] ?? ''))] = $ct['profile']['name'] ?? null;
    foreach ((array)($c['value']['messages'] ?? []) as $m) {
        $id = (string)($m['id'] ?? ''); $de = preg_replace('/\D/', '', (string)($m['from'] ?? ''));
        if ($de === '' && !empty($m['from_user_id'])) $de = (string)$m['from_user_id'];   // sem telefone (nome de usuário): guarda pelo ID e não responde
        if ($id === '' || $de === '') continue;
        $ja = sd_supabase('/rest/v1/pd_avisos_log?wamid=eq.' . rawurlencode($id) . '&select=id&limit=1');
        if (!empty($ja['body'])) continue;                                   // a Meta reenvia: não duplica
        $t = $m['type'] ?? 'text';
        $txt = $t === 'text' ? ($m['text']['body'] ?? '')
             : ($t === 'button' ? ($m['button']['text'] ?? '')
             : ($t === 'interactive' ? ($m['interactive']['button_reply']['title'] ?? ($m['interactive']['list_reply']['title'] ?? '')) : ''));
        if ($txt === '') $txt = '[' . ($t === 'image' ? 'imagem' : ($t === 'audio' ? 'áudio' : ($t === 'document' ? 'documento' : ($t === 'video' ? 'vídeo' : $t)))) . ']' . (!empty($m[$t]['caption']) ? ' ' . $m[$t]['caption'] : '');
        // mídia (imagem, áudio, vídeo, documento, figurinha): baixa da Meta e guarda no Storage
        $mid = ['path' => null, 'tipo' => null, 'nome' => null, 'mime' => null];
        if (in_array($t, ['image', 'audio', 'video', 'document', 'sticker'], true) && !empty($m[$t]['id']) && defined('SD_ZAP_TOKEN') && SD_ZAP_TOKEN) {
            try {
                $bx = pdMidiaBaixar((string)$m[$t]['id']);
                if ($bx) {
                    $mime = $bx[1] ?: (string)($m[$t]['mime_type'] ?? '');
                    $nome = (string)($m[$t]['filename'] ?? '');
                    $pth = 'in/' . preg_replace('/\D/', '', $de) . '/' . gmdate('Ymd-His') . '-' . substr(preg_replace('/\W/', '', $id), -8) . '.' . pdExtMime($mime);
                    if (pdStorageSobe($pth, $bx[0], $mime)) $mid = ['path' => $pth, 'tipo' => $t, 'nome' => $nome ?: null, 'mime' => $mime];
                }
            } catch (Throwable $e) { error_log('wa-webhook midia: ' . $e->getMessage()); }
        }
        $para = ctype_digit($de) ? pdZapNormaliza($de) : $de;
        // quem é? (por número: equipe ou cliente)
        $quem = $nomes[$de] ?? null; $cid = null; $membro = null; $clRow = null;
        // o MESMO número pode estar em mais de um cadastro (teste, ou gente que divide o zap): quem fala agora é a "persona" escolhida
        $candidatos = [];
        foreach ((array)(sd_supabase('/rest/v1/profiles?zap=not.is.null&active=eq.true&select=id,name,email,zap,team_key,role')['body'] ?? []) as $p) if (pdZapNormaliza(preg_replace('/\D/', '', (string)$p['zap'])) === $para) $candidatos[] = $p;
        $trocaPersona = null;
        if (count($candidatos) > 1) {
            $nrm = fn($x) => trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9 @.]/', ' ', strtolower((string)@iconv('UTF-8', 'ASCII//TRANSLIT', (string)$x)))));
            // quem é? aceita: "sou o X", "aqui é o X", "X aqui", "é o X", só "X", o número da lista (1, 2…), o e-mail, ou "admin"/"equipe" quando só um é
            // já tem persona? então só troca se a mensagem PARECE identificação ("sou o X", "aqui é o X", só o número/nome/e-mail). Conversa normal passa reto.
            $prJa = sd_supabase('/rest/v1/pd_avisos_log?para=eq.' . $para . '&status=eq.persona&ref=like.persona:*&select=ref&order=criado_em.desc&limit=1')['body'][0] ?? null;
            $temPersona = (bool)$prJa;
            $dito0 = $nrm(preg_replace('/^\s*(?:oi|ola|opa|e ai|bom dia|boa tarde|boa noite)[,!. ]*/iu', '', $txt));
            $pareceIdent = (bool)preg_match('/^(sou |aqui e |e (o|a) |eu sou |falar como |fala como |agora sou |persona )/', $dito0) || ctype_digit($dito0) || str_word_count($dito0) <= 3;
            $dito = $dito0;
            if ($temPersona && !$pareceIdent) $dito = '';
            $dito = preg_replace('/^(?:sou (?:o|a) |sou |aqui e (?:o|a) |aqui e |e (?:o|a) |falar como |fala como |agora sou (?:o|a) |agora sou |persona |eu sou (?:o|a) |eu sou |aqui )/', '', $dito);
            $dito = preg_replace('/( aqui| mesmo| falando)$/', '', trim($dito));
            $achados = [];
            if ($dito !== '' && mb_strlen($dito) <= 60) {
                if (ctype_digit($dito)) { $k = (int)$dito - 1; if (isset($candidatos[$k])) $achados = [$candidatos[$k]]; }
                else {
                    foreach ($candidatos as $p) { $pn = $nrm($p['name']); $pe = $nrm($p['email'] ?? '');
                        if ($pn === $dito || ($pe !== '' && ($pe === $dito || explode('@', $pe)[0] === $dito))) { $achados = [$p]; break; } }
                    if (!$achados) foreach ($candidatos as $p) { $pn = $nrm($p['name']); if (strpos($pn, $dito) === 0 || strpos($dito, $pn) !== false) $achados[] = $p; }
                    if (!$achados) { $pal = explode(' ', $dito); foreach ($candidatos as $p) { $pn = $nrm($p['name']); $ok = true; foreach ($pal as $w) if ($w !== '' && strpos($pn, $w) === false && !in_array($w, ['adm', 'admin', 'equipe', 'vendas', 'comercial'], true)) { $ok = false; break; } if ($ok && array_filter($pal, fn($w) => strpos($pn, $w) !== false)) $achados[] = $p; } }
                    if (count($achados) > 1 && preg_match('/\b(adm|admin)\b/', $dito)) { $ad = array_values(array_filter($achados, fn($p) => ($p['role'] ?? '') === 'admin')); if (count($ad) === 1) $achados = $ad; }
                    if (count($achados) > 1 && preg_match('/\b(equipe|vendas|comercial)\b/', $dito)) { $eq2 = array_values(array_filter($achados, fn($p) => ($p['role'] ?? '') !== 'admin')); if (count($eq2) === 1) $achados = $eq2; }
                }
            }
            if (count($achados) === 1) $trocaPersona = $achados[0];
            // nomes distinguíveis na pergunta: "1) Sérgio Duh (admin) · 2) Sérgio Vendas (equipe)"
            $listaC = []; foreach ($candidatos as $ix => $p) $listaC[] = ($ix + 1) . ') ' . trim($p['name']) . ' (' . (($p['role'] ?? '') === 'admin' ? 'admin' : 'equipe') . ')';
            $perguntaQuem = "Esse número está em mais de um cadastro:\n" . implode("\n", $listaC) . "\n\nQuem é você? Responda com o número ou o nome (ex.: *2* ou *sou o " . trim($candidatos[1]['name']) . "*). Vale até você trocar de novo.";
            // resposta à pergunta da palavra mágica (pendência dos últimos 10 min)
            $pendente = null; $acertou = false; $errou = false;
            if (!$trocaPersona) {
                $pp = sd_supabase('/rest/v1/pd_avisos_log?para=eq.' . $para . '&status=eq.persona_pendente&criado_em=gte.' . rawurlencode(gmdate('c', time() - 600)) . '&select=ref&order=criado_em.desc&limit=1')['body'][0] ?? null;
                if ($pp) { $pid0 = substr((string)$pp['ref'], 8); foreach ($candidatos as $p) if ($p['id'] === $pid0) $pendente = $p;
                    if ($pendente) { $pal = pdPalavraMagica($pendente['id']); if ($pal !== '' && pdSegredoNorm($txt) === pdSegredoNorm($pal)) { $acertou = true; $trocaPersona = $pendente; } else $errou = true;
                        if ($acertou) sd_supabase('/rest/v1/pd_avisos_log?para=eq.' . $para . '&status=eq.persona_pendente', 'PATCH', ['status' => 'persona_ok']);
                        else { $tent = count((array)(sd_supabase('/rest/v1/pd_avisos_log?para=eq.' . $para . '&status=eq.persona_erro&criado_em=gte.' . rawurlencode(gmdate('c', time() - 600)) . '&select=id')['body'] ?? []));
                            sd_supabase('/rest/v1/pd_avisos_log', 'POST', ['canal' => 'whatsapp', 'para' => $para, 'quem' => $pendente['name'], 'corpo' => 'palavra errada', 'status' => 'persona_erro', 'origem' => 'sistema', 'direcao' => 'entrada', 'ref' => 'persona:' . $pendente['id']]);
                            if ($tent >= 2) { sd_supabase('/rest/v1/pd_avisos_log?para=eq.' . $para . '&status=eq.persona_pendente', 'PATCH', ['status' => 'persona_desistiu']); $errou = 'limite'; } } } }
            }
            $pedePalavra = false;
            if ($trocaPersona && !$acertou && pdPalavraMagica($trocaPersona['id']) !== '') {   // essa pessoa tem palavra mágica: pede antes de trocar
                sd_supabase('/rest/v1/pd_avisos_log', 'POST', ['canal' => 'whatsapp', 'para' => $para, 'quem' => $trocaPersona['name'], 'corpo' => 'aguardando palavra mágica', 'status' => 'persona_pendente', 'origem' => 'sistema', 'direcao' => 'entrada', 'ref' => 'persona:' . $trocaPersona['id']]);
                $pedePalavra = true; $trocaPersona = null;
            }
            if ($trocaPersona) { sd_supabase('/rest/v1/pd_avisos_log', 'POST', ['canal' => 'whatsapp', 'para' => $para, 'quem' => $trocaPersona['name'], 'corpo' => 'persona: ' . $trocaPersona['name'], 'status' => 'persona', 'origem' => 'sistema', 'direcao' => 'entrada', 'ref' => 'persona:' . $trocaPersona['id']]); $membro = $trocaPersona; }
            else {
                $pr = sd_supabase('/rest/v1/pd_avisos_log?para=eq.' . $para . '&status=eq.persona&ref=like.persona:*&select=ref&order=criado_em.desc&limit=1')['body'][0] ?? null;   // vale até trocar de novo
                $pid = $pr ? substr((string)$pr['ref'], 8) : null;
                foreach ($candidatos as $p) if ($pid && $p['id'] === $pid) $membro = $p;
            }
            if ($membro) $quem = $membro['name'];
            if ($acertou || $errou) $txt = '[palavra mágica]';   // o segredo não fica no histórico
        } elseif ($candidatos) { $membro = $candidatos[0]; $quem = $membro['name']; }
        { $cr = sd_supabase('/rest/v1/clients?zap=not.is.null&select=id,name,zap,ia_zap'); if (($cr['code'] ?? 500) >= 300) $cr = sd_supabase('/rest/v1/clients?zap=not.is.null&select=id,name,zap'); }
        if (!$cid) foreach ((array)($cr['body'] ?? []) as $cl) if (pdZapNormaliza(preg_replace('/\D/', '', (string)$cl['zap'])) === $para) { $quem = $cl['name']; $cid = $cl['id']; $clRow = $cl; break; }
        $linha = ['canal' => 'whatsapp', 'para' => $para, 'quem' => $quem, 'corpo' => mb_substr($txt, 0, 2000),
            'status' => 'recebida', 'origem' => 'entrada', 'direcao' => 'entrada', 'wamid' => $id, 'client_id' => $cid,
            'midia_path' => $mid['path'], 'midia_tipo' => $mid['tipo'], 'midia_nome' => $mid['nome']];
        $ins = sd_supabase('/rest/v1/pd_avisos_log', 'POST', $linha);
        if (($ins['code'] ?? 500) >= 300) { unset($linha['midia_path'], $linha['midia_tipo'], $linha['midia_nome']); sd_supabase('/rest/v1/pd_avisos_log', 'POST', $linha); }   // SQL v99 ainda não rodou: guarda o texto mesmo assim
        // alarme só quando NÃO é a equipe falando com a IA (a IA já responde) e só pros admins
        $primeiraVez = empty((array)(sd_supabase('/rest/v1/pd_avisos_log?direcao=eq.entrada&para=eq.' . $para . '&wamid=neq.' . rawurlencode($id) . '&select=id&limit=1')['body'] ?? []));
        if ($primeiraVez && !($membro && !$cid && ($membro['role'] ?? '') !== 'client')) { try {   // toca só quando uma pessoa ENTRA; as mensagens seguintes ficam na caixinha sem alarme
            foreach ((array)(sd_supabase('/rest/v1/profiles?role=eq.admin&active=eq.true&select=id')['body'] ?? []) as $ad)
                sd_supabase('/rest/v1/pd_alarmes', 'POST', ['tipo' => 'outro', 'user_id' => $ad['id'], 'titulo' => mb_substr('WhatsApp de ' . ($quem ?: '+' . $para), 0, 140),
                    'corpo' => mb_substr($txt, 0, 300), 'link' => 'avisos:' . preg_replace('/\D/', '', $para)]);
        } catch (Throwable $e) {} }
        if (count($candidatos) > 1 && ($pedePalavra || $errou) && !$trocaPersona) { try { pdZapDigitando($id); pdZapEnviar($para, $errou === 'limite' ? 'Três tentativas erradas. Se esqueceu a palavra, entra na plataforma, abre "o que eu sei" na sua IA e apaga ou ensina outra. Depois me diz de novo quem você é.' : ($errou ? 'Não é essa. Tenta de novo (ou me diz quem você é).' : 'E a palavra mágica?'), true); } catch (Throwable $e) {} continue; }
        if (count($candidatos) > 1 && !$membro) { try { pdZapDigitando($id); pdZapEnviar($para, (count($achados) > 1 ? "Achei mais de um com esse nome. " : '') . $perguntaQuem, true); } catch (Throwable $e) {} continue; }
        if ($trocaPersona) { try { pdZapDigitando($id); pdZapEnviar($para, 'Fechado: daqui em diante você é *' . trim($trocaPersona['name']) . '* nesse número, até você trocar de novo.', true); } catch (Throwable $e) {} continue; }
        // quem é da equipe (número cadastrado, não cliente) conversa com a IA da casa
        // cliente com "IA no WhatsApp" ligada: a Gigi (atendimento) responde, só com os dados dele
        if ($cid && !$membro && !empty($clRow['ia_zap'])) { @set_time_limit(120); try { pdZapDigitando($id); pdIaZapCliente($clRow, $para, $txt, $t === 'text' || $t === 'button' || $t === 'interactive' ? 'text' : $t); } catch (Throwable $e) { error_log('ia-zap-cliente: ' . $e->getMessage()); } }
        if ($membro && !$cid && ($membro['role'] ?? '') !== 'client') { @set_time_limit(120); try { pdZapDigitando($id); pdIaZapResponder($membro, $para, $txt, $t === 'text' || $t === 'button' || $t === 'interactive' ? 'text' : $t, $mid['path'] ? $mid : null); } catch (Throwable $e) { error_log('ia-zap: ' . $e->getMessage()); } }
    }
    foreach ((array)($c['value']['statuses'] ?? []) as $s) {
        $id = (string)($s['id'] ?? ''); $st = (string)($s['status'] ?? ''); if ($id === '' || $st === '') continue;
        $patch = ['entrega' => $st];
        if ($st === 'failed') {
            $er = $s['errors'][0] ?? [];
            $patch['status'] = 'erro';
            $patch['erro'] = mb_substr(trim(($er['code'] ?? '') . ' ' . ($er['title'] ?? '') . ' ' . ($er['message'] ?? ($er['error_data']['details'] ?? ''))), 0, 300);
        }
        sd_supabase('/rest/v1/pd_avisos_log?wamid=eq.' . rawurlencode($id), 'PATCH', $patch);
    }
}
