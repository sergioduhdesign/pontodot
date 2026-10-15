// ================================================================
// PONTODOT — Supabase config
// ================================================================
const SUPABASE_URL = 'https://ibkflplbraltewsjjsqm.supabase.co';
const SUPABASE_KEY = 'sb_publishable_M5Wws9n27ONN9JmG_qXbSg_e3U1xFxD';

const { createClient } = supabase;
// Dentro do verso (iframe) quem renova o login é a página de fora: dois relógios renovando o MESMO
// token ao mesmo tempo derrubavam a sessão. Aberto sozinho, ele mesmo renova.
const _dentroDoVerso = (() => { try { return window.self !== window.top; } catch (e) { return true; } })();
const sb = createClient(SUPABASE_URL, SUPABASE_KEY, { auth: { autoRefreshToken: !_dentroDoVerso } });

window.sb = sb;
