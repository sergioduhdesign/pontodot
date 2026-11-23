<?php
// =====================================================================
// Pontodot — APRENDIZADO CENTRAL (vale para TODA função com IA)
//  1) pdAprendeBloco(): as lições APROVADAS da área entram no prompt. Ligado dentro do pdBlindagem(),
//     que já fecha o prompt de toda chamada de IA da casa; por isso nenhuma função fica de fora
//     (inclusive as ainda não liberadas para todos).
//  2) pdAprendeCorrecao(): quando a equipe corrige à mão o que a IA fez, vira lição PROPOSTA
//     (só vale depois que o gestor aprova na Escola e a nota de corte é atingida).
//  3) pdPratica(): registra o dia a dia (peça corrigida, pedido atendido) em pd_observacoes,
//     como ponto de PRÁTICA — separado dos pontos de PROVA (pd_notas).
// Tudo é bônus: qualquer falha é engolida e NUNCA trava a função que chamou.
// Para uma função usar uma habilidade específica: $GLOBALS['PD_APRENDE_SKILL'] = 'X'; (e opcional PD_APRENDE_CLIENTE).
// =====================================================================

/** script → [habilidade, área do PD]. O que não está aqui não recebe bloco (aula, SEO e Copy já têm o próprio). */
function pdAprendeMapa() {
    return [
        'pd-estudio-ia' => ['ESTRATEGIA_ESTUDIO', 'digital'], 'pd-cortes' => ['VIDEO_CORTES', 'video'], 'pd-voz' => ['AUDIO_ROTEIRO', 'audio'],
        'pd-arte' => ['DESIGN_ARTE', 'grafica'], 'pd-narrativas' => ['NARRATIVAS', 'copys'], 'pd-vendas' => ['VENDAS_TEXTOS', 'copys'],
        'social-desempenho' => ['SOCIAL_ANALISE', 'digital'], 'social' => ['SOCIAL_TEXTOS', 'digital'], 'meta-ads' => ['CAMPANHAS_ANALISE', 'digital'],
        'retro' => ['RETRO_ANALISE', 'digital'], 'pd-rascunho' => ['RASCUNHO_RESPOSTA', 'copys'], 'pd-pessoas' => ['PESSOAS_PESQUISA', 'digital'],
        'pd-ia' => ['CONVERSA', 'copys'],
    ];
}
/** [habilidade, área] da chamada em curso (override explícito ganha do script). */
function pdAprendeContexto() {
    if (!empty($GLOBALS['PD_APRENDE_SKILL'])) return [(string)$GLOBALS['PD_APRENDE_SKILL'], (string)($GLOBALS['PD_APRENDE_AREA'] ?? 'copys')];
    $s = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''), '.php'); if ($s === '') return null;
    $m = pdAprendeMapa(); if (isset($m[$s])) return $m[$s];
    foreach ($m as $k => $v) if (strpos($s, $k) === 0) return $v;      // social-*.php, meta-ads-*.php, pd-vendas-*.php…
    return null;
}
/** Texto das lições aprovadas desta habilidade (comuns da casa, gerais da IA e as do cliente). Cache por requisição. */
function pdAprendeBloco($skill = null, $cid = null) {
    static $cache = [];
    try {
        if (!$skill) { $c = pdAprendeContexto(); if (!$c) return ''; $skill = $c[0]; }
        if (!function_exists('sd_supabase')) return '';
        $cid = preg_replace('/[^0-9a-fA-F-]/', '', (string)($cid ?? ($GLOBALS['PD_APRENDE_CLIENTE'] ?? '')));
        $k = $skill . '|' . $cid; if (isset($cache[$k])) return $cache[$k];
        $base = '/rest/v1/pd_licoes?status=eq.aprovada&skill_id=eq.' . urlencode($skill) . '&select=regra,exemplo_bom,exemplo_ruim&order=criada_em.desc&limit=15&or=';
        $r = sd_supabase($base . '(comum.eq.true,escopo.in.(habilidade,universal)' . ($cid ? ',cliente_ref.eq.' . urlencode($cid) : '') . ')');
        if (($r['code'] ?? 500) >= 300) $r = sd_supabase($base . '(comum.eq.true)');
        $rows = (($r['code'] ?? 500) < 300) ? (array)($r['body'] ?? []) : [];
        $o = '';
        foreach ($rows as $l) { if (empty($l['regra'])) continue;
            $o .= '- ' . $l['regra'] . (!empty($l['exemplo_bom']) ? ' (jeito aprovado: ' . mb_substr((string)$l['exemplo_bom'], 0, 160) . ')' : '') . (!empty($l['exemplo_ruim']) ? ' (evitar: ' . mb_substr((string)$l['exemplo_ruim'], 0, 160) . ')' : '') . "\n"; }
        return $cache[$k] = ($o === '' ? '' : "\n\n[LIÇÕES APROVADAS PELA EQUIPE — obrigatório aplicar; vêm de correções reais anteriores]\n" . $o);
    } catch (Throwable $e) { return ''; }
}

/** Compara o que a IA fez com o que a equipe deixou. Cria UMA lição proposta (se a mudança for relevante). Devolve true se criou. */
function pdAprendeCorrecao($skill, $area, $cid, $orig, $atual, $rotulo = '', $nomeQuem = '') {
    try {
        $orig = trim((string)$orig); $atual = trim((string)$atual);
        if (mb_strlen($orig) < 20 || mb_strlen($atual) < 20 || !function_exists('sd_supabase')) return false;
        similar_text(mb_substr($orig, 0, 1500), mb_substr($atual, 0, 1500), $pct);
        if ($pct >= 92 || $pct < 25) return false;      // quase igual (vírgula) ou texto novo (não é correção)
        $dup = sd_supabase('/rest/v1/pd_licoes?skill_id=eq.' . urlencode($skill) . '&exemplo_bom=eq.' . urlencode(mb_substr($atual, 0, 400)) . '&select=id&limit=1');   // não repete a mesma correção a cada salvamento
        if (!empty($dup['body'][0])) return false;
        $quem = function_exists('pdQuemFazArea') ? pdQuemFazArea($area) : [];
        $r = sd_supabase('/rest/v1/pd_licoes', 'POST', [
            'ia_user_id' => $quem['id'] ?? null, 'personagem' => $quem['personagem'] ?? null, 'skill_id' => $skill, 'escopo' => $cid ? 'cliente' : 'habilidade', 'cliente_ref' => $cid ?: null,
            'regra' => 'A equipe corrigiu o que a IA fez' . ($rotulo ? ' (' . $rotulo . ($nomeQuem ? ', por ' . $nomeQuem : '') . ')' : '') . ': prefira o jeito da versão corrigida (abertura, tom, tamanho e escolha de palavras).',
            'exemplo_ruim' => mb_substr($orig, 0, 400), 'exemplo_bom' => mb_substr($atual, 0, 400), 'status' => 'proposta', 'comum' => false]);
        if (($r['code'] ?? 500) >= 300) {      // coluna escopo/cliente_ref ainda não existe: grava no formato antigo
            $r = sd_supabase('/rest/v1/pd_licoes', 'POST', ['ia_user_id' => $quem['id'] ?? null, 'personagem' => $quem['personagem'] ?? null, 'skill_id' => $skill,
                'regra' => 'A equipe corrigiu o que a IA fez' . ($rotulo ? ' (' . $rotulo . ')' : '') . ': prefira o jeito da versão corrigida.',
                'exemplo_ruim' => mb_substr($orig, 0, 400), 'exemplo_bom' => mb_substr($atual, 0, 400), 'status' => 'proposta', 'comum' => false]);
        }
        if (($r['code'] ?? 500) < 300) { pdPratica($area, 'corrigida', ['skill' => $skill, 'similaridade' => (int)$pct], $cid, $quem['id'] ?? null, $quem['personagem'] ?? null); return true; }
    } catch (Throwable $e) {}
    return false;
}

/** Ponto de PRÁTICA (dia a dia): peça corrigida, pedido atendido, correção entendida. Fica em pd_observacoes (evento prefixado "pratica_"). */
function pdPratica($area, $evento, array $dados = [], $cid = null, $iaUserId = null, $personagem = null) {
    try {
        if (!function_exists('sd_supabase')) return false;
        $r = sd_supabase('/rest/v1/pd_observacoes', 'POST', ['area' => $area, 'evento' => 'pratica_' . $evento, 'dados' => $dados, 'ia_user_id' => $iaUserId, 'personagem' => (string)$personagem, 'client_id' => $cid ?: null]);
        return ($r['code'] ?? 500) < 300;
    } catch (Throwable $e) { return false; }
}

/** Textos de uma peça do Estúdio (qualquer chave de texto, em qualquer profundidade), para comparar antes/depois da edição da equipe. */
function pdAprendeTextosDoc($doc) {
    $o = []; $walk = function ($v, $k = '') use (&$walk, &$o) {
        if (is_array($v)) { foreach ($v as $kk => $vv) $walk($vv, (string)$kk); return; }
        if (is_string($v) && in_array($k, ['text', 'texto', 'txt', 'content', 'titulo', 'headline', 'legenda', 'subtitulo', 'cta'], true) && mb_strlen(trim($v)) >= 3) $o[] = trim(strip_tags($v)); };
    $walk($doc); return implode("\n", $o);
}
