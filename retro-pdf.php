<?php
// Retrospectiva — PDF da semana. Acesso por ?t=<token> (&fim=AAAA-MM-DD opcional).
require_once __DIR__ . '/../sd-private/sd-config.php';
require_once __DIR__ . '/retro-lib.php';
require_once __DIR__ . '/lib/fpdf/fpdf.php';

$t = preg_replace('/[^a-f0-9]/', '', (string)($_GET['t'] ?? ''));
$c = $t ? pd_supa('/rest/v1/pd_retro_config?token=eq.' . $t . '&ativo=eq.true&select=client_id') : null;
if (empty($c['body'][0]['client_id'])) { http_response_code(404); die('Link inválido.'); }
$cid = $c['body'][0]['client_id'];
$fim = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['fim'] ?? '')) ? $_GET['fim'] : null;
$q = '/rest/v1/pd_retro_snaps?client_id=eq.' . urlencode($cid) . '&tipo=eq.semanal' . ($fim ? '&fim=eq.' . $fim : '') . '&order=fim.desc&limit=1&select=dados';
$s = pd_supa($q)['body'][0]['dados'] ?? null;
if (!$s) { http_response_code(404); die('Ainda não há relatório.'); }

function L($x) { return iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', (string)$x); }
$cor = ['ink' => [29, 29, 31], 'mut' => [110, 110, 115], 'line' => [225, 225, 228], 'ok' => [34, 139, 84], 'mid' => [200, 150, 20], 'bad' => [200, 60, 50], 'acc' => [10, 10, 10]];

class RPDF extends FPDF { public $rod = '';
    function Footer() { $this->SetY(-12); $this->SetFont('Helvetica', '', 8); $this->SetTextColor(140, 140, 145); $this->Cell(0, 6, L($this->rod) . '  -  pagina ' . $this->PageNo(), 0, 0, 'C'); } }
$pdf = new RPDF('P', 'mm', 'A4'); $pdf->SetMargins(16, 16, 16); $pdf->SetAutoPageBreak(true, 18);
$pdf->rod = 'Pontodot  -  Retrospectiva ' . $s['cliente']; $pdf->AddPage();
$ink = fn() => $pdf->SetTextColor(...$GLOBALS['cor']['ink']); $mut = fn() => $pdf->SetTextColor(...$GLOBALS['cor']['mut']);

$per = $s['periodo'];
$pdf->SetFont('Helvetica', 'B', 20); $ink(); $pdf->Cell(0, 10, L('Retrospectiva semanal'), 0, 1);
$pdf->SetFont('Helvetica', '', 12); $mut(); $pdf->Cell(0, 6, L($s['cliente'] . '  -  ' . date('d/m', strtotime($per['ini'])) . ' a ' . date('d/m/Y', strtotime($per['fim']))), 0, 1);
$pdf->Ln(3); $pdf->SetDrawColor(...$cor['line']); $pdf->Line(16, $pdf->GetY(), 194, $pdf->GetY()); $pdf->Ln(5);

function H($pdf, $txt) { $pdf->Ln(2); $pdf->SetFont('Helvetica', 'B', 12); $pdf->SetTextColor(29, 29, 31); $pdf->Cell(0, 7, L($txt), 0, 1); }
function seta($p) { return $p === null ? '' : ($p > 0 ? '+' : '') . round($p) . '%'; }
$brl = fn($v) => $v === null ? '-' : 'R$ ' . number_format((float)$v, 2, ',', '.');

if (!empty($s['ads']['atual'])) {
    $a = $s['ads']['atual']; $tn = $s['ads']['tendencia'];
    H($pdf, 'Investimento e volume (Meta Ads)');
    $cards = [['Investido', $brl($a['gasto']), seta($tn['gasto'])], ['Leads', (string)$a['leads'], seta($tn['leads'])], ['CPL', $brl($a['cpl']), seta($tn['cpl'])], ['CTR', number_format((float)$a['ctr'], 2, ',', '.') . '%', seta($tn['ctr'])]];
    $x0 = 16; $w = 43; $y = $pdf->GetY();
    foreach ($cards as $i => [$r, $v, $d]) { $x = $x0 + $i * ($w + 2);
        $pdf->SetDrawColor(...$cor['line']); $pdf->Rect($x, $y, $w, 22);
        $pdf->SetXY($x + 3, $y + 3); $pdf->SetFont('Helvetica', '', 8); $mut(); $pdf->Cell($w - 6, 4, L($r), 0, 2);
        $pdf->SetFont('Helvetica', 'B', 13); $ink(); $pdf->Cell($w - 6, 7, L($v), 0, 2);
        $pdf->SetFont('Helvetica', '', 8); $mut(); $pdf->Cell($w - 6, 4, L($d !== '' ? $d . ' vs. semana anterior' : ''), 0, 0); }
    $pdf->SetY($y + 27);
    $pdf->SetFont('Helvetica', '', 10); $mut();
    $pdf->MultiCell(0, 5.5, L(number_format($a['cliques'], 0, ',', '.') . ' cliques  |  ' . number_format($a['impressoes'], 0, ',', '.') . ' impressoes  |  CPM ' . $brl($a['cpm']) . '  |  frequencia ' . number_format((float)$a['freq'], 2, ',', '.') . '  |  alcance ' . number_format($a['alcance'], 0, ',', '.')));

    H($pdf, 'Performance por dia');
    $dias = $s['ads']['por_dia']; $maxG = max(1, max(array_column($dias, 'gasto'))); $y = $pdf->GetY() + 2; $bw = 22; $hh = 30;
    foreach ($dias as $i => $d) { $x = 16 + $i * 25.4; $h = $d['gasto'] / $maxG * $hh;
        $pdf->SetFillColor(...($d['leads'] > 0 ? [29, 29, 31] : [200, 200, 205])); $pdf->Rect($x, $y + $hh - $h, $bw, max($h, .4), 'F');
        $pdf->SetFont('Helvetica', '', 7); $mut(); $pdf->SetXY($x, $y + $hh + 1); $pdf->Cell($bw, 3.5, date('d/m', strtotime($d['d'])), 0, 2, 'C');
        $pdf->Cell($bw, 3.5, L($d['leads'] . ' leads'), 0, 0, 'C'); }
    $pdf->SetY($y + $hh + 10); $pdf->SetFont('Helvetica', '', 8); $mut(); $pdf->Cell(0, 4, L('Altura = investimento do dia. Barra escura = dia com lead.'), 0, 1);

    if (!empty($s['mercado']['metricas'])) {
        H($pdf, 'Voce vs. mercado - ' . $s['mercado']['rotulo_segmento']);
        $nome = ['melhor' => 'melhor que o mercado', 'na_media' => 'na media', 'pior' => 'acima do ideal']; $cp = ['melhor' => $cor['ok'], 'na_media' => $cor['mid'], 'pior' => $cor['bad']];
        foreach ($s['mercado']['metricas'] as $k => $m) { if ($k === 'freq') continue;
            $v = $m['unidade'] === 'brl' ? $brl($m['valor']) : number_format($m['valor'], 2, ',', '.') . '%';
            $ref = $m['plataforma'] ?: $m['publico']; $fm = fn($z) => $m['unidade'] === 'brl' ? $brl($z) : number_format($z, 2, ',', '.') . '%';
            $pdf->SetFont('Helvetica', 'B', 10); $ink(); $pdf->Cell(22, 6, strtoupper($k), 0, 0); $pdf->Cell(32, 6, L($v), 0, 0);
            $pdf->SetTextColor(...$cp[$m['pos']]); $pdf->Cell(48, 6, L($nome[$m['pos']]), 0, 0);
            $pdf->Ln(5.5); $pdf->SetFont('Helvetica', '', 8); $mut(); $pdf->Cell(0, 5, L('faixa tipica ' . $fm($ref['p25']) . ' a ' . $fm($ref['p75']) . ' (mediana ' . $fm($ref['mediana']) . ')  ' . ($m['ref_usada'] === 'plataforma' ? '[carteira Pontodot]' : '[referencia publica]')), 0, 1); $pdf->Ln(1.5); }
    }
    if (!empty($s['ads']['campanhas'])) {
        H($pdf, 'Campanhas');
        $pdf->SetFont('Helvetica', 'B', 8); $mut(); $pdf->Cell(92, 5, 'Campanha', 'B', 0); $pdf->Cell(28, 5, 'Investido', 'B', 0, 'R'); $pdf->Cell(20, 5, 'Leads', 'B', 0, 'R'); $pdf->Cell(0, 5, 'CPL', 'B', 1, 'R');
        $pdf->SetFont('Helvetica', '', 9); $ink();
        foreach (array_slice($s['ads']['campanhas'], 0, 10) as $c2) { $pdf->Cell(92, 6, L(mb_substr($c2['nome'], 0, 52)), 0, 0); $pdf->Cell(28, 6, L($brl($c2['gasto'])), 0, 0, 'R'); $pdf->Cell(20, 6, (string)$c2['leads'], 0, 0, 'R'); $pdf->Cell(0, 6, L($brl($c2['cpl'])), 0, 1, 'R'); }
    }
}
if (!empty($s['organico'])) { $o = $s['organico']; H($pdf, 'Instagram organico');
    $pdf->SetFont('Helvetica', '', 10); $ink(); $pdf->MultiCell(0, 6, L('Alcance ' . number_format($o['alcance'], 0, ',', '.') . '  |  Interacoes ' . number_format($o['interacoes'], 0, ',', '.') . '  |  Seguidores ' . number_format($o['seguidores'], 0, ',', '.') . (isset($o['posts']) ? '  |  ' . $o['posts'] . ' posts publicados' : ''))); }
if (!empty($s['alertas'])) { H($pdf, 'Atencao');
    foreach ($s['alertas'] as $al) { $pdf->SetFont('Helvetica', 'B', 9); $pdf->SetTextColor(...($al['tipo'] === 'alerta' ? $cor['bad'] : $cor['ok'])); $pdf->Cell(6, 5.5, $al['tipo'] === 'alerta' ? '!' : '+', 0, 0);
        $pdf->SetFont('Helvetica', '', 10); $ink(); $pdf->MultiCell(0, 5.5, L($al['texto'])); } }
if (!empty($s['analise'])) { H($pdf, 'Leitura da semana'); $pdf->SetFont('Helvetica', '', 10); $ink(); $pdf->MultiCell(0, 5.5, L(str_replace('•', '-', $s['analise']))); }
if (empty($s['ads']['atual'])) { $pdf->SetFont('Helvetica', '', 11); $mut(); $pdf->MultiCell(0, 6, L('Sem dados de anuncios nesta semana. ' . implode(' ', $s['ads']['erros'] ?? []))); }

header_remove('Content-Type');
$pdf->Output('I', 'retrospectiva-' . preg_replace('/[^a-z0-9]+/', '-', strtolower($s['cliente'])) . '-' . $per['fim'] . '.pdf');
