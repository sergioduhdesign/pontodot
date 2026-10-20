// =====================================================================
// Pontodot — O CARD DE TAREFA (o mesmo, na frente e no verso)
//
// Três coisas moram aqui, usadas pelos dois lados:
//   · a BATATA QUENTE — com quem está a vez agora, sempre à vista
//   · a TIMELINE — a linha do tempo REAL da tarefa (task_events +
//     conversa), registrada conforme as coisas acontecem
//   · o ALARME — aviso que fica piscando e apitando até alguém cuidar
//     (um som agradável, mas insistente; para na hora do clique)
//
// Quem usa: novo/index.html (frente) e app.html (verso).
// window.PDCARD — sem dependência além do cliente supabase de cada lado.
// =====================================================================
(function(){
'use strict';
const PD = window.PDCARD = {};
const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
PD.esc = esc;

// ------------------------------------------------------------- estilo
const CSS = `
.pd-batata{display:inline-flex;align-items:center;gap:6px;font-size:10px;font-weight:700;letter-spacing:.08em;
  padding:4px 10px;border-radius:999px;border:1px solid rgba(127,127,127,.35);white-space:nowrap;vertical-align:middle}
.pd-batata[data-quem="humano"]{color:#e8b64c;border-color:rgba(232,182,76,.55);background:rgba(232,182,76,.1)}
.pd-batata[data-quem="cliente"]{color:#e86aa6;border-color:rgba(232,106,166,.5);background:rgba(232,106,166,.08)}
.pd-batata[data-quem="gpt"],.pd-batata[data-quem="maestro"],.pd-batata[data-quem="robo"]{color:#5BC48E;border-color:rgba(91,196,142,.5);background:rgba(91,196,142,.08)}
.pd-tl{position:relative;margin:10px 0 4px;padding-left:26px}
.pd-tl:before{content:'';position:absolute;left:8px;top:6px;bottom:6px;width:1.5px;background:rgba(127,127,127,.28);border-radius:2px}
.pd-tl-ev{position:relative;margin:0 0 13px;font-size:12.5px;line-height:1.5}
.pd-tl-ev:last-child{margin-bottom:2px}
.pd-tl-dot{position:absolute;left:-26px;top:1px;width:17px;height:17px;border-radius:50%;display:flex;align-items:center;
  justify-content:center;font-size:9px;background:var(--vidro,rgba(30,30,34,.9));border:1.5px solid rgba(127,127,127,.4)}
.pd-tl-ev[data-q="humano"] .pd-tl-dot{border-color:#e8b64c}
.pd-tl-ev[data-q="cliente"] .pd-tl-dot{border-color:#e86aa6}
.pd-tl-ev[data-q="maestro"] .pd-tl-dot{border-color:#9a7bf7}
.pd-tl-ev[data-q="produtor"] .pd-tl-dot,.pd-tl-ev[data-q="robo"] .pd-tl-dot{border-color:#5BC48E}
.pd-tl-cab{display:flex;gap:8px;align-items:baseline;flex-wrap:wrap}
.pd-tl-cab b{font-weight:600}
.pd-tl-quem{font-size:10px;letter-spacing:.05em;opacity:.6}
.pd-tl-hora{font-size:10px;opacity:.5;margin-left:auto;font-variant-numeric:tabular-nums;flex-shrink:0}
.pd-tl-corpo{opacity:.82;white-space:pre-wrap;word-break:break-word;margin-top:1px;font-weight:400}
.pd-tl-fala{background:rgba(127,127,127,.1);border-radius:12px;padding:8px 12px;margin-top:3px}
.pd-al-caixa{position:fixed;right:14px;bottom:14px;z-index:99900;display:flex;flex-direction:column;gap:10px;max-width:min(380px,92vw)}
.pd-al{background:var(--vidro,rgba(26,27,30,.96));border:1.5px solid #e8b64c;border-radius:16px;padding:13px 15px;
  box-shadow:0 14px 40px rgba(0,0,0,.45);backdrop-filter:blur(10px);animation:pdAlPulso 2.2s ease-in-out infinite}
@keyframes pdAlPulso{0%,100%{box-shadow:0 14px 40px rgba(0,0,0,.45)}50%{box-shadow:0 14px 40px rgba(0,0,0,.45),0 0 0 5px rgba(232,182,76,.16)}}
.pd-al b{font-size:13px}
.pd-al p{margin:4px 0 10px;font-size:12px;line-height:1.5;opacity:.8}
.pd-al .acoes{display:flex;gap:8px}
.pd-al button{border:1px solid rgba(127,127,127,.4);background:rgba(232,182,76,.14);color:inherit;border-radius:9px;
  padding:7px 13px;font:inherit;font-size:12px;font-weight:600;cursor:pointer}
.pd-al button.depois{background:none;opacity:.6;font-weight:400}`;
if (!document.getElementById('pdCardCss')) {
  const st = document.createElement('style'); st.id = 'pdCardCss'; st.textContent = CSS;
  document.head.appendChild(st);
}

// ------------------------------------------------------- batata quente
const ROTULO = { humano:'com humano', maestro:'com o maestro', gpt:'produzindo · GPT', robo:'com o robô', cliente:'com o cliente' };
PD.tempoDesde = function(iso){
  const ms = Date.now() - new Date(iso).getTime();
  if (!(ms > 0)) return '';
  const h = ms / 3600000;
  if (h < 1) return 'há ' + Math.max(1, Math.round(ms / 60000)) + ' min';
  if (h < 48) return 'há ' + Math.round(h) + 'h';
  return 'há ' + Math.round(h / 24) + ' dias';
};
PD.batataHTML = function(t){
  if (!t || !t.batata || !ROTULO[t.batata]) return '';
  const desde = t.batata_em ? PD.tempoDesde(t.batata_em) : '';
  return `<span class="pd-batata" data-quem="${esc(t.batata)}" title="Com quem está a vez agora">` +
    `🥔 ${esc(ROTULO[t.batata])}${desde ? ' · ' + desde : ''}</span>`;
};
PD.batata = async function(sb, taskId, quem, deUser){
  try { await sb.from('tasks').update({ batata: quem || null, batata_de: deUser || null,
    batata_em: new Date().toISOString() }).eq('id', taskId); } catch(e){}
};

// --------------------------------------------------------- a timeline
const ICONE = { briefing:'📝', debriefing:'🎼', validacao:'✓', chamado_ia:'✉', entrega_ia:'⇣', lote:'▦',
  correcao:'↺', aprovacao:'✓', ajuste:'✎', liberado:'➤', agendado:'🕐', publicado:'★', zap:'✆', site:'⌂', nota:'·', fala:'' };
PD.evento = async function(sb, taskId, tipo, titulo, extra){
  try {
    const e = Object.assign({ task_id: taskId, tipo, titulo: String(titulo||'').slice(0,200) }, extra || {});
    await sb.from('task_events').insert(e);
  } catch(err){ console.warn('[timeline]', err && err.message); }
};
// junta task_events + comentários numa linha só, em ordem real
PD.timelineHTML = function(eventos){
  if (!eventos || !eventos.length) return '<div class="nota" style="padding:12px 4px">A história desta tarefa começa aqui embaixo.</div>';
  const dd = iso => { const d = new Date(iso);
    return `${String(d.getDate()).padStart(2,'0')}/${String(d.getMonth()+1).padStart(2,'0')} ${String(d.getHours()).padStart(2,'0')}h${String(d.getMinutes()).padStart(2,'0')}`; };
  return '<div class="pd-tl">' + eventos.map(e => `
    <div class="pd-tl-ev" data-q="${esc(e.quem_tipo || 'humano')}">
      <span class="pd-tl-dot">${ICONE[e.tipo] ?? '·'}</span>
      <div class="pd-tl-cab">${e.titulo ? '<b>' + esc(e.titulo) + '</b>' : ''}
        <span class="pd-tl-quem">${esc(e.quem || '')}</span>
        <span class="pd-tl-hora">${dd(e.criado_em)}</span></div>
      ${e.corpo ? `<div class="pd-tl-corpo${e.tipo === 'fala' ? ' pd-tl-fala' : ''}">${esc(e.corpo).slice(0, 900)}</div>` : ''}
    </div>`).join('') + '</div>';
};
PD.carregarTimeline = async function(sb, taskId, el, comentarios){
  if (!el) return;
  let evs = [];
  try {
    const { data } = await sb.from('task_events')
      .select('tipo,titulo,corpo,quem,quem_tipo,criado_em').eq('task_id', taskId)
      .order('criado_em', { ascending: true }).limit(200);
    evs = data || [];
  } catch(e){}
  (comentarios || []).forEach(c => evs.push({ tipo: 'fala', titulo: '', corpo: c.body,
    quem: (c.profiles?.name || c.quem || 'Equipe').split(' ')[0],
    quem_tipo: c.quem_tipo || 'humano', criado_em: c.created_at }));
  evs.sort((a, b) => new Date(a.criado_em) - new Date(b.criado_em));
  el.innerHTML = PD.timelineHTML(evs);
  el.scrollTop = el.scrollHeight;
  return evs.length;
};

// ----------------------------------------------------------- o alarme
// Toca um sino de duas notas, bonito mas insistente, a cada 8s enquanto
// houver alarme aberto. Começa depois do primeiro toque na página (regra
// do navegador) e PARA na hora em que alguém cuida.
let actx = null, somLigado = false, somTimer = null;
function nota(freq, t0, dur, vol){
  const o = actx.createOscillator(), g = actx.createGain();
  o.type = 'sine'; o.frequency.value = freq;
  g.gain.setValueAtTime(0, t0); g.gain.linearRampToValueAtTime(vol, t0 + .02);
  g.gain.exponentialRampToValueAtTime(.0001, t0 + dur);
  o.connect(g); g.connect(actx.destination); o.start(t0); o.stop(t0 + dur + .05);
}
function toca(){
  try {
    if (!actx) actx = new (window.AudioContext || window.webkitAudioContext)();
    if (actx.state === 'suspended') return;
    const t = actx.currentTime;
    nota(880, t, .5, .12); nota(1174.7, t + .18, .7, .1);
  } catch(e){}
}
function somLiga(on){
  if (on && !somTimer){ toca(); somTimer = setInterval(toca, 8000); }
  if (!on && somTimer){ clearInterval(somTimer); somTimer = null; }
}
document.addEventListener('pointerdown', () => {
  try { if (!actx) actx = new (window.AudioContext || window.webkitAudioContext)();
        if (actx.state === 'suspended') actx.resume(); } catch(e){}
}, { capture: true });

PD._abertos = [];
function pintarAlarmes(abrirLink){
  let box = document.getElementById('pdAlarmes');
  if (!PD._abertos.length){ if (box) box.remove(); somLiga(false); return; }
  if (!box){ box = document.createElement('div'); box.id = 'pdAlarmes'; box.className = 'pd-al-caixa'; document.body.appendChild(box); }
  box.innerHTML = PD._abertos.slice(0, 3).map(a => `
    <div class="pd-al" role="alert">
      <b>⚠ ${esc(a.titulo)}</b>
      ${a.corpo ? `<p>${esc(a.corpo).slice(0, 180)}</p>` : '<p></p>'}
      <div class="acoes">
        <button onclick="PDCARD.cuidar(${a.id}, true)">Cuidar agora</button>
        <button class="depois" onclick="PDCARD.cuidar(${a.id}, false)" title="Marca como cuidado sem abrir">já cuidei</button>
      </div>
    </div>`).join('')
    + (PD._abertos.length > 3 ? `<div style="text-align:center;font-size:11px;opacity:.6">+ ${PD._abertos.length - 3} esperando</div>` : '');
  somLiga(true);
}
PD.vigiarAlarmes = function(sb, perfil, opts){
  PD._sb = sb; PD._abrir = (opts && opts.abrir) || (l => { if (l) location.href = l; });
  const euCuido = a => !a.user_id || a.user_id === (perfil && perfil.id);
  const puxar = async () => {
    try {
      const { data } = await sb.from('pd_alarmes').select('id,titulo,corpo,link,task_id,user_id')
        .is('cuidado_em', null).order('criado_em', { ascending: true }).limit(20);
      PD._abertos = (data || []).filter(euCuido);
      pintarAlarmes();
    } catch(e){}
  };
  puxar();
  setInterval(puxar, 15000);
  try {
    sb.channel('pd-alarmes').on('postgres_changes', { event: '*', schema: 'public', table: 'pd_alarmes' }, puxar).subscribe();
  } catch(e){}
};
PD.cuidar = async function(id, abrir){
  const a = PD._abertos.find(x => x.id === id);
  PD._abertos = PD._abertos.filter(x => x.id !== id);
  pintarAlarmes();
  try {
    const { data: { user } } = await PD._sb.auth.getUser();
    await PD._sb.from('pd_alarmes').update({ cuidado_em: new Date().toISOString(), cuidado_por: user?.id || null }).eq('id', id);
  } catch(e){}
  if (abrir && a) PD._abrir(a.link, a);
};
// cria alarme + sino + push em todos os aparelhos de quem deve cuidar
PD.alarmar = async function(sb, al){
  try {
    await sb.from('pd_alarmes').insert({ user_id: al.user_id || null, task_id: al.task_id || null,
      tipo: al.tipo || 'outro', titulo: String(al.titulo||'').slice(0,140), corpo: al.corpo || null, link: al.link || null });
    const { data: { session } } = await sb.auth.getSession();
    for (const uid of (al.push_ids || [])) {
      if (!session) break;
      fetch('/push-send.php', { method: 'POST',
        headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + session.access_token },
        body: JSON.stringify({ to_user_id: uid, title: '⚠ ' + al.titulo,
          body: al.corpo || 'Precisa de você.', url: al.link || '/index.html' }) }).catch(()=>{});
    }
  } catch(e){ console.warn('[alarme]', e && e.message); }
};
})();
