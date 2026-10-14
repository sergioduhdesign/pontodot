// ================================================================
// STUDIO DESIGN — Supabase config
// ================================================================
const SUPABASE_URL = 'https://ibkflplbraltewsjjsqm.supabase.co';
const SUPABASE_KEY = 'sb_publishable_M5Wws9n27ONN9JmG_qXbSg_e3U1xFxD';

const { createClient } = supabase;
const sb = createClient(SUPABASE_URL, SUPABASE_KEY);

window.sb = sb;
