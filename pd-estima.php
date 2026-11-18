<?php
// =====================================================================
// TEMPO DE PRODUÇÃO — a IA da casa lê a tarefa e diz a frente e o tempo.
// Base: catálogo de tempos de referência (pd_ref_tempos), o tempo REAL das
// tarefas já entregues (cronômetro) e o peso de cada cliente (quem costuma
// dar mais trabalho que a média entra com tempo maior).
//   POST { ids:[...], forcar? }  →  { ok, itens:[{id,frente,ref,est_min,lo,hi,nota}] }
// Grava em pd_fila como sugestão ('ia'); o que a pessoa já conferiu não é sobrescrito.
// =====================================================================
require_once __DIR__ . '/sd-auth.php';
require_once __DIR__ . '/pd-lib.php';
require_once __DIR__ . '/pd-aula-lib.php';
header('Content-Type: application/json; charset=utf-8');
@set_time_limit(90);

$u = sd_current_user();
if (!in_array(($u['role'] ?? ''), ['admin', 'team', 'client'], true)) sd_fail('Sem acesso.', 403);
$b = sd_body();
$ids = array_slice(array_values(array_unique(array_filter(array_map(fn($x) => preg_replace('/[^A-Za-z0-9_\-]/', '', (string)$x), (array)($b['ids'] ?? []))))), 0, 12);
if (!$ids) sd_fail('Sem tarefas para estimar.');
$forcar = !empty($b['forcar']) && ($u['role'] ?? '') !== 'client';

$FRENTES = ['Design', 'Imagem e 3D', 'Vídeo', 'Texto e conteúdo', 'Social media', 'Web', 'Mídia e campanhas', 'Estratégia e branding', 'Produção e eventos', 'Atendimento e gestão'];
$in = rawurlencode('(' . implode(',', array_map(fn($i) => '"' . $i . '"', $ids)) . ')');
$t = sd_supabase('/rest/v1/tasks?id=in.' . $in . '&select=id,action,obs,project,status,client_id,resp,clients(name)');
$tarefas = array_values(array_filter((array)($t['body'] ?? []), fn($x) => is_array($x) && (($u['role'] ?? '') !== 'client' || ($x['client_id'] ?? null) === ($u['client_id'] ?? '-'))));
if (!$tarefas) sd_fail('Não achei essas tarefas.');

// o que a pessoa já conferiu fica como está
$ja = sd_supabase('/rest/v1/pd_fila?task_id=in.' . $in . '&select=task_id,est_fonte,est_min');
if (($ja['code'] ?? 0) >= 400) sd_fail('Falta rodar o SQL v193 (frentes e tempo).', 409);
$conf = []; foreach ((array)($ja['body'] ?? []) as $r) if (($r['est_fonte'] ?? '') === 'pessoa' || (!$forcar && ($r['est_min'] ?? null))) $conf[$r['task_id']] = true;
$tarefas = array_values(array_filter($tarefas, fn($x) => empty($conf[$x['id']])));
if (!$tarefas) { echo json_encode(['ok' => true, 'itens' => []]); exit; }

// catálogo e histórico real
$cat = (array)(sd_supabase('/rest/v1/pd_ref_tempos?ativo=eq.true&select=codigo,categoria,tarefa,rapido_min,medio_min,longo_min,unidade&order=codigo&limit=200')['body'] ?? []);
$hist = (array)(sd_supabase('/rest/v1/tasks?status=eq.ENTREGUE&total_seconds=gt.300&select=action,client_id,total_seconds&order=created_at.desc&limit=500')['body'] ?? []);
$mediana = function (array $v) { if (!$v) return 0; sort($v); $n = count($v); return $n % 2 ? $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2; };
$min = fn($h) => max(1, (int)round(((int)($h['total_seconds'] ?? 0)) / 60));
$casa = $mediana(array_map($min, array_filter($hist, 'is_array')));
$porCli = []; foreach ($hist as $h) if (is_array($h) && !empty($h['client_id'])) $porCli[$h['client_id']][] = $min($h);
$peso = function ($cid) use ($porCli, $casa, $mediana) { if (!$cid || $casa <= 0 || count($porCli[$cid] ?? []) < 4) return 1.0; return max(.7, min(2.2, round($mediana($porCli[$cid]) / $casa, 2))); };
$palavras = fn($s) => array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower((string)$s)), fn($w) => mb_strlen($w) > 3));
$parecidas = function ($tar) use ($hist, $palavras, $min) { $p = $palavras($tar['action'] ?? ''); if (!$p) return []; $r = [];
    foreach ($hist as $h) { if (!is_array($h)) continue; $n = count(array_intersect($p, $palavras($h['action'] ?? ''))); if ($n < 1) continue; $r[] = [$n + ((($h['client_id'] ?? '') === ($tar['client_id'] ?? '-')) ? 1 : 0), mb_substr((string)$h['action'], 0, 70) . ' = ' . $min($h) . ' min']; }
    usort($r, fn($a, $c) => $c[0] <=> $a[0]); return array_map(fn($x) => $x[1], array_slice($r, 0, 5)); };

$linhasCat = implode("\n", array_map(fn($c) => ($c['codigo'] ?? '') . ' | ' . ($c['categoria'] ?? '') . ' | ' . ($c['tarefa'] ?? '') . ' | ' . (int)($c['rapido_min'] ?? 0) . '/' . (int)($c['medio_min'] ?? 0) . '/' . (int)($c['longo_min'] ?? 0) . ' min' . (!empty($c['unidade']) ? ' | ' . mb_substr((string)$c['unidade'], 0, 60) : ''), array_filter($cat, 'is_array')));
$system = "Você é a gestora de produção de uma agência de branding e conteúdo. Para cada tarefa, diga a FRENTE de atuação e o TEMPO DE PRODUÇÃO ATIVO (trabalho de fato, sem espera de aprovação), em minutos."
    . "\nFrentes possíveis (use exatamente um destes nomes): " . implode('; ', $FRENTES) . '.'
    . "\nComo estimar: 1) se houver tarefas parecidas já entregues com tempo real, elas valem mais que qualquer tabela; 2) senão, use o catálogo (rápido/médio/longo) escolhendo o item mais próximo e o porte que o texto da tarefa sugere; 3) na dúvida do porte, use o médio. Não considere o cliente: o peso de cada cliente é aplicado depois."
    . "\nResponda SÓ com JSON, uma lista: [{\"id\":\"...\",\"frente\":\"...\",\"ref\":\"código do catálogo ou null\",\"base_min\":número,\"lo\":número,\"hi\":número,\"nota\":\"até 90 caracteres dizendo de onde veio a conta\"}]"
    . "\n\nCATÁLOGO (código | categoria | tarefa | rápido/médio/longo):\n" . $linhasCat;
$user = "TAREFAS:\n";
foreach ($tarefas as $x) { $ps = $parecidas($x);
    $user .= "\n- id " . $x['id'] . ': ' . mb_substr((string)($x['action'] ?? ''), 0, 160) . (!empty($x['obs']) ? ' · detalhes: ' . mb_substr(preg_replace('/\s+/u', ' ', (string)$x['obs']), 0, 300) : '') . ' · projeto: ' . ($x['project'] ?? 'GERAL')
        . ($ps ? "\n  parecidas já entregues (tempo real): " . implode('; ', $ps) : ''); }
[$txt, $custo, $erro] = pdClaude($system, $user, 1600, defined('PD_CATUM_MODEL') ? PD_CATUM_MODEL : null);
if ($erro || !$txt) sd_fail('A IA não respondeu agora. Tente de novo.', 502);
$a = strpos($txt, '['); $z = strrpos($txt, ']');
$lista = ($a !== false && $z !== false && $z > $a) ? json_decode(substr($txt, $a, $z - $a + 1), true) : null;
if (!is_array($lista)) sd_fail('Não entendi a resposta da IA. Tente de novo.', 502);

$porId = []; foreach ($tarefas as $x) $porId[$x['id']] = $x;
$itens = []; $grava = []; $agora = gmdate('c');
foreach ($lista as $r) {
    if (!is_array($r) || empty($porId[$r['id'] ?? ''])) continue; $x = $porId[$r['id']];
    $base = max(5, min(60 * 80, (int)round((float)($r['base_min'] ?? 0)))); if ($base <= 5 && empty($r['base_min'])) continue;
    $p = $peso($x['client_id'] ?? null); $arr = fn($v) => (int)(round($v / 5) * 5);
    $est = max(5, $arr($base * $p)); $lo = max(5, $arr(max(5, (float)($r['lo'] ?? $base * .7)) * $p)); $hi = max($est, $arr(max($base, (float)($r['hi'] ?? $base * 1.5)) * $p));
    $frente = in_array(($r['frente'] ?? ''), $FRENTES, true) ? $r['frente'] : 'Atendimento e gestão';
    $nota = mb_substr(trim((string)($r['nota'] ?? '')), 0, 110) . ($p != 1.0 ? ' · peso do cliente ' . str_replace('.', ',', (string)$p) . 'x' : '');
    $itens[] = ['id' => $x['id'], 'frente' => $frente, 'ref' => $r['ref'] ?? null, 'est_min' => $est, 'lo' => $lo, 'hi' => $hi, 'nota' => $nota];
    $grava[] = ['task_id' => $x['id'], 'client_id' => $x['client_id'] ?? null, 'frente' => $frente, 'ref_codigo' => !empty($r['ref']) ? mb_substr((string)$r['ref'], 0, 12) : null,
        'est_min' => $est, 'est_lo' => $lo, 'est_hi' => $hi, 'est_fonte' => 'ia', 'est_nota' => $nota, 'est_em' => $agora];
}
if ($grava) sd_supabase('/rest/v1/pd_fila?on_conflict=task_id', 'POST', $grava, ['Prefer: resolution=merge-duplicates,return=minimal']);
echo json_encode(['ok' => true, 'itens' => $itens], JSON_UNESCAPED_UNICODE);
