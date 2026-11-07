<?php
// =====================================================================
// Pontodot — BATIMENTO dos crons (painel de saúde)
// Cada cron chama pdBatimento('nome') ao começar: grava "rodei agora".
// O painel avisa quando um deles passa do tempo esperado. Se a tabela
// ainda não existe (SQL v63 não rodou), falha em silêncio — nunca
// derruba o cron.
// =====================================================================
function pdBatimento($chave, $detalhe = []) {
    if (!defined('SD_SUPABASE_URL') || !defined('SD_SECRET_KEY')) return;
    try {
        $ch = curl_init(SD_SUPABASE_URL . '/rest/v1/pd_batimento?on_conflict=chave');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8,
            CURLOPT_HTTPHEADER => ['apikey: ' . SD_SECRET_KEY, 'Content-Type: application/json',
                'Prefer: resolution=merge-duplicates,return=minimal'],
            CURLOPT_POSTFIELDS => json_encode(['chave' => $chave, 'ultima_em' => date('c'),
                'detalhe' => (object)$detalhe])]);
        curl_exec($ch); curl_close($ch);
    } catch (Throwable $e) { /* silêncio de propósito */ }
}

/** CENTRAL DE ERROS — grava "o que quebrou" na tabela pd_erros (SQL v150). Nunca derruba quem chamou; sem a tabela, só vai pro error_log. */
function pdErro($origem, $mensagem, $onde = '', $detalhe = []) {
    static $n = 0, $vistos = [];
    $mensagem = mb_substr((string)$mensagem, 0, 600); $k = md5($origem . $onde . $mensagem);
    if ($n >= 4 || isset($vistos[$k])) return; $n++; $vistos[$k] = 1;   // no máximo 4 por requisição, sem repetir
    error_log('[pd-erro ' . $origem . '] ' . ($onde ? $onde . ' · ' : '') . $mensagem);
    if (!defined('SD_SUPABASE_URL') || !defined('SD_SECRET_KEY')) return;
    try {
        $ch = curl_init(SD_SUPABASE_URL . '/rest/v1/pd_erros');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5,
            CURLOPT_HTTPHEADER => ['apikey: ' . SD_SECRET_KEY, 'Content-Type: application/json', 'Prefer: return=minimal'],
            CURLOPT_POSTFIELDS => json_encode(['origem' => $origem, 'onde' => mb_substr((string)$onde, 0, 200), 'mensagem' => $mensagem, 'detalhe' => (object)$detalhe, 'quem' => $GLOBALS['PD_ERRO_QUEM'] ?? null])]);
        curl_exec($ch); curl_close($ch);
    } catch (Throwable $e) { /* silêncio de propósito */ }
}
/** Liga a captura de erros do PHP: exceção que ninguém pegou e erro fatal (tela branca / HTTP 500). */
function pdErrosLiga() {
    static $on = false; if ($on) return; $on = true;
    $prev = set_exception_handler(function ($e) use (&$prev) {
        pdErro('php', get_class($e) . ': ' . $e->getMessage(), basename($e->getFile()) . ':' . $e->getLine(), ['url' => $_SERVER['REQUEST_URI'] ?? '']);
        if ($prev) return $prev($e);
        http_response_code(500); if (!headers_sent()) header('Content-Type: application/json; charset=utf-8'); echo json_encode(['error' => 'Erro interno. A equipe já foi avisada.']);
    });
    register_shutdown_function(function () {
        $e = error_get_last();
        if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true))
            pdErro('php', 'Fatal: ' . $e['message'], basename($e['file']) . ':' . $e['line'], ['url' => $_SERVER['REQUEST_URI'] ?? '']);
    });
}
