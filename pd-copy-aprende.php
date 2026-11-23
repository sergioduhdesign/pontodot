<?php
// =====================================================================
// Pontodot — APRENDIZADO DA COPY
//  1) cpLicoesBloco: as lições APROVADAS (comuns + do cliente) entram no prompt da Copy.
//  2) cpAprende: quando a equipe corrige à mão um texto escrito pela IA e salva, vira uma lição PROPOSTA
//     (nada vale até o gestor aprovar na Escola). Mesmo desenho do SEO (pdSeoLicaoDaEdicao).
// Tudo aqui é bônus: qualquer falha é engolida e NUNCA trava o salvar nem o gerar.
// =====================================================================
if (!defined('CP_SKILL')) define('CP_SKILL', 'COPY_TEXTOS');

/** Texto pronto para o prompt: lições aprovadas pela equipe (comuns da casa + as deste cliente). */
function cpLicoesBloco($cid, $iaId = null) {
    try {
        $cid = preg_replace('/[^0-9a-fA-F-]/', '', (string)$cid);
        $r = sd_supabase('/rest/v1/pd_licoes?status=eq.aprovada&skill_id=eq.' . CP_SKILL . '&or=(comum.eq.true' . ($cid ? ',cliente_ref.eq.' . urlencode($cid) : '') . ')&select=regra,exemplo_bom,exemplo_ruim&order=criada_em.desc&limit=15');
        $rows = (($r['code'] ?? 500) < 300) ? (array)($r['body'] ?? []) : [];
        $o = '';
        foreach ($rows as $l) { if (empty($l['regra'])) continue;
            $o .= '- ' . $l['regra'] . (!empty($l['exemplo_bom']) ? ' (jeito aprovado: ' . mb_substr((string)$l['exemplo_bom'], 0, 160) . ')' : '') . (!empty($l['exemplo_ruim']) ? ' (evitar: ' . mb_substr((string)$l['exemplo_ruim'], 0, 160) . ')' : '') . "\n"; }
        $g = '';
        if ($iaId && function_exists('pdLicoesTexto')) $g = (string)pdLicoesTexto($iaId, CP_SKILL);   // lições gerais desta IA (habilidade/universal)
        if ($g !== '') $g = trim(preg_replace('/^LIÇÕES JÁ APROVADAS[^\n]*\n/u', '', $g)) . "\n";
        $tudo = $o . $g;
        return trim($tudo) === '' ? '' : "\n\nLIÇÕES APROVADAS PELA EQUIPE (obrigatório aplicar; vêm de correções reais de textos anteriores):\n" . $tudo;
    } catch (Throwable $e) { return ''; }
}

/**
 * Compara o que a equipe está salvando com o que a IA escreveu. Guarda o texto original da IA em `_orig`
 * e, se a pessoa mudou o texto de forma relevante, cria UMA lição proposta por peça (marca `_licao` para não repetir).
 * Devolve as variantes (com as marcas) para o salvar gravar.
 */
function cpAprende($id, array $novo, $nomeQuem = '') {
    try {
        $old = sd_supabase('/rest/v1/pd_copys?id=eq.' . urlencode((string)$id) . '&select=client_id,variantes')['body'][0] ?? null; if (!$old) return $novo;
        $chave = isset($novo['itens']) ? 'itens' : (isset($novo['variantes']) ? 'variantes' : null); if (!$chave || !is_array($novo[$chave])) return $novo;
        $ov = (array)($old['variantes'] ?? []); $oi = (array)($ov[$chave] ?? ($ov['itens'] ?? ($ov['variantes'] ?? [])));
        $ni = $novo[$chave]; $quem = null; $criadas = 0;
        foreach ($ni as $i => $it) {
            if (!is_array($it)) continue;
            $o = (isset($oi[$i]) && is_array($oi[$i])) ? $oi[$i] : [];
            $atual = trim((string)($it['texto'] ?? ''));
            $orig = (string)($it['_orig'] ?? ($o['_orig'] ?? ($o['texto'] ?? '')));
            if ($orig === '') { if ($atual !== '') $ni[$i]['_orig'] = $atual; continue; }   // primeira vez que vemos a peça: o que está aí é o original
            $ni[$i]['_orig'] = $orig;
            if ($atual === '' || (!empty($it['_licao']) && $it['_licao'] === md5($atual))) continue;
            if (mb_strlen($atual) < 20 || mb_strlen($orig) < 20) continue;
            similar_text(mb_substr($orig, 0, 1500), mb_substr($atual, 0, 1500), $pct);
            if ($pct >= 92 || $pct < 25) continue;   // quase igual (vírgula) ou texto novo (não é correção)
            if ($criadas >= 3) break;                // teto por salvamento
            $quem = $quem ?: pdQuemFazArea('copys');
            $r = sd_supabase('/rest/v1/pd_licoes', 'POST', [
                'ia_user_id' => $quem['id'] ?? null, 'personagem' => $quem['personagem'] ?? null, 'skill_id' => CP_SKILL, 'escopo' => 'cliente', 'cliente_ref' => $old['client_id'],
                'regra' => 'A equipe corrigiu um texto escrito pela IA (Copy' . ($nomeQuem ? ', por ' . $nomeQuem : '') . '): prefira o jeito da versão corrigida (abertura, tom, tamanho e escolha de palavras).',
                'exemplo_ruim' => mb_substr($orig, 0, 400), 'exemplo_bom' => mb_substr($atual, 0, 400), 'status' => 'proposta', 'comum' => false]);
            if (($r['code'] ?? 500) < 300) { $ni[$i]['_licao'] = md5($atual); $criadas++; }
        }
        $novo[$chave] = $ni; return $novo;
    } catch (Throwable $e) { return $novo; }
}
