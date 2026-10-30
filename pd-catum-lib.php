<?php
// Hora de Catum — leitura do termômetro (copy primeiro). Cálculo puro: recebe dados, devolve leituras.
// Regra de "primeira": peça na versão 1 com aprovação interna ("tá bom pra mostrar"). O cliente não entra na conta.
// Correção marcada como mudança de direção ou briefing ambíguo NÃO conta como erro da IA. Correção sem motivo fica "a classificar".
const PD_CATUM_MIN_AMOSTRA = 20;   // abaixo disso: "em formação" (sem porcentagem)

function pdCatumCalcular(array $copys, array $itens, array $motivos, float $refMin, string $mestre, int $minimo = PD_CATUM_MIN_AMOSTRA): array {
    $porSet = []; foreach ($itens as $it) $porSet[$it['set_id']][] = $it;
    $grupos = []; $aClass = [];
    usort($copys, fn($a, $b) => strcmp((string)($a['em'] ?? ''), (string)($b['em'] ?? '')));
    foreach ($copys as $cp) {
        $v = (array)($cp['variantes'] ?? []);
        if (!empty($v['calendario']) || empty($cp['set_id'])) continue;   // calendário não é TX01: fica fora até separar a parcela de texto
        $ia = (array)($v['ia'] ?? []); $pers = strtolower(trim((string)($ia['personagem'] ?? ''))); $atrib = 'registrada';
        if ($pers === '') { $pers = $mestre; $atrib = 'mestre_da_area'; }
        $its = $porSet[$cp['set_id']] ?? []; if (!$its) continue;
        usort($its, fn($a, $b) => (int)($a['position'] ?? 0) <=> (int)($b['position'] ?? 0));
        $g =& $grupos[$pers];
        if (!isset($g)) $g = ['personagem' => $pers, 'tarefa' => 'TX01', 'primeira' => 0, 'erro_ia' => 0, 'fora' => 0, 'a_classificar' => 0, 'pendentes' => 0, 'seq' => [], 'ia_seg' => 0.0, 'pecas' => 0, 'humano_min' => 0.0, 'humano_medido' => 0, 'atribuicao' => $atrib];
        $g['ia_seg'] += (float)($ia['seg'] ?? 0); $g['pecas'] += count($its);
        foreach ($its as $it) {
            $ver = (int)($it['version'] ?? 1); $mt = $motivos[$it['id']] ?? null;
            if ($ver > 1) {
                if (!$mt) { $g['a_classificar']++; $aClass[] = ['item_id' => $it['id'], 'copy' => $cp['nome'] ?? '', 'titulo' => $it['title'] ?? '', 'versao' => $ver, 'personagem' => $pers]; }
                elseif ($mt['motivo'] === 'erro_ia') { $g['erro_ia']++; $g['seq'][] = 0; if ($mt['humano_min'] !== null) { $g['humano_min'] += (float)$mt['humano_min']; $g['humano_medido']++; } }
                else $g['fora']++;
            } elseif (!empty($it['interno_ok'])) { $g['primeira']++; $g['seq'][] = 1; }
            else $g['pendentes']++;
        }
        unset($g);
    }
    $out = [];
    foreach ($grupos as $g) {
        $n = $g['primeira'] + $g['erro_ia'];   // unidades avaliadas
        $estado = $n === 0 ? 'sem_leitura' : ($n < $minimo ? 'em_formacao' : 'cotada');
        $taxa = $n > 0 ? $g['primeira'] / $n : null; $tend = null;
        if ($estado === 'cotada' && count($g['seq']) >= 20) {
            $ult = array_slice($g['seq'], -10); $ant = array_slice($g['seq'], -20, 10);
            $d = array_sum($ult) / 10 - array_sum($ant) / 10; $tend = $d > 0.1 ? 'sobe' : ($d < -0.1 ? 'cai' : 'estavel');
        }
        $equiv = $g['primeira'] * $refMin;
        $out[] = ['personagem' => $g['personagem'], 'tarefa' => $g['tarefa'], 'estado' => $estado, 'avaliadas' => $n, 'primeira' => $g['primeira'], 'erro_ia' => $g['erro_ia'], 'fora_da_conta' => $g['fora'], 'a_classificar' => $g['a_classificar'], 'pendentes' => $g['pendentes'], 'minimo' => $minimo,
            'prontidao' => $estado === 'cotada' ? round($taxa, 3) : null, 'tendencia' => $tend, 'atribuicao' => $g['atribuicao'],
            'ref_min' => $refMin, 'equivalente_min' => round($equiv, 1),
            'devolvido_min' => round($equiv + $g['erro_ia'] * $refMin - $g['humano_min'], 1), 'humano_min_registrado' => round($g['humano_min'], 1), 'correcoes_com_minutos' => $g['humano_medido'],
            'ia_seg_medio_por_peca' => $g['pecas'] > 0 && $g['ia_seg'] > 0 ? round($g['ia_seg'] / $g['pecas'], 1) : null];
    }
    usort($out, fn($a, $b) => $b['avaliadas'] <=> $a['avaliadas']);
    return ['leituras' => $out, 'a_classificar' => array_slice($aClass, 0, 50)];
}
