<?php
// PD · MODELOS — planilhas para baixar (lista + download ao vivo do Drive). Qualquer pessoa logada da equipe.
require_once __DIR__ . '/sd-base.php';
require_once __DIR__ . '/sd-auth.php';
require_once __DIR__ . '/pd-lib.php';
require_once __DIR__ . '/pd-series-lib.php';
$u = sd_current_user(); if (!$u) { http_response_code(401); header('Content-Type: application/json'); echo json_encode(['ok' => false, 'erro' => 'Entre na plataforma.']); exit; }
$b = sd_body(); $acao = (string)($b['acao'] ?? 'lista');
if ($acao === 'baixar') {
    $cfg = vsConfig(); $id = $cfg['planilha_id'] ?? null; if (!$id) sd_fail('Planilha não configurada.', 404);
    try { $r = ponte('baixar', ['fileId' => $id]); } catch (Throwable $e) { sd_fail('Não consegui buscar no Drive: ' . mb_substr($e->getMessage(), 0, 120), 502); }
    $bin = base64_decode((string)($r['b64'] ?? ''), true); if (!$bin) sd_fail('Arquivo vazio.', 502);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'); header('Content-Disposition: attachment; filename="SD_Vistoria_Social_Semanal.xlsx"'); header('Content-Length: ' . strlen($bin)); echo $bin; exit;
}
header('Content-Type: application/json; charset=utf-8');
$cfg = vsConfig();
echo json_encode(['ok' => true, 'modelos' => [
    ['nome' => 'Vistoria semanal de social (ao vivo, do Drive)', 'nota' => 'A planilha que a Camila preenche. Baixa a versão de agora.', 'acao' => 'baixar', 'url_editar' => $cfg['planilha_url'] ?? null],
    ['nome' => 'Modelo de importação de tarefas', 'nota' => 'Cria e atualiza tarefas em lote.', 'url' => 'STUDIO_DESIGN_modelo_importacao.xlsx'],
    ['nome' => 'Modelo de lote de aprovação', 'nota' => 'Peças e legendas para o cliente aprovar.', 'url' => 'SD_modelo_lote_aprovacao.xlsx'],
]], JSON_UNESCAPED_UNICODE);
