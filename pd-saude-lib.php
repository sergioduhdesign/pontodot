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
