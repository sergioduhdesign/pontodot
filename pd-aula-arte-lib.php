<?php
// =====================================================================
// Pontodot — AULA DE ARTE EM SEGUNDO PLANO (a aula nasce de um job real)
//
//   O cliente aprovou a copy → o designer humano produz a arte como sempre
//   e, em paralelo, a PD (aluna) produz a MESMA peça. A arte da PD vai à
//   professora (GPT do Sérgio) à noite, pelo contrato pd-correcao/2; a
//   aluna refaz até esgotar as rodadas. De manhã (virada de dia) o resultado
//   vira um lote de aprovação com o "chapéu de aula" (approval_items.aula)
//   para o Sérgio comparar master × PD. O designer nunca é interrompido.
//
//   Só roda para o cliente com clients.aula_arte ligado E com a chave
//   geral pd_incubacao.aula_arte_ligada. Imagem: OpenAI Images (gpt-image-1)
//   com SD_OPENAI_KEY; sem a chave a aluna não entrega e a aula fica parada
//   (o relógio informa o motivo e nada quebra).
// =====================================================================
require_once __DIR__ . '/pd-aula-lib.php';
require_once __DIR__ . '/pd-aula-doc-lib.php';   // pdAulaDocPrazo / pdAulaDocInicio / pdAulaDocCfg (mesma noite, mesma virada de dia)
require_once __DIR__ . '/meta-ads-lib.php';      // pd_ctx_cliente (ficha do cliente)

const PD_ARTE_MAX_PECAS = 8;   // teto de peças por aula (custo: ~US$0,04–0,06 por imagem por rodada)

function pdAulaArteEh($a) { return (($a['tipo'] ?? 'arte') === 'arte' && ($a['modo'] ?? '') === 'job'); }
function pdArteEhStory($kind) { return in_array(strtolower((string)$kind), ['stories', 'story', 'reels'], true); }
function pdArteTamanho($kind) { return pdArteEhStory($kind) ? '1024x1536' : '1024x1024'; }

/** Gera UMA imagem na OpenAI (gpt-image-1). Devolve [png_bytes, erro]. Custo aproximado vai para o teto do dia. */
function pdArteGerarImagem($prompt, $tamanho = '1024x1024') {
    if (!defined('SD_OPENAI_KEY') || !SD_OPENAI_KEY) return [null, 'sem SD_OPENAI_KEY'];
    if (!in_array($tamanho, ['1024x1024', '1024x1536', '1536x1024'], true)) $tamanho = '1024x1024';
    $ch = curl_init('https://api.openai.com/v1/images/generations');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 180,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . SD_OPENAI_KEY, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['model' => 'gpt-image-1', 'prompt' => mb_substr((string)$prompt, 0, 4000), 'n' => 1, 'size' => $tamanho, 'quality' => 'medium'])]);
    $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    if ($code !== 200) { $j = json_decode((string)$raw, true); return [null, 'OpenAI Images HTTP ' . $code . ($err ? ' · ' . $err : '') . (!empty($j['error']['message']) ? ' · ' . mb_substr($j['error']['message'], 0, 200) : '')]; }
    $j = json_decode((string)$raw, true); $b64 = (string)($j['data'][0]['b64_json'] ?? '');
    $usd = $tamanho === '1024x1024' ? 0.04 : 0.06; pdIncubSoma($usd); if (function_exists('pdOpenaiLog')) { try { pdOpenaiLog('aula_arte', 'gpt-image-1', $usd, 'aula de arte'); } catch (Throwable $e) {} }
    if ($b64 === '') return [null, 'a OpenAI não devolveu imagem'];
    $bytes = base64_decode($b64, true); if (!$bytes) return [null, 'imagem inválida'];
    return [$bytes, null];
}

/** Sobe bytes para o bucket público "aprovacao" (o mesmo das artes humanas: arte.php e o lote renderizam a URL direto). Devolve a URL pública. */
function pdArteSobe($path, $bytes, $mime = 'image/png') {
    $p = implode('/', array_map('rawurlencode', explode('/', $path)));
    $ch = curl_init(SD_SUPABASE_URL . '/storage/v1/object/aprovacao/' . $p);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90, CURLOPT_POSTFIELDS => $bytes,
        CURLOPT_HTTPHEADER => ['apikey: ' . SD_SECRET_KEY, 'Authorization: Bearer ' . SD_SECRET_KEY, 'Content-Type: ' . $mime, 'x-upsert: true']]);
    curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code >= 300) throw new Exception('Storage recusou a arte (bucket "aprovacao"), HTTP ' . $code);
    return SD_SUPABASE_URL . '/storage/v1/object/public/aprovacao/' . $p;
}
/** Baixa a arte já guardada no storage (para levar à pasta da aula no Drive). */
function pdArteBaixa($url) {
    $ch = curl_init($url); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_FOLLOWLOCATION => true]);
    $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return ($code === 200 && $raw !== false && $raw !== '') ? $raw : null;
}

/** Normaliza os cards de copy aprovados: [{kind, titulo, texto, cta, hashtags, item_id}]. */
function pdArtePecasNormaliza($pecas) {
    $out = []; foreach ((array)$pecas as $p) { if (!is_array($p)) continue;
        $k = strtolower(trim((string)($p['kind'] ?? $p['tipo'] ?? 'feed'))); if (!in_array($k, ['feed', 'carrossel', 'stories', 'reels'], true)) continue;
        $tit = trim((string)($p['titulo'] ?? $p['title'] ?? $p['headline'] ?? '')); $txt = trim((string)($p['texto'] ?? $p['caption'] ?? ''));
        if ($tit === '' && $txt === '') continue;
        $out[] = ['kind' => $k, 'titulo' => mb_substr($tit, 0, 160), 'texto' => mb_substr($txt, 0, 1500), 'cta' => mb_substr(trim((string)($p['cta'] ?? '')), 0, 120), 'hashtags' => mb_substr(trim((string)($p['hashtags'] ?? '')), 0, 300), 'item_id' => $p['item_id'] ?? $p['id'] ?? null];
        if (count($out) >= PD_ARTE_MAX_PECAS) break; }
    return $out;
}

/** Cria a aula de arte (status em_exercicio: a aluna produz no próximo relógio). Só com clients.aula_arte E pd_incubacao.aula_arte_ligada. */
function pdAulaArteCriar($criadoPor, $clientId, $pecas, $taskId = null, $origemSetId = null) {
    $cfg = pdIncub() ?: []; if (empty($cfg['aula_arte_ligada'])) return ['ok' => false, 'msg' => 'desligada'];
    if (!preg_match('/^[0-9a-f-]{36}$/i', (string)$clientId)) return ['ok' => false, 'msg' => 'sem cliente'];
    $rc = pdAulaSup('/rest/v1/clients?id=eq.' . urlencode($clientId) . '&select=id,name,color,segment,aula_arte,aula_arte_refs,aula_arte_tipologia'); if (($rc['code'] ?? 500) >= 300) return ['ok' => false, 'msg' => 'rode o SQL v165'];
    $cl = $rc['body'][0] ?? null; if (!$cl) return ['ok' => false, 'msg' => 'cliente não encontrado'];
    if (!array_key_exists('aula_arte', $cl)) return ['ok' => false, 'msg' => 'rode o SQL v165'];
    if (empty($cl['aula_arte'])) return ['ok' => false, 'msg' => 'desligada'];
    $pecas = pdArtePecasNormaliza($pecas); if (!$pecas) return ['ok' => false, 'msg' => 'nenhuma peça visual (feed/carrossel/stories/reels)'];
    if ($origemSetId) {   // o cliente pode aprovar em mais de uma leva: não repete peça que já está numa aula deste lote
        $ja = pdAulaSup('/rest/v1/pd_aulas?tipo=eq.arte&modo=eq.job&contexto->>origem_set_id=eq.' . urlencode($origemSetId) . '&select=id,contexto')['body'] ?? []; $vistos = [];
        foreach ((array)$ja as $x) foreach ((array)($x['contexto']['pecas'] ?? []) as $p) if (!empty($p['item_id'])) $vistos[$p['item_id']] = 1;
        if ($vistos) $pecas = array_values(array_filter($pecas, fn($p) => empty($p['item_id']) || !isset($vistos[$p['item_id']])));
        if (!$pecas) return ['ok' => false, 'msg' => 'essas peças já estão em aula'];
    }
    $todasStory = true; foreach ($pecas as $p) if (!pdArteEhStory($p['kind'])) $todasStory = false;
    $skill = $todasStory ? 'ARTE_STORY' : 'ARTE_POST';
    $sk = pdAulaSup('/rest/v1/pd_habilidades?skill_id=eq.' . urlencode($skill) . '&ativa=eq.true&order=versao.desc&limit=1&select=*')['body'][0] ?? null;
    if (!$sk) return ['ok' => false, 'msg' => 'habilidade ' . $skill . ' não cadastrada (rode o SQL v111)'];
    if (!function_exists('pdQuemFazArea')) require_once __DIR__ . '/pd-lib.php';
    $quem = pdQuemFazArea('digital'); $iaId = $quem['id'] ?: $criadoPor; if (!$iaId) return ['ok' => false, 'msg' => 'sem dono para a aula'];
    $ctx = ['cliente' => $cl['name'], 'cor' => (string)($cl['color'] ?? ''), 'segmento' => (string)($cl['segment'] ?? ''), 'ficha' => '', 'produtos' => [], 'tipologia' => trim((string)($cl['aula_arte_tipologia'] ?? '')), 'refs' => trim((string)($cl['aula_arte_refs'] ?? '')), 'pecas' => $pecas, 'origem_set_id' => $origemSetId, 'tarefa' => null];
    try { $c = pd_ctx_cliente($clientId); if ($c) { $ctx['ficha'] = mb_substr((string)($c['ficha'] ?? ''), 0, 6000); $ctx['produtos'] = $c['produtos']; } } catch (Throwable $e) {}
    if ($taskId) { $t = pdAulaSup('/rest/v1/tasks?id=eq.' . urlencode($taskId) . '&select=id,action,obs,resp')['body'][0] ?? null; if ($t) $ctx['tarefa'] = ['id' => $t['id'], 'acao' => $t['action'], 'briefing' => (string)($t['obs'] ?? ''), 'resp' => $t['resp']]; }
    $inicio = pdAulaDocInicio(); $ctx['inicio'] = $inicio;
    $ex = ['autor' => 'job real', 'cliente_real' => $cl['name'], 'brief' => count($pecas) . ' peça(s) com copy aprovada pelo cliente: ' . implode(' · ', array_map(fn($p) => '[' . $p['kind'] . '] ' . $p['titulo'], $pecas)), 'tarefa' => $taskId, 'formato' => $todasStory ? '1080x1920' : '1080x1080', 'criterios' => $sk['criterios'] ?? [], 'skill_versao' => $sk['versao'] ?? 1];
    $r = pdAulaSup('/rest/v1/pd_aulas', 'POST', ['ia_user_id' => $iaId, 'personagem' => $quem['personagem'] ?: 'ia', 'skill_id' => $skill, 'modo' => 'job', 'tipo' => 'arte', 'status' => 'em_exercicio', 'rodada' => 1, 'max_rodadas' => pdAulaDocCfg()['max'],
        'exercicio' => $ex, 'entregas' => [], 'task_id' => $taskId ?: null, 'client_id' => $clientId, 'origem' => 'copy', 'contexto' => $ctx, 'prazo' => pdAulaDocPrazo(), 'criada_por' => preg_match('/^[0-9a-f-]{36}$/i', (string)$criadoPor) ? $criadoPor : null, 'dia' => date('Y-m-d', time() - 3 * 3600)], ['Prefer: return=representation']);
    $a = $r['body'][0] ?? null; if (!$a) return ['ok' => false, 'msg' => 'não consegui gravar a aula (' . ($r['code'] ?? '?') . '). Os SQLs v163/v165 já rodaram?'];
    if ($taskId) { if (!function_exists('pdEvento')) require_once __DIR__ . '/pd-lib.php'; try { pdEvento($taskId, 'aula', 'A PD produz esta arte em segundo plano (aula)', count($pecas) . ' peça(s). O designer segue normalmente; de manhã o Sérgio compara master × PD.', 'Escola', 'robo', ['aula_id' => $a['id']]); } catch (Throwable $e) {} }
    return ['ok' => true, 'aula_id' => $a['id'], 'n' => count($pecas), 'skill' => $skill, 'msg' => 'Aula de arte criada com ' . count($pecas) . ' peça(s).'];
}

/** O prompt de UMA peça para a aluna (imagem). */
function pdArtePrompt($a, $peca, $feedback = null) {
    $ctx = (array)($a['contexto'] ?? []); $L = [];
    $L[] = 'Peça de social media para o mercado imobiliário brasileiro, ' . (pdArteEhStory($peca['kind']) ? 'formato story vertical 9:16 (deixe livres o topo e a base, áreas da interface do Instagram)' : 'formato de feed quadrado 1:1') . ', estética premium de incorporadora/loteadora brasileira: fotografia realista de arquitetura ou paisagem, luz natural, composição limpa com respiro.';
    $L[] = 'MARCA: ' . $ctx['cliente'] . ($ctx['segmento'] ? ' (' . $ctx['segmento'] . ')' : '') . ($ctx['cor'] ? '. Cor principal da marca: ' . $ctx['cor'] . ' (use nos destaques e blocos de texto).' : '.') . ($ctx['tipologia'] ? ' Tipologia do produto: ' . $ctx['tipologia'] . ' (a cena precisa ser coerente com isso).' : '');
    if (!empty($ctx['produtos'])) $L[] = 'Produtos do cliente: ' . implode(', ', array_slice((array)$ctx['produtos'], 0, 6)) . '.';
    if (!empty($ctx['refs'])) $L[] = 'Há uma pasta de referências de arte do cliente (não disponível aqui): siga uma linguagem sóbria, elegante, tipografia moderna sem serifa, sem excesso de elementos.';
    $L[] = 'TEXTO OBRIGATÓRIO NA IMAGEM, exatamente assim, como título dominante e legível em português: "' . $peca['titulo'] . '"' . ($peca['cta'] ? '. Chamada menor, perto da base: "' . $peca['cta'] . '"' : '') . '. Nenhum outro texto. Ortografia perfeita; não altere, não traduza e não abrevie o texto.';
    if ($peca['texto']) $L[] = 'Contexto da legenda (NÃO escrever na imagem, serve só para a cena): ' . mb_substr($peca['texto'], 0, 400);
    $L[] = 'NÃO invente logotipo, marca d\'água, preço, metragem, endereço, telefone ou site. Sem pessoas com rosto reconhecível. Sem bordas nem molduras.';
    $lic = pdLicoesTexto($a['ia_user_id'], $a['skill_id']); if ($lic) $L[] = mb_substr($lic, 0, 1200);
    if ($feedback) $L[] = 'CORREÇÃO DA PROFESSORA sobre a versão anterior (aplique tudo): ' . mb_substr((string)$feedback, 0, 1200);
    return implode("\n", $L);
}

/** Passo da aluna: produz uma imagem por peça, guarda no storage (bucket aprovacao) e deixa a rodada pronta para a professora. */
function pdAulaArteProduzir($a) {
    $id = $a['id']; $patch = fn($d) => pdAulaSup('/rest/v1/pd_aulas?id=eq.' . urlencode($id), 'PATCH', $d);
    $ent = (array)$a['entregas']; if (count($ent) >= (int)$a['rodada']) { $patch(['status' => 'em_correcao']); return ['ok' => true, 'msg' => 'rodada ' . $a['rodada'] . ' já tinha entrega']; }
    if (!defined('SD_OPENAI_KEY') || !SD_OPENAI_KEY) return ['ok' => false, 'msg' => 'sem SD_OPENAI_KEY: a aluna não gera imagem'];
    if (!pdIncubTemTeto()) return ['ok' => false, 'msg' => 'teto de gasto do dia'];
    $c0 = pdIncub() ?: []; if ((float)($c0['gasto_hoje'] ?? 0) >= pdAulaDocCfg()['teto_noite']) return ['ok' => false, 'msg' => 'teto da noite atingido'];
    $cor = (array)$a['correcoes']; $uc = $cor ? end($cor) : null; $fb = $uc ? (($uc['texto_do_professor'] ?: $uc['feedback']) . (!empty($uc['humano']) ? "\nCOMENTÁRIO DO SÉRGIO (prioridade máxima): " . $uc['humano'] : '')) : null;
    $pecas = (array)($a['contexto']['pecas'] ?? []); $saida = []; $erros = [];
    foreach ($pecas as $i => $p) {
        [$png, $err] = pdArteGerarImagem(pdArtePrompt($a, $p, $fb), pdArteTamanho($p['kind']));
        if (!$png) { $erros[] = ($i + 1) . ': ' . $err; continue; }
        try { $url = pdArteSobe(($a['client_id'] ?: 'sem-cliente') . '/aula/' . $id . '/R' . (int)$a['rodada'] . '-' . ($i + 1) . '.png', $png); }
        catch (Throwable $e) { $erros[] = ($i + 1) . ': ' . $e->getMessage(); continue; }
        $saida[] = ['i' => $i + 1, 'kind' => $p['kind'], 'titulo' => $p['titulo'], 'url' => $url, 'name' => 'entrega-R' . (int)$a['rodada'] . '-' . ($i + 1) . '.png'];
    }
    if (!$saida) return ['ok' => false, 'msg' => 'a aluna não entregou nenhuma peça: ' . implode(' | ', $erros)];
    $ent[] = ['rodada' => (int)$a['rodada'], 'pecas' => $saida, 'em' => gmdate('c'), 'por' => $a['personagem'], 'erros' => $erros ?: null];
    $patch(['entregas' => $ent, 'status' => 'em_correcao']);
    return ['ok' => true, 'msg' => 'rodada ' . $a['rodada'] . ': a aluna entregou ' . count($saida) . ' peça(s)' . ($erros ? ' (falhou: ' . implode('; ', $erros) . ')' : '')];
}

/** Um passo da aula de arte (relógio). */
function pdAulaArtePasso($a) {
    $cfg = pdIncub() ?: ['nota_minima' => 85, 'professor_modo' => 'projeto_gpt'];
    $sk = pdAulaSup('/rest/v1/pd_habilidades?skill_id=eq.' . urlencode($a['skill_id']) . '&ativa=eq.true&order=versao.desc&limit=1&select=*')['body'][0] ?? null;
    if (!$sk) return ['ok' => false, 'msg' => 'habilidade não cadastrada (SQL v111)'];
    if ($a['status'] === 'em_exercicio') return pdAulaArteProduzir($a);   // a PD produz de dia, como o designer
    if ($a['status'] === 'em_correcao') {
        $ch = (array)($a['chamado'] ?? []); $faltaEnvio = empty($ch['folderId']) || (int)($ch['rodada'] ?? 0) !== (int)$a['rodada'];
        if ($faltaEnvio && !empty($a['contexto']['inicio']) && $a['contexto']['inicio'] > gmdate('c')) return ['ok' => false, 'msg' => 'arte pronta; a professora recebe às ' . pdAulaDocCfg()['hora_professora']];
        if ($faltaEnvio) return pdProfessorEnviar($a, $sk, $cfg, true);   // manual=true: a aula de job não conta no limite diário de chamados (como a de documento)
        return pdProfessorPasso($a, $sk, $cfg);   // lê o CORRECAO.json (ou o e-mail) da professora
    }
    return ['ok' => false, 'msg' => 'nada a fazer em "' . $a['status'] . '"'];
}

/** Virada de dia (ou aprovação, ou rodadas esgotadas): a última versão vira um lote de aprovação com o chapéu de aula. */
function pdAulaArteEntregar($a, $motivo) {
    if (!function_exists('pdNotificarResp')) require_once __DIR__ . '/pd-lib.php';
    $ent = (array)$a['entregas']; $ult = $ent ? end($ent) : null; $cor = (array)$a['correcoes']; $uc = $cor ? end($cor) : null; $ctx = (array)($a['contexto'] ?? []);
    $pend = []; if ($uc && empty($uc['aprovado']) && (float)($uc['nota'] ?? 0) < 85) $pend[] = 'Professora ainda não aprovou (nota ' . round((float)($uc['nota'] ?? 0)) . '): ' . mb_substr((string)($uc['feedback'] ?? ''), 0, 600);
    if (!$cor) $pend[] = 'A professora não respondeu esta noite.';
    if (!$ult) $pend[] = 'A aluna não chegou a entregar (sem imagem).';
    $pendTxt = $pend ? implode("\n", $pend) : ''; $setId = null; $n = 0;
    if ($ult && !empty($ult['pecas'])) {
        $pecas = (array)($ctx['pecas'] ?? []); $cn = (string)($ctx['cliente'] ?? 'Cliente');
        $base = ['client_id' => $a['client_id'], 'task_id' => $a['task_id'] ?: null, 'title' => 'AULA · ' . $cn . ' · ' . date('d/m/Y', time() - 3 * 3600), 'subtitle' => 'Arte da PD (aula) · ' . count($ult['pecas']) . ' peça(s) · nota ' . ($uc ? round((float)$uc['nota']) : '—'), 'layout' => 'galeria', 'status' => 'rascunho', 'created_by' => $a['criada_por'] ?: $a['ia_user_id'], 'interno' => true];
        $set = null;   // mesma dança do pd-copy: o banco tem CHECK em modo/source_type
        foreach ([['copy', 'studio'], ['lote_variado', 'upload'], ['lote_variado', 'studio'], ['copy', 'upload']] as [$mo, $so]) {
            $set = pdAulaSup('/rest/v1/approval_sets', 'POST', $base + ['modo' => $mo, 'source_type' => $so], ['Prefer: return=representation']);
            if (($set['code'] ?? 500) < 300 && !empty($set['body'][0]['id'])) break; if (strpos((string)($set['raw'] ?? ''), '23514') === false) break; }
        $setId = $set['body'][0]['id'] ?? null;
        if ($setId) {
            $linhas = []; foreach ((array)$ult['pecas'] as $k => $pc) { $p = $pecas[(int)($pc['i'] ?? ($k + 1)) - 1] ?? [];
                $linhas[] = ['set_id' => $setId, 'position' => $k, 'kind' => $pc['kind'] ?? 'feed', 'title' => mb_substr((string)($pc['titulo'] ?? $p['titulo'] ?? ('Peça ' . ($k + 1))), 0, 160), 'caption' => mb_substr(trim((string)($p['texto'] ?? '') . (!empty($p['cta']) ? "\n\nCTA: " . $p['cta'] : '')), 0, 4000), 'hashtags' => ($p['hashtags'] ?? '') ?: null,
                    'media' => [['name' => $pc['name'] ?? ('arte-' . ($k + 1) . '.png'), 'url' => $pc['url'], 'thumb' => $pc['url'], 'mime' => 'image/png', 'source' => 'storage', 'aula_id' => $a['id'], 'rodada' => (int)($ult['rodada'] ?? 1)]], 'aula' => true, 'aula_id' => $a['id']]; }
            $r = pdAulaSup('/rest/v1/approval_items', 'POST', $linhas);
            if (($r['code'] ?? 500) >= 300 && strpos((string)($r['raw'] ?? ''), '23514') !== false) { foreach ($linhas as $k => $l) $linhas[$k]['kind'] = 'material'; $r = pdAulaSup('/rest/v1/approval_items', 'POST', $linhas); }
            if (($r['code'] ?? 500) >= 300) { $pendTxt .= ($pendTxt ? "\n" : '') . 'Não consegui criar as peças do lote (' . ($r['code'] ?? '?') . '). O SQL v165 já rodou?'; } else $n = count($linhas);
        } else $pendTxt .= ($pendTxt ? "\n" : '') . 'Não consegui criar o lote de aprovação da aula.';
    }
    $resumo = 'Aula de arte (' . ucfirst((string)$a['personagem']) . '): ' . count($cor) . ' parecer' . (count($cor) === 1 ? '' : 'es') . ', ' . count($ent) . ' rodada' . (count($ent) === 1 ? '' : 's') . '. ' . $motivo . ($pendTxt ? "\nPENDÊNCIAS:\n" . $pendTxt : '');
    $base0 = rtrim(function_exists('sd_base') ? sd_base() : 'https://pontodot.ai', '/'); $link = $setId ? $base0 . '/aprovacao.html?set=' . $setId : null;
    if (!empty($a['task_id'])) {
        $corpo = "🎓 Arte da PD (aula) pronta para comparar com a master" . ($link ? ":\n" . $link : '') . "\n\n" . $resumo;
        $linha = ['task_id' => $a['task_id'], 'user_id' => $a['criada_por'] ?: $a['ia_user_id'], 'body' => $corpo, 'meta' => ['aula_id' => $a['id'], 'tipo' => 'aula_arte', 'set_id' => $setId]];
        $r = sd_supabase('/rest/v1/comments', 'POST', $linha); if (($r['code'] ?? 500) >= 300) { unset($linha['meta']); sd_supabase('/rest/v1/comments', 'POST', $linha); }
        try { pdEvento($a['task_id'], 'aula', 'Aula de arte pronta: master × PD', $resumo, 'Escola', 'robo', ['aula_id' => $a['id'], 'set_id' => $setId]); } catch (Throwable $e) {}
    }
    if ($n || $cor) try {
        $tit = '🎓 Aula de arte pronta: master × PD'; $txt = ($ctx['cliente'] ?? 'Cliente') . ' · ' . $n . ' peça(s) da PD' . ($uc ? ' · nota ' . round((float)$uc['nota']) : '') . ($pendTxt ? "\n" . mb_substr($pendTxt, 0, 200) : '');
        $lk = !empty($a['task_id']) ? 'task:' . $a['task_id'] : null; if ($link) $txt .= "\n" . $link;   // o sino abre a tarefa (o comentário dela traz o lote); o zap leva o link do lote
        pdNotificarResp('SERGIO', 'aula', $tit, $txt, $lk, null, $a['task_id'] ?: null);
        if (!empty($a['criada_por'])) { $ehSergio = pdAulaSup('/rest/v1/profiles?id=eq.' . urlencode($a['criada_por']) . '&team_key=eq.SERGIO&select=id')['body'][0] ?? null; if (!$ehSergio) pdNotificarUsuario($a['criada_por'], 'aula', $tit, $txt, $lk, $a['task_id'] ?: null); }
    } catch (Throwable $e) { error_log('aula arte aviso: ' . $e->getMessage()); }
    pdAulaSup('/rest/v1/pd_aulas?id=eq.' . urlencode($a['id']), 'PATCH', ['entregue_em' => gmdate('c'), 'pendencias' => $pendTxt ?: null, 'status' => $cor ? 'validacao_humana' : 'reprovada', 'nota_final' => $uc['nota'] ?? null, 'contexto' => $ctx + ['set_aula_id' => $setId]]);
    return 'entregue: ' . substr($a['id'], 0, 8) . ($setId ? ' (lote ' . substr($setId, 0, 8) . ')' : ' (sem lote)');
}

/** Relógio das aulas de arte: roda no pd-worker (a cada 5 min). */
function pdAulaArteRelogio() {
    $c = pdIncub() ?: []; if (empty($c['aula_arte_ligada'])) return 'desligada';
    $as = pdAulaSup('/rest/v1/pd_aulas?tipo=eq.arte&modo=eq.job&entregue_em=is.null&status=in.(em_exercicio,em_correcao,validacao_humana)&select=*&order=criada_em&limit=12')['body'] ?? [];
    $msg = []; $agora = gmdate('c'); $ponte = pdPonteOk();
    foreach ((array)$as as $a) {
        try {
            $vencida = !empty($a['prazo']) && $a['prazo'] <= $agora;
            if ($vencida || $a['status'] === 'validacao_humana') { $msg[] = pdAulaArteEntregar($a, $vencida ? 'Virada de dia.' : 'A professora aprovou.'); continue; }
            if ($a['status'] === 'em_correcao' && !$ponte) { $msg[] = substr($a['id'], 0, 8) . ': ponte do Drive não configurada'; continue; }
            for ($i = 0; $i < 2; $i++) { $r = pdAulaArtePasso($a); if (!empty($r['msg'])) $msg[] = substr($a['id'], 0, 8) . ': ' . $r['msg']; if (empty($r['ok'])) break;
                $a = pdAulaSup('/rest/v1/pd_aulas?id=eq.' . urlencode($a['id']) . '&select=*')['body'][0] ?? $a; if (!empty($a['entregue_em']) || $a['status'] === 'validacao_humana') break; }
        } catch (Throwable $e) { $msg[] = substr($a['id'], 0, 8) . ': ' . $e->getMessage(); }
    }
    return $msg ? implode(' | ', $msg) : 'nada novo';
}
