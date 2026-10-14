// ================================================================
// STUDIO DESIGN — UI v7
// ================================================================

// ================================================================
// TEMAS — catálogo + troca + relógio automático + fonte + pessoal
//
// "light" e "dark" continuam existindo como estavam (nada quebra pra
// quem já usa). Os temas novos entram no mesmo mecanismo: trocar o
// data-theme do <html> e o resto do CSS reage sozinho, porque tudo já
// usa as variáveis --bg/--surface/--text/etc.
// ================================================================
const THEME_CATALOG = [
  { id:'light',      nome:'Claro',        cores:['#ffffff','#f4f4f5','#0a0a0a'] },
  { id:'dark',       nome:'Escuro',       cores:['#0c0c0e','#141416','#f4f4f8'] },
  { id:'minimal',    nome:'Minimalista',  cores:['#ffffff','#fafafa','#0a0a0a'] },
  { id:'mac-retro',  nome:'Mac Retrô',    cores:['#d8d8d8','#eeeeee','#000000'] },
  { id:'win-retro',  nome:'Windows Retrô',cores:['#c0c0c0','#000080','#000000'] },
];

const Theme = {
  init() {
    const modo = localStorage.getItem('sd_theme_mode') || 'manual';   // manual | auto
    if (modo === 'auto') {
      this.aplicarPorRelogio();
      // Reavalia a cada 10 min — cobre a virada de horário sem precisar recarregar.
      setInterval(() => { if (this.getModo() === 'auto') this.aplicarPorRelogio(); }, 10 * 60 * 1000);
    } else {
      const saved = localStorage.getItem('sd_theme');
      const dark = window.matchMedia('(prefers-color-scheme: dark)').matches;
      this.set(saved || (dark ? 'dark' : 'light'), false);
    }
    this.aplicarFonte();
  },

  getModo(){ return localStorage.getItem('sd_theme_mode') || 'manual'; },

  set(theme, salvarComoManual = true) {
    // Mostra "Aplicando tema..." por um instante — a troca em si é
    // instantânea, mas sem esse retorno visual o clique parecia não
    // ter feito nada.
    const overlay = document.getElementById('prof-theme-loading');
    if (overlay) {
      overlay.style.display = 'flex';
      setTimeout(() => { overlay.style.display = 'none'; }, 320);
    }

    if (theme === 'personal') {
      this.aplicarPersonalSalvo();
    } else {
      // Sai do modo pessoal: limpa variáveis inline que ele tenha aplicado
      this.limparPersonalInline();
      document.documentElement.setAttribute('data-theme', theme);
    }
    if (salvarComoManual) {
      localStorage.setItem('sd_theme', theme);
      localStorage.setItem('sd_theme_mode', 'manual');
    }
    const btn = document.getElementById('theme-btn');
    if (btn) btn.textContent = (theme === 'dark') ? '☀' : '◑';
    if (window.updateLogoTheme) updateLogoTheme();
    if (window.renderThemePanel) renderThemePanel();
  },

  toggle() {
    // Atalho rápido continua existindo: sol/lua alterna claro/escuro,
    // como sempre foi. Os outros temas ficam no painel.
    this.set(document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark');
  },

  // ---------- Dia/Noite automático pelo relógio ----------
  setAuto(ligado){
    localStorage.setItem('sd_theme_mode', ligado ? 'auto' : 'manual');
    if (ligado) this.aplicarPorRelogio();
    else this.set(localStorage.getItem('sd_theme') || 'light');
  },
  aplicarPorRelogio(){
    const hora = new Date().getHours();
    const noite = hora >= 19 || hora < 7;   // 19h–7h = noite
    this.set(noite ? 'dark' : 'light', false);
  },

  // ---------- Tamanho de fonte ----------
  // A base de código usa muito px direto (não rem), então root font-size
  // não escalaria a maior parte da tela. `zoom` escala tudo de verdade —
  // funciona em Chrome, Edge e Safari recentes. No Firefox (que não
  // suporta zoom), o botão continua clicável mas sem efeito visual;
  // é uma limitação real do navegador, não do código.
  FS_STEPS: [90, 100, 112, 125],
  aplicarFonte(idx){
    const atual = idx ?? parseInt(localStorage.getItem('sd_fs_idx') || '1', 10);
    const pct = this.FS_STEPS[atual];
    // No BODY, não no #app: vários modais (permissões, peças, exclusão)
    // são criados soltos, direto como filhos do body, não de dentro do
    // #app. Se o zoom estivesse só no #app, esses modais ficariam com
    // tamanho diferente do resto da tela. No body, tudo escala junto.
    document.body.style.zoom = pct + '%';
    localStorage.setItem('sd_fs_idx', atual);
    const lbl = document.getElementById('fs-label');
    if (lbl) lbl.textContent = pct + '%';
    document.getElementById('fs-menos')?.toggleAttribute('disabled', atual === 0);
    document.getElementById('fs-mais')?.toggleAttribute('disabled', atual === this.FS_STEPS.length - 1);
  },
  fonteMenos(){ const i = Math.max(0, (parseInt(localStorage.getItem('sd_fs_idx')||'1',10)) - 1); this.aplicarFonte(i); },
  fonteMais(){ const i = Math.min(this.FS_STEPS.length-1, (parseInt(localStorage.getItem('sd_fs_idx')||'1',10)) + 1); this.aplicarFonte(i); },

  // ---------- Tema pessoal ----------
  // Sem tabela no banco ainda (fica pra quando o Supabase estiver
  // liberado): a pessoa escolhe as cores, e ganha um código curto pra
  // copiar e mandar pro time — quem colar em "Importar tema" aplica
  // o mesmo visual. É "compartilhável" por cópia, não automático.
  aplicarPersonal(cores){
    document.documentElement.setAttribute('data-theme', 'personal');
    const root = document.documentElement.style;
    root.setProperty('--bg', cores.bg);
    root.setProperty('--surface', cores.surface);
    root.setProperty('--surface2', cores.surface2);
    root.setProperty('--text', cores.text);
    root.setProperty('--accent', cores.accent);
    root.setProperty('--accent-fg', cores.accentFg);
    root.setProperty('--border', cores.border);
  },
  limparPersonalInline(){
    ['--bg','--surface','--surface2','--text','--accent','--accent-fg','--border']
      .forEach(v => document.documentElement.style.removeProperty(v));
  },
  aplicarPersonalSalvo(){
    const salvo = localStorage.getItem('sd_theme_personal');
    if (!salvo) { this.set('light'); return; }
    try { this.aplicarPersonal(JSON.parse(salvo)); }
    catch(e){ this.set('light'); }
  },
  salvarPersonal(cores){
    localStorage.setItem('sd_theme_personal', JSON.stringify(cores));
    localStorage.setItem('sd_theme', 'personal');
    localStorage.setItem('sd_theme_mode', 'manual');
    this.aplicarPersonal(cores);
    if (window.renderThemePanel) renderThemePanel();
  },
  // Código curto tipo "SD1-a0a0a0-ffffff-..." — só as 7 cores em hex,
  // sem separador ambíguo, fácil de colar no WhatsApp.
  codificarPersonal(cores){
    const hex = c => c.replace('#','');
    return ['SD1', hex(cores.bg), hex(cores.surface), hex(cores.surface2),
            hex(cores.text), hex(cores.accent), hex(cores.accentFg), hex(cores.border)].join('-');
  },
  decodificarPersonal(codigo){
    const p = String(codigo||'').trim().split('-');
    if (p.length !== 8 || p[0] !== 'SD1') return null;
    const h = v => '#' + v;
    return { bg:h(p[1]), surface:h(p[2]), surface2:h(p[3]), text:h(p[4]),
             accent:h(p[5]), accentFg:h(p[6]), border:h(p[7]) };
  }
};

function applyClientColor(color) {
  if (!color) { resetClientColor(); return; }
  const r = parseInt(color.slice(1,3),16), g = parseInt(color.slice(3,5),16), b = parseInt(color.slice(5,7),16);
  const dark = document.documentElement.getAttribute('data-theme') === 'dark';
  document.body.style.transition = 'background 0.4s ease';
  document.body.style.background = dark ? `rgba(${r},${g},${b},0.22)` : `rgba(${r},${g},${b},0.18)`;
}
function resetClientColor() { document.body.style.background = ''; }

function showToast(msg, type = 'info') {
  let c = document.getElementById('toast-container');
  if (!c) {
    c = document.createElement('div');
    c.id = 'toast-container';
    c.style.cssText = 'position:fixed;bottom:80px;right:20px;z-index:9999;display:flex;flex-direction:column;gap:8px;pointer-events:none';
    document.body.appendChild(c);
  }
  const colors = { info:'#2563eb', success:'#16a34a', error:'#dc2626', warn:'#d97706' };
  const t = document.createElement('div');
  t.style.cssText = `padding:10px 16px;background:var(--surface);border:1px solid var(--border);border-left:3px solid ${colors[type]||colors.info};border-radius:9px;font-size:13px;color:var(--text);box-shadow:0 4px 20px rgba(0,0,0,0.14);max-width:300px;opacity:0;transform:translateX(20px);transition:all 0.28s cubic-bezier(0.34,1.56,0.64,1);pointer-events:auto;font-family:'Helvetica Neue',Helvetica,Arial,sans-serif`;
  t.textContent = msg;
  c.appendChild(t);
  requestAnimationFrame(() => { t.style.opacity='1'; t.style.transform='translateX(0)'; });
  setTimeout(() => { t.style.opacity='0'; t.style.transform='translateX(20px)'; setTimeout(() => t.remove(), 300); }, 3500);
}

// ================================================================
// MODAIS + BOTÃO DE VOLTAR NATIVO
//
// Antes, o botão físico de voltar do Android (ou o gesto de deslizar
// da borda) fechava o app inteiro quando um modal estava aberto —
// porque o navegador não sabia que "havia algo para voltar". Agora
// cada modal aberto empilha uma entrada no histórico; voltar fecha
// o modal em vez de sair do app. Empilhar vários modais também
// funciona: cada "voltar" fecha um de cada vez.
// ================================================================
let modalStack = [];

function openModal(id) {
  const el = document.getElementById(id);
  if (!el) return;
  el.classList.add('open');
  document.body.style.overflow = 'hidden';

  modalStack.push(id);
  history.pushState({ sdModal: id }, '');
}

function closeModal(id) {
  const el = document.getElementById(id);
  if (!el) return;
  el.classList.remove('open');

  const i = modalStack.lastIndexOf(id);
  if (i !== -1) modalStack.splice(i, 1);
  if (!modalStack.length) document.body.style.overflow = '';

  // Fechou pelo X ou clicando fora (não pelo botão voltar): desfaz a
  // entrada que este modal empilhou, para o histórico não crescer à toa.
  if (history.state && history.state.sdModal === id) {
    history.back();
  }
}

// Botão físico / gesto de voltar do aparelho
window.addEventListener('popstate', (ev) => {
  if (!modalStack.length) return;   // nada aberto: deixa o navegador agir normal
  const id = modalStack.pop();
  const el = document.getElementById(id);
  if (el) {
    el.classList.remove('open');
    if (!modalStack.length) document.body.style.overflow = '';
  }
});

function avatarHTML(name, extraStyle='', size=28) {
  const initials = (name||'?').split(' ').map(w=>w[0]).slice(0,2).join('').toUpperCase();
  const colors = ['#2563eb','#7c3aed','#dc2626','#16a34a','#d97706','#0891b2','#db2777','#0a0a0a'];
  const color = colors[(name||'').charCodeAt(0) % colors.length];
  return `<div style="width:${size}px;height:${size}px;background:${color};border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:${Math.round(size*0.36)}px;font-weight:700;color:#fff;flex-shrink:0;${extraStyle}">${initials}</div>`;
}

function linkify(text) {
  if (!text) return '';
  return String(text)
    .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
    .replace(/(https?:\/\/[^\s<]+)/g,'<a href="$1" target="_blank" rel="noopener" style="color:#2563eb;font-weight:600;text-decoration:underline">$1</a>')
    // @Nome — vira chip clicável, igual menção de Slack. Só marca nomes que
    // batem com alguém de verdade (a lista vem de window.MENCIONAVEIS,
    // montada a partir dos profiles ativos), pra não grifar um "@" solto
    // que a pessoa só usou como pontuação.
    .replace(/@([A-Za-zÀ-ÿ]+(?:\s[A-Za-zÀ-ÿ]+)?)/g, (m, nome) => {
      const achou = (window.MENCIONAVEIS||[]).find(p =>
        p.nome.toLowerCase() === nome.toLowerCase() ||
        p.primeiroNome.toLowerCase() === nome.toLowerCase());
      if (!achou) return m;
      return `<span style="color:#2563eb;font-weight:700;background:rgba(37,99,235,.08);padding:1px 4px;border-radius:4px">@${achou.nome}</span>`;
    })
    // #tag — destaca em roxo, no mesmo tom do resto da plataforma
    .replace(/#([A-Za-zÀ-ÿ0-9_-]{2,})/g,
      '<span style="color:#6366f1;font-weight:700;background:rgba(99,102,241,.08);padding:1px 4px;border-radius:4px">#$1</span>');
}

function processCardImage(file, callback) {
  const reader = new FileReader();
  reader.onload = e => {
    const img = new Image();
    img.onload = () => {
      const size = Math.min(img.width, img.height);
      const canvas = document.createElement('canvas');
      canvas.width = 400; canvas.height = 400;
      canvas.getContext('2d').drawImage(img, (img.width-size)/2, (img.height-size)/2, size, size, 0, 0, 400, 400);
      canvas.toBlob(blob => callback(blob), 'image/jpeg', 0.85);
    };
    img.src = e.target.result;
  };
  reader.readAsDataURL(file);
}

const EMOJIS = [
  '👍','❤️','🔥','✅','⚠️','🎯','💡','📌','🚀','⏰',
  '💬','🙌','👀','🤔','✏️','📎','🖼️','📊','🎨','💪',
  '😊','😅','🙏','👏','🤝','⭐','🌟','💯','🔔','📢',
  '🏆','✨','💎','🎉','🎊','😍','😎','🤩','🗓️','📱',
  '💻','🖥️','📸','🎬','🎙️','📝','📋','🗂️','📁','🏠',
  '🏢','🌆','🌇','🏗️','🏘️','🌳','🌊','☀️','🎨','🖌️'
];

function createEmojiPicker(targetOrCallback, position='bottom') {
  const wrap = document.createElement('div');
  wrap.className = 'emoji-picker-pop';
  wrap.style.cssText = position === 'top'
    ? 'position:absolute;bottom:calc(100% + 6px);left:0;'
    : 'position:absolute;top:calc(100% + 6px);left:0;';
  EMOJIS.forEach(e => {
    const btn = document.createElement('button');
    btn.className = 'emoji-item';
    btn.textContent = e;
    btn.onclick = ev => {
      ev.stopPropagation();
      if (typeof targetOrCallback === 'function') {
        targetOrCallback(e);
      } else {
        const el = document.getElementById(targetOrCallback);
        if (el) {
          const pos = el.selectionStart || el.value.length;
          el.value = el.value.slice(0,pos) + e + el.value.slice(pos);
          el.selectionStart = el.selectionEnd = pos + e.length;
          el.focus();
        }
      }
      wrap.classList.remove('open');
    };
    wrap.appendChild(btn);
  });
  return wrap;
}

const TagSearch = {
  parse(query) {
    const tokens = [];
    query.trim().split(/\s+/).forEach(w => {
      if (w.startsWith('#') && w.length > 1) tokens.push({type:'#', value:w.slice(1).toLowerCase()});
      else if (w.startsWith('@') && w.length > 1) tokens.push({type:'@', value:w.slice(1).toLowerCase()});
    });
    return tokens;
  },
  filter(tasks, clients, products, query) {
    const tokens = this.parse(query);
    if (!tokens.length) {
      const q = query.toLowerCase();
      if (!q) return tasks;
      return tasks.filter(t =>
        t.action?.toLowerCase().includes(q) ||
        t.project?.toLowerCase().includes(q) ||
        t.id?.toLowerCase().includes(q) ||
        (t.clients?.name||'').toLowerCase().includes(q) ||
        (t.products?.name||'').toLowerCase().includes(q)
      );
    }
    return tasks.filter(t => tokens.every(tok => {
      if (tok.type === '#') return t.project?.toLowerCase().includes(tok.value) || t.action?.toLowerCase().includes(tok.value);
      if (tok.type === '@') {
        const respLabel = (RESP_CFG[t.resp]?.label || t.resp || '').toLowerCase();
        return respLabel.includes(tok.value) ||
          t.resp?.toLowerCase().includes(tok.value) ||
          (t.clients?.name||'').toLowerCase().replace(/\s+/g,'').includes(tok.value) ||
          (t.clients?.name||'').toLowerCase().includes(tok.value) ||
          (tok.value === 'atrasada' && t.week === 'ATRASADA') ||
          t.status?.toLowerCase().includes(tok.value);
      }
      return true;
    }));
  },
  getSuggestions(partial, clients, products, tasks) {
    const results = [];
    const lastWord = partial.trim().split(/\s+/).pop();
    if (!lastWord) return results;
    if (lastWord.startsWith('#')) {
      const term = lastWord.slice(1).toLowerCase();
      // Tags reais: projetos e produtos já cadastrados, não uma lista fixa
      // que nunca cresce. O catálogo de assuntos é o que a agência usa.
      const vistos = new Set();
      (tasks||[]).forEach(t => { if (t.project && t.project !== 'GERAL') vistos.add(t.project); });
      [...vistos].filter(v=>v.toLowerCase().startsWith(term)).slice(0,6)
        .forEach(v => results.push({type:'#',value:v.toLowerCase(),label:'#'+v}));
      products.filter(p=>p.name.toLowerCase().startsWith(term)).slice(0,3).forEach(p => results.push({type:'#',value:p.name.toLowerCase(),label:'#'+p.name}));
    } else if (lastWord.startsWith('@')) {
      const term = lastWord.slice(1).toLowerCase();
      Object.entries(RESP_CFG).filter(([k,v]) => v.label.toLowerCase().startsWith(term)).slice(0,6).forEach(([k,v]) => results.push({type:'@',value:v.label.toLowerCase(),label:'@'+v.label}));
      clients.filter(c=>c.name.toLowerCase().replace(/\s+/g,'').startsWith(term)||c.name.toLowerCase().startsWith(term)).slice(0,4).forEach(c => results.push({type:'@',value:c.name.toLowerCase().replace(/\s+/g,''),label:'@'+c.name}));
    }
    return results;
  }
};

const STATUS_CFG = {
  'BRIEFING':     { color:'#6366f1', label:'Briefing' },
  'EM ANDAMENTO': { color:'#2563eb', label:'Em Andamento' },
  'DELEGADA':     { color:'#7c3aed', label:'Com o cliente' },
  'ATENÇÃO':      { color:'#d97706', label:'Aprovado' },
  'BUCHA':        { color:'#dc2626', label:'Bucha' },
  'ALTERAÇÃO':    { color:'#db2777', label:'Alteração' },
  'ENTREGUE':     { color:'#16a34a', label:'Entregue' },
};

// ================================================================
// RESP_CFG — FIXO, baseado nos valores reais do banco
// Chaves: SERGIO, JOY, LUANA, JUAN, CLIENTE, OUTROS
// buildRespCfg apenas enriquece com avatar/nome real do perfil
// ================================================================
const RESP_CFG = {
  'SERGIO':  { label:'Sérgio Duh', color:'#0a0a0a', avatar: null },
  'JOY':     { label:'Joy',        color:'#2563eb', avatar: null },
  'LUANA':   { label:'Luana',      color:'#7c3aed', avatar: null },
  'JUAN':    { label:'Juan',       color:'#d97706', avatar: null },
  'CLIENTE': { label:'Cliente',    color:'#64748b', avatar: null },
  'OUTROS':  { label:'Outros / IA',color:'#64748b', avatar: null },
};

// Enriquecer com dados reais do banco (avatar, nome exato)
// Usa team_key para mapear para a chave correta do RESP_CFG
function buildRespCfg(teamMembers) {
  teamMembers.forEach(m => {
    // Tira acento antes de comparar — "Sérgio" precisa virar "SERGIO" pra bater
    // com a chave do RESP_CFG (sem isso o dono da conta nunca tinha avatar).
    const stripAccents = s => s.normalize('NFD').replace(/[\u0300-\u036f]/g,'');
    const key = m.team_key || stripAccents(m.name.split(' ')[0]).toUpperCase();
    if (RESP_CFG[key]) {
      // Só atualiza avatar e nome — não cria novas chaves
      RESP_CFG[key].avatar = m.avatar_url || null;
      RESP_CFG[key].label  = m.name.split(' ')[0]; // primeiro nome real
      RESP_CFG[key].id     = m.id;
    }
  });
  return RESP_CFG;
}

// BRIEFING primeiro — fluxo padrão de trabalho
const KANBAN_COLS = ['BRIEFING','EM ANDAMENTO','DELEGADA','ATENÇÃO','BUCHA','ALTERAÇÃO','ENTREGUE'];

window.Theme = Theme;
window.applyClientColor = applyClientColor;
window.resetClientColor = resetClientColor;
window.showToast = showToast;
window.openModal = openModal;
window.closeModal = closeModal;

// ================================================================
// PULL-TO-REFRESH
//
// O overscroll-behavior:none do CSS desligou o "puxar pra atualizar"
// nativo do navegador (que também causava o efeito de ver o fundo
// cinza atrás do app). Este substitui por um dentro do próprio app:
// puxa no topo da tela, solta, e a função passada roda de novo.
// ================================================================
function initPullToRefresh(onRefresh){
  let startY = 0, pulling = false, triggered = false;
  const THRESHOLD = 70;

  let indicator = document.getElementById('ptr-indicator');
  if (!indicator) {
    indicator = document.createElement('div');
    indicator.id = 'ptr-indicator';
    indicator.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
      stroke-width="2" stroke-linecap="round"><path d="M21 12a9 9 0 1 1-3-6.7"/>
      <path d="M21 3v6h-6"/></svg>`;
    document.body.prepend(indicator);
  }

  document.addEventListener('touchstart', (e) => {
    // Só conta se a tela já estiver no topo — senão é scroll normal.
    if (window.scrollY > 0 && document.scrollingElement.scrollTop > 0) return;
    startY = e.touches[0].clientY;
    pulling = true; triggered = false;
  }, { passive: true });

  document.addEventListener('touchmove', (e) => {
    if (!pulling) return;
    const dist = e.touches[0].clientY - startY;
    if (dist > 0 && dist < 200) {
      indicator.style.height = Math.min(dist * 0.6, 56) + 'px';
      if (dist > THRESHOLD && !triggered) {
        triggered = true;
        indicator.classList.add('spin');
      }
    }
  }, { passive: true });

  document.addEventListener('touchend', async () => {
    if (!pulling) return;
    pulling = false;
    if (triggered) {
      try { await onRefresh(); } catch(e){ console.log('refresh:', e.message); }
    }
    indicator.style.height = '0px';
    setTimeout(() => indicator.classList.remove('spin'), 300);
  });
}
window.initPullToRefresh = initPullToRefresh;
window.avatarHTML = avatarHTML;
window.linkify = linkify;
window.processCardImage = processCardImage;
window.createEmojiPicker = createEmojiPicker;
window.TagSearch = TagSearch;
window.Theme = Theme;
window.THEME_CATALOG = THEME_CATALOG;

// ================================================================
// PAINEL DE APARÊNCIA — vive dentro de "Meu Perfil", não mais solto
// no header. Chamado toda vez que o próprio perfil abre, ou que o
// tema muda enquanto o perfil está aberto.
// ================================================================
function renderThemePanel(){
  const grid = document.getElementById('prof-theme-grid');
  if (!grid) return;   // perfil não está aberto agora — nada a fazer

  const atual = document.documentElement.getAttribute('data-theme');
  const modoAuto = Theme.getModo() === 'auto';

  grid.innerHTML = THEME_CATALOG.map(t => `
    <div class="sd-theme-swatch ${t.id===atual && !modoAuto ? 'on':''}" onclick="Theme.set('${t.id}')">
      <div class="sd-theme-preview">
        ${t.cores.map(c => `<span style="background:${c}"></span>`).join('')}
      </div>
      <div class="sd-theme-label">${t.nome}</div>
    </div>`).join('');

  const chk = document.getElementById('prof-theme-auto-chk');
  if (chk) chk.checked = modoAuto;

  const lbl = document.getElementById('prof-theme-personal-label');
  if (lbl) {
    const temPersonal = !!localStorage.getItem('sd_theme_personal');
    lbl.textContent = temPersonal ? 'Editar meu tema' : 'Criar tema pessoal';
  }
}
window.renderThemePanel = renderThemePanel;
window.STATUS_CFG = STATUS_CFG;
window.RESP_CFG = RESP_CFG;
window.KANBAN_COLS = KANBAN_COLS;
window.buildRespCfg = buildRespCfg;
