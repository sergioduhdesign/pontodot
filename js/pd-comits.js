/* COMITS — grupos de conversa (pessoas + IAs + clientes). Fechar nunca apaga. */
(function(){
  const CM = { aba:'aberto', lista:null, pessoas:null, sel:null, dados:null, busca:'', achados:null, ultimoId:0, timer:null, admin:false, painel:false, acharEm:[], acharI:0 };
  window.CM = CM;
  const E = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c]));
  const GLOBO = '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.6 2.7 3.9 5.7 3.9 9s-1.3 6.3-3.9 9c-2.6-2.7-3.9-5.7-3.9-9S9.4 5.7 12 3z"/></svg>';
  const CADEADO = '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>';
  const cor = nome => { let h = 0; for (const c of String(nome || '')) h = (h * 31 + c.charCodeAt(0)) % 360; return `hsl(${h} 70% 62%)`; };
  const hora = iso => { const d = new Date(iso); return String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0'); };
  const dia = iso => { const d = new Date(iso); return String(d.getDate()).padStart(2, '0') + '/' + String(d.getMonth() + 1).padStart(2, '0'); };
  async function api(corpo){
    const { data:{ session } } = await sbR.auth.getSession();
    const r = await fetch('/pd-comits.php', { method:'POST', headers:{ 'Content-Type':'application/json', 'Authorization':'Bearer ' + session.access_token }, body: JSON.stringify(corpo) });
    const j = await r.json().catch(() => ({})); if (!r.ok || !j.ok) throw new Error(j.error || ('servidor respondeu ' + r.status)); return j;
  }
  const raiz = () => document.getElementById('cmRoot');
  const avatar = (m, t) => m.tipo === 'ia' ? `<img src="${E(typeof fotoDe === 'function' ? '' : '')}" alt="">` : '';
  const ini = n => String(n || '?').split(/\s+/).map(x => x[0]).join('').slice(0, 2).toUpperCase();
  const FMT = t => E(t).replace(/\*\*([^*\n][^*]*?)\*\*/g, '<b>$1</b>').replace(/(^|[\s(])\*([^*\n]+)\*(?=[\s).,!?:;]|$)/g, '$1<i>$2</i>');   // **negrito** e *itálico* viram formatação, sem asteriscos soltos
  const AVN = {};   // nome → foto das pessoas
  const bolinha = (nome, tipo, t) => { const foto = tipo !== 'ia' ? (AVN[String(nome).toLowerCase()] || (typeof perfil !== 'undefined' && perfil && perfil.name === nome ? perfil.avatar_url : '')) : ''; return `<span class="cm-av" style="--c:${cor(nome)};${t ? 'width:' + t + 'px;height:' + t + 'px;' : ''}" title="${E(nome)}">${tipo === 'ia' ? `<img src="/icone-ia.php?p=${encodeURIComponent(String(nome).toLowerCase())}&s=64" alt="" onerror="this.remove()">` : (foto ? `<img src="${E(foto)}" alt="" onerror="this.remove()">` : '')}<i>${E(ini(nome))}</i></span>`; };

  function css(){
    if (document.getElementById('cmCss')) return;
    const s = document.createElement('style'); s.id = 'cmCss'; s.textContent = `
    .cm{display:flex;flex-direction:column;height:100%;min-height:0;color:var(--branco)}
    .cm-cab{display:flex;align-items:center;gap:8px;padding:14px 16px 10px}.cm-cab h3{margin:0;flex:1;font-size:21px;font-weight:400;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .cm-ib{width:36px;height:36px;border-radius:50%;border:.5px solid rgba(127,127,127,.4);background:transparent;color:inherit;display:inline-grid;place-items:center;cursor:pointer;flex:0 0 auto;font:inherit;font-size:15px}
    .cm-ib:hover{background:rgba(127,127,127,.18)}.cm-ib.on{background:var(--branco);color:#111}
    .cm-tabs{display:flex;gap:6px;padding:0 16px 8px}.cm-tabs button{padding:6px 14px;border-radius:999px;border:.5px solid rgba(127,127,127,.4);background:none;color:inherit;font:inherit;font-size:12.5px;cursor:pointer}.cm-tabs button.on{background:var(--branco);color:#111}
    .cm-busca{display:flex;gap:6px;align-items:center;margin:0 16px 8px}.cm-busca input{flex:1;min-width:0;height:36px;border-radius:10px;border:.5px solid rgba(127,127,127,.4);background:rgba(127,127,127,.1);color:inherit;padding:0 12px;font:inherit;font-size:14px}
    .cm-corpo{flex:1;min-height:0;overflow-y:auto;padding:0 10px 10px;overscroll-behavior:contain;scrollbar-width:thin}
    .cm-it{display:flex;gap:11px;align-items:center;width:100%;text-align:left;padding:10px 8px;border-radius:12px;background:none;border:0;color:inherit;font:inherit;cursor:pointer}.cm-it:hover{background:rgba(127,127,127,.14)}
    .cm-it .t{min-width:0;flex:1}.cm-it b{display:block;font-weight:500;font-size:14.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.cm-it small{display:block;opacity:.6;font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .cm-av{position:relative;width:38px;height:38px;border-radius:50%;background:var(--c);color:#111;display:inline-grid;place-items:center;overflow:hidden;flex:0 0 auto;font-size:12px;font-weight:700}.cm-av img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}.cm-av i{font-style:normal}
    .cm-pilha{display:flex}.cm-pilha .cm-av{margin-left:-10px;border:2px solid #17191a}.cm-pilha .cm-av:first-child{margin-left:0}
    .cm-sec{font-size:10.5px;letter-spacing:.14em;text-transform:uppercase;opacity:.55;padding:12px 8px 4px}
    .cm-msgs{flex:1;min-height:0;overflow-y:auto;padding:6px 16px 8px;display:flex;flex-direction:column;gap:7px;overscroll-behavior:contain}
    .cm-m{display:flex;gap:8px;align-items:flex-end;max-width:88%}.cm-m.eu{align-self:flex-end;flex-direction:row-reverse}
    .cm-bl{padding:8px 12px;border-radius:14px;background:rgba(127,127,127,.2);font-size:14px;line-height:1.45;white-space:pre-wrap;word-break:break-word}.cm-m.eu .cm-bl{background:rgba(127,127,127,.34)}
    .cm-bl small{display:block;font-size:11px;opacity:.6;margin-bottom:2px}.cm-bl .h{opacity:.45;font-size:10.5px;margin-left:6px}
    .cm-m.imp .cm-bl{outline:1.5px solid #e0b342}.cm-sis{align-self:center;font-size:11.5px;opacity:.55;text-align:center;padding:2px 10px}
    .cm-in{display:flex;gap:6px;padding:10px 14px 14px;align-items:flex-end}.cm-in textarea{flex:1;min-height:40px;max-height:120px;border-radius:14px;border:.5px solid rgba(127,127,127,.4);background:rgba(127,127,127,.1);color:inherit;padding:10px 12px;font:inherit;font-size:14px;resize:none}
    .cm-btn:disabled,.cm-in button:disabled{opacity:.5}
    .cm-in button.env{height:40px;border-radius:999px;padding:0 18px;border:0;background:var(--branco);color:#111;font:inherit;font-weight:500;cursor:pointer;flex:none}
    .cm-in button.ic{width:38px;height:38px;border-radius:50%;border:0;background:rgba(255,255,255,.08);color:inherit;font-size:17px;line-height:1;padding:0;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;flex:none}.cm-in button.ic:hover{background:rgba(255,255,255,.16)}
    .cm-emo{position:absolute;bottom:62px;left:14px;display:grid;grid-template-columns:repeat(8,1fr);gap:2px;padding:8px;border-radius:14px;background:#24272a;border:.5px solid rgba(242,242,238,.2);box-shadow:0 10px 30px rgba(0,0,0,.45);z-index:5}.cm-emo button{border:0;background:none;font-size:20px;line-height:1;width:34px;height:34px;border-radius:8px;cursor:pointer}.cm-emo button:hover{background:rgba(255,255,255,.1)}
    .cm-meio{position:relative}
    .cm mark{background:var(--mc,#e0b342);color:#111;border-radius:3px;padding:0 1px}.cm mark.at{outline:2px solid #fff}
    .cm-ach{padding:6px 8px 10px 56px;font-size:13px;opacity:.9;cursor:pointer;border-radius:8px}.cm-ach:hover{background:rgba(127,127,127,.12)}.cm-ach small{opacity:.55;display:block}
    .cm-form{padding:4px 16px 14px;display:flex;flex-direction:column;gap:9px;overflow-y:auto}.cm-form input[type=text],.cm-form textarea{border-radius:10px;border:.5px solid rgba(127,127,127,.4);background:rgba(127,127,127,.1);color:inherit;padding:9px 12px;font:inherit;font-size:14px;width:100%;box-sizing:border-box}
    .cm-esc{display:flex;gap:10px;align-items:center;padding:6px 8px;border-radius:10px;cursor:pointer}.cm-esc:hover{background:rgba(127,127,127,.12)}.cm-esc input{width:18px;height:18px}
    .cm-btn{height:40px;border-radius:999px;padding:0 20px;border:0;background:var(--branco);color:#111;font:inherit;font-weight:500;cursor:pointer}.cm-btn.s{background:none;color:inherit;border:.5px solid rgba(127,127,127,.5)}
    .cm-log{font-size:12px;opacity:.75;padding:4px 8px}.cm-nav{display:flex;gap:4px;align-items:center;font-size:12px}
    html .caixa.invertida.cm.cm.cm{--branco:#F2F2EE;--branco2:rgba(242,242,238,.74);--branco3:rgba(242,242,238,.5);--linha:rgba(242,242,238,.18);background:#17191a!important;color:#F2F2EE!important;box-shadow:0 12px 36px rgba(0,0,0,.55);color-scheme:dark}
    .cm ::placeholder{color:rgba(242,242,238,.42)!important}
    .cm .cm-bl{background:rgba(255,255,255,.08);color:#F2F2EE}.cm .cm-m.eu .cm-bl{background:rgba(255,255,255,.17)}
    .cm .cm-bl small{opacity:.9}.cm .cm-bl .h{color:#F2F2EE}
    .cm .cm-ib{border-color:rgba(242,242,238,.28);color:#F2F2EE}.cm .cm-ib:hover{background:rgba(255,255,255,.12)}.cm .cm-ib.on{background:#F2F2EE;color:#111}
    .cm .cm-tabs button{border-color:rgba(242,242,238,.28);color:#F2F2EE}.cm .cm-tabs button.on{background:#F2F2EE;color:#111}
    .cm .cm-in button,.cm .cm-btn:not(.s){background:#F2F2EE!important;color:#111!important}
    .cm .cm-btn.s{color:#F2F2EE;border-color:rgba(242,242,238,.4)}
    .cm .cm-it:hover,.cm .cm-esc:hover{background:rgba(255,255,255,.08)}
    .cm textarea,.cm input[type=text],.cm .cm-busca input{background:rgba(255,255,255,.07)!important;color:#F2F2EE!important;border-color:rgba(242,242,238,.25)!important}
    .cm-pilha .cm-av{border-color:#17191a}
    .cm-tit{display:flex;align-items:center;gap:10px;min-width:0;flex:1;cursor:pointer;padding:2px 4px;border-radius:12px}.cm-tit:hover{background:rgba(255,255,255,.06)}
    .cm-tit .cm-pilha{flex:0 0 auto}.cm-tit .t{min-width:0;display:block}.cm-tit h3{font-size:18px;margin:0;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.cm-tit small{display:block;font-size:12px;opacity:.6;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .cm-gridp{display:grid;grid-template-columns:repeat(auto-fill,minmax(84px,1fr));gap:14px 8px;margin:6px 0 8px}.cm-gridp .gp{display:flex;flex-direction:column;align-items:center;text-align:center;gap:4px;background:none;border:0;color:inherit;font:inherit;cursor:default;padding:4px 0}.cm-gridp .gp b{font-weight:500;font-size:13px}.cm-gridp .gp small{font-size:11px;opacity:.55;margin-top:-3px}.cm-gridp .gp.add{cursor:pointer}
    .cm-lin{display:flex;align-items:center;gap:14px;width:100%;text-align:left;padding:14px 4px;border:0;border-top:.5px solid rgba(242,242,238,.1);background:none;color:inherit;font:inherit;font-size:14.5px;cursor:pointer}.cm-lin>svg,.cm-lin>:first-child{width:22px;text-align:center;flex:0 0 22px}.cm-lin span{flex:1}.cm-lin em{font-style:normal;font-size:12px;opacity:.5}.cm-lin:hover{background:rgba(255,255,255,.05)}
    .cm-grade{flex:1;min-height:0;display:grid;grid-template-columns:150px minmax(0,1fr) 216px}
    .cm-gente{overflow-y:auto;padding:6px 8px 10px 14px;border-right:.5px solid rgba(242,242,238,.1);scrollbar-width:thin}
    .cm-gente .p{display:flex;align-items:center;gap:8px;padding:5px 2px;font-size:12.5px;opacity:.92}.cm-gente .p small{display:block;font-size:10.5px;opacity:.55;line-height:1.15;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.cm-gente .p b{display:block;font-weight:600;line-height:1.2;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.cm-gente .p span.t{min-width:0;flex:1}
    .cm-meio{display:flex;flex-direction:column;min-height:0;min-width:0}
    .cm-curso{border-left:.5px solid rgba(242,242,238,.1);padding:10px 14px 14px;display:flex;flex-direction:column;gap:10px;overflow-y:auto;transition:opacity .3s;scrollbar-width:thin}.cm-curso.troca{opacity:0}
    .cm-ad-img{width:100%;aspect-ratio:4/3;object-fit:contain;border-radius:12px;background:rgba(255,255,255,.04);display:block}
    .cm-ad-tx b{display:block;font-weight:600;font-size:15px;line-height:1.25}.cm-ad-tx p{margin:6px 0 4px;font-size:13px;opacity:.85}.cm-ad-tx u{text-decoration:none;color:#ffc83d}.cm-ad-tx small{display:block;opacity:.55;font-size:11px;margin-bottom:10px}
    .cm-ad-tx button{width:100%;height:38px;border-radius:999px;border:0;background:#F2F2EE;color:#111;font:inherit;font-weight:600;font-size:13px;line-height:1;cursor:pointer;display:flex;align-items:center;justify-content:center;text-align:center;padding:0 12px;box-sizing:border-box}
    @media(max-width:1000px){.cm-grade{grid-template-columns:minmax(0,1fr)}.cm-curso{display:none}.cm-gente{display:none}}
    @media(max-width:700px){.cm-tit .cm-pilha .cm-av:nth-child(n+2){display:none}}
    @media(max-width:700px){.cm-m{max-width:94%}.cm-cab h3{font-size:19px}}`;
    document.head.appendChild(s);
  }

  /* ---------- abrir ---------- */
  window.abrirComits = async function(modo){
    css(); CM.painel = modo === 'painel'; CM.sel = null; CM.busca = ''; CM.achados = null;
    abrirJanela(`<div class="caixa invertida cm" id="cmRoot" data-sem-fio style="padding:0;height:100%"></div>`, true);
    try { const j = await api({ acao:'listar', status:CM.aba }); CM.lista = j.comits; CM.admin = j.admin; } catch(e){ CM.lista = []; toast(e.message); }
    lista();
  };
  const fechar = () => { clearInterval(CM.timer); CM.timer = null; fecharJanela(); };
  async function carregaLista(){ try { carregaPessoas().then(() => { try { if (typeof CMlistaCorpo === 'function' && document.querySelector('.cm-it')) CMlistaCorpo(); } catch(e){} }); } catch(e){} try { const j = await api({ acao:'listar', status:CM.aba }); CM.lista = j.comits; CM.admin = j.admin; } catch(e){ toast(e.message); } }

  function linhaComit(c){
    const ms = (c.membros || []).slice(0, 4);
    return `<button class="cm-it" onclick="CMver('${c.id}')"><span class="cm-pilha">${ms.map(m => bolinha(m.nome, m.tipo, 32)).join('')}</span>
      <span class="t"><b>${c.publico ? '' : ''}${E(c.titulo)}</b><small>${E(c.ultima || c.assunto || 'sem mensagens')}</small></span>
      <span style="opacity:.6" title="${c.publico ? 'Pública' : 'Particular'}">${c.publico ? GLOBO : CADEADO}</span></button>`;
  }
  function lista(){
    const R = raiz(); if (!R) return;
    clearInterval(CM.timer); CM.timer = null; CM.sel = null;
    const L = CM.lista || [];
    R.innerHTML = `<div class="cm-cab"><h3>${CM.painel ? 'Painel de comits' : 'Comits'}</h3><button class="cm-ib" title="Novo comit" onclick="CMnovo()">＋</button><button class="cm-ib" onclick="CMfechaJanela()" aria-label="Fechar">✕</button></div>
      <div class="cm-tabs"><button class="${CM.aba === 'aberto' ? 'on' : ''}" onclick="CMaba('aberto')">Ativos</button><button class="${CM.aba === 'fechado' ? 'on' : ''}" onclick="CMaba('fechado')">Fechados</button></div>
      <div class="cm-busca"><input id="cmQ" placeholder="buscar em todos os comits…" value="${E(CM.busca)}" onkeydown="if(event.key==='Enter')CMbuscar(this.value)" oninput="if(!this.value){CM.achados=null;CMlistaCorpo()}"></div>
      <div class="cm-corpo" id="cmCorpo"></div>`;
    CMlistaCorpo();
  }
  window.CMfechaJanela = fechar;
  window.CMlistaCorpo = function(){
    const B = document.getElementById('cmCorpo'); if (!B) return;
    if (CM.achados){
      B.innerHTML = CM.achados.length ? CM.achados.map(g => `<button class="cm-it" onclick="CMver('${g.id}','${E(CM.busca).replace(/'/g, '')}')"><span class="t"><b>${E(g.titulo)}</b><small>${g.achados.length} trecho(s)${g.status === 'fechado' ? ' · fechado' : ''}</small></span></button>`
        + g.achados.slice(0, 3).map(a => `<div class="cm-ach" onclick="CMver('${g.id}','${E(CM.busca).replace(/'/g, '')}')"><small>${E(a.de)} · ${dia(a.em)} ${hora(a.em)}</small>${realce(E(a.texto), CM.busca, cor(a.de))}</div>`).join('')).join('')
        : '<div class="cm-sec">nada encontrado</div>';
      return;
    }
    const L = CM.lista || [];
    B.innerHTML = L.length ? L.map(linhaComit).join('') : `<div class="cm-sec" style="text-transform:none;letter-spacing:0;font-size:13px;padding:18px 10px">${CM.aba === 'aberto' ? 'Nenhum comit ativo. Toque em ＋ para abrir um com pessoas, IAs e clientes.' : 'Nenhum comit fechado.'}</div>`;
  };
  window.CMaba = async function(a){ CM.aba = a; await carregaLista(); CM.achados = null; lista(); };
  window.CMbuscar = async function(q){ q = String(q || '').trim(); CM.busca = q; if (q.length < 2){ CM.achados = null; CMlistaCorpo(); return; }
    try { CM.achados = (await api({ acao:'buscar', q })).grupos; } catch(e){ toast(e.message); } CMlistaCorpo(); };
  function realce(html, termo, c){ if (!termo) return html; const t = E(termo).replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); return html.replace(new RegExp('(' + t + ')', 'gi'), `<mark style="--mc:${c}">$1</mark>`); }

  /* ---------- um comit ---------- */
  window.CMver = async function(id, termo){
    css(); if (!raiz()){ CM.painel = false; abrirJanela(`<div class="caixa invertida cm" id="cmRoot" data-sem-fio style="padding:0;height:100%"></div>`, true); }
    try { const [, j] = await Promise.all([carregaPessoas(), api({ acao:'ver', id })]); CM.dados = j; CM.sel = id; CM.ultimoId = j.msgs.length ? j.msgs[j.msgs.length - 1].id : 0; CM.admin = j.admin; }
    catch(e){ toast(e.message); return; }
    CM.termo = termo || ''; chat(); clearInterval(CM.timer); CM.timer = setInterval(() => { if (rapido > 0){ rapido--; puxa(); } else if (++CM.tick % 3 === 0) puxa(); }, 2000); CM.tick = 0;   // 2s logo após enviar (6x), depois a cada 6s
  };
  const meuId = () => (typeof perfil !== 'undefined' && perfil) ? perfil.id : null;
  function balao(m){
    if (m.autor_tipo === 'sistema') return `<div class="cm-sis">${E(m.texto)} · ${hora(m.created_at)}</div>`;
    const eu = m.autor_ref && m.autor_ref === meuId();
    return `<div class="cm-m ${eu ? 'eu' : ''} ${m.importante ? 'imp' : ''}" data-id="${m.id}">${eu ? '' : bolinha(m.autor_nome, m.autor_tipo === 'ia' ? 'ia' : 'h', 30)}
      <div class="cm-bl">${eu ? '' : `<small style="color:${cor(m.autor_nome)}">${E(m.autor_nome)}${m.autor_tipo === 'ia' ? ' · IA' : m.autor_tipo === 'cliente' ? ' · cliente' : ''}</small>`}<span class="tx">${FMT(m.texto)}</span><span class="h">${hora(m.created_at)}</span></div></div>`;
  }
  function chat(){
    const R = raiz(); if (!R) return; const d = CM.dados, c = d.comit, fechado = c.status === 'fechado';
    const dono = c.criado_por === meuId() || d.admin;
    CM.info = false; const nomes = d.membros.map(m => String(m.nome).split(' ')[0]).join(', ');
    R.innerHTML = `<div class="cm-cab"><button class="cm-ib" onclick="CMvolta()" aria-label="Voltar">‹</button>
        <div class="cm-tit" role="button" tabindex="0" onclick="CMinfo()" title="Dados do comit"><span class="cm-pilha">${d.membros.slice(0, 3).map(m => bolinha(m.nome, m.tipo, 32)).join('')}</span><span class="t"><h3>${E(c.titulo)}${fechado ? ' · fechado' : ''}</h3><small>${E(nomes)}</small></span></div>
        <button class="cm-ib" title="Buscar nesta conversa" aria-label="Buscar" onclick="CMbuscaAqui()">⌕</button>
        <button class="cm-ib" onclick="CMfechaJanela()" aria-label="Fechar janela">✕</button></div>
      <div class="cm-busca" id="cmBA" style="display:none"><input id="cmQa" placeholder="buscar nesta conversa…" oninput="CMachar(this.value)" onkeydown="if(event.key==='Enter'){event.preventDefault();CMpula(event.shiftKey?-1:1)}"><span class="cm-nav"><button class="cm-ib" style="width:30px;height:30px" onclick="CMpula(-1)">↑</button><button class="cm-ib" style="width:30px;height:30px" onclick="CMpula(1)">↓</button><span id="cmCt"></span></span></div>
      <div class="cm-grade">
        <aside class="cm-gente">${d.membros.map(m => `<div class="p" title="${E(m.nome)} · ${m.tipo}">${bolinha(m.nome, m.tipo, 26)}<span class="t"><b>${E(String(m.nome).split(' ')[0])}</b><small>${E(funcaoDe(m))}</small></span></div>`).join('')}
          ${fechado ? '' : '<button class="cm-ib" style="width:28px;height:28px;font-size:14px;margin-top:6px" title="Adicionar participantes" onclick="CMadd()">＋</button>'}</aside>
        <section class="cm-meio"><div class="cm-msgs" id="cmMsgs">${d.msgs.map(balao).join('')}</div>
          ${fechado ? '<div class="cm-sis" style="padding:12px">Comit fechado — só leitura.</div>' : `<div class="cm-in"><button type="button" class="ic" title="Emoji" aria-label="Emoji" onmousedown="event.preventDefault()" onclick="CMemoji()">☺</button><textarea id="cmTx" rows="1" placeholder="escreva… (chame uma IA pelo nome)" oninput="CMcresce(this)" onkeydown="CMtecla(event)"></textarea><button type="button" class="ic" title="Pular linha" aria-label="Pular linha" onmousedown="event.preventDefault()" onclick="CMquebra()">↵</button><button type="button" class="env" onmousedown="event.preventDefault()" onclick="CMenviar()">Enviar</button></div>`}</section>
        <aside class="cm-curso" id="cmCurso"></aside>
      </div>`;
    const M = document.getElementById('cmMsgs'); if (M) M.scrollTop = M.scrollHeight; adMonta();
    if (CM.termo){ CMbuscaAqui(CM.termo); }
  }
  function funcaoDe(m){ const P = CM.pessoas || {}; const x = (m.tipo === 'ia' ? P.ias : P.humanos || []) ; const f = (x || []).find(y => y.ref === m.ref); return (f && f.funcao) || (m.tipo === 'ia' ? 'Assistente' : m.tipo === 'cliente' ? 'Cliente' : 'Equipe'); }
  window.CMvolta = async function(){ await carregaLista(); lista(); };
  async function puxa(){
    if (!CM.sel || !raiz()) { clearInterval(CM.timer); return; }
    if (puxando || document.hidden) return; puxando = true;
    try { const j = await api({ acao:'ver', id:CM.sel, depois:CM.ultimoId }); if (j.msgs.length){ const M = document.getElementById('cmMsgs'); if (!M) return; const fim = M.scrollHeight - M.scrollTop - M.clientHeight < 80;
        j.msgs.forEach(m => { CM.ultimoId = m.id; if (M.querySelector('[data-id="' + m.id + '"]')) return; CM.dados.msgs.push(m); M.insertAdjacentHTML('beforeend', balao(m)); }); if (j.msgs.some(m => m.autor_ref !== meuId() && m.autor_tipo !== 'sistema')) SOM.ping(); visto(CM.sel); if (fim) M.scrollTop = M.scrollHeight; } } catch(e){} finally { puxando = false; }
  }
  const TOQUE = () => { try { return matchMedia('(pointer:coarse)').matches; } catch(e){ return false; } };
  window.CMcresce = t => { t.style.height = 'auto'; t.style.height = Math.min(t.scrollHeight, 120) + 'px'; };
  window.CMtecla = e => { if (e.key !== 'Enter' || e.isComposing) return; if (e.shiftKey || e.altKey || e.ctrlKey && false) return; if (TOQUE()) return;   // celular: Enter pula linha, envia só o botão
    e.preventDefault(); CMenviar(); };
  window.CMquebra = () => { const t = document.getElementById('cmTx'); if (!t) return; const a = t.selectionStart, b = t.selectionEnd; t.value = t.value.slice(0, a) + '\n' + t.value.slice(b); t.selectionStart = t.selectionEnd = a + 1; CMcresce(t); t.focus(); };
  const EMO = ['😀','😂','😊','😍','🙏','👍','👏','🔥','💡','✅','❤️','🎉','🤔','😅','😬','🙌','👀','🚀','⚠️','📌','📎','⏰','💬','✨'];
  window.CMemoji = () => { const m = document.querySelector('.cm-meio'); if (!m) return; const ja = m.querySelector('.cm-emo'); if (ja){ ja.remove(); return; }
    const d = document.createElement('div'); d.className = 'cm-emo'; d.innerHTML = EMO.map(x => `<button type="button" onmousedown="event.preventDefault()" onclick="CMponEmoji('${x}')">${x}</button>`).join(''); m.appendChild(d); };
  window.CMponEmoji = x => { const t = document.getElementById('cmTx'); if (!t) return; const a = t.selectionStart, b = t.selectionEnd; t.value = t.value.slice(0, a) + x + t.value.slice(b); t.selectionStart = t.selectionEnd = a + x.length; t.focus(); CMcresce(t); };
  let puxando = false, rapido = 0;
  window.CMenviar = async function(){
    const t = document.getElementById('cmTx'); const txt = (t && t.value || '').trim(); if (!txt) return;
    t.value = ''; CMcresce(t); const em = document.querySelector('.cm-emo'); if (em) em.remove(); t.focus();   // a caixa NUNCA trava: limpa, mostra a mensagem na hora e envia por trás
    const M = document.getElementById('cmMsgs'); let tmp = null;
    if (M){ M.insertAdjacentHTML('beforeend', balao({ id:'tmp', autor_ref:meuId(), autor_tipo:'humano', autor_nome:'', texto:txt, created_at:new Date().toISOString() })); tmp = M.lastElementChild; M.scrollTop = M.scrollHeight; if (tmp) tmp.style.opacity = '.6'; }
    try { SOM.envia(); const j = await api({ acao:'enviar', id:CM.sel, texto:txt });
      if (j.msg){ if (tmp){ if (M.querySelector('[data-id="' + j.msg.id + '"]')) tmp.remove(); else { tmp.style.opacity = ''; tmp.dataset.id = j.msg.id; } } CM.dados.msgs.push(j.msg); CM.ultimoId = Math.max(CM.ultimoId || 0, j.msg.id); }
      rapido = 6; } catch(e){ if (tmp) tmp.remove(); toast(e.message); if (t){ t.value = txt; CMcresce(t); } }
  };
  window.CMbuscaDaInfo = async function(){ await CMver(CM.sel); CMbuscaAqui(); };
  window.CMinfo = function(){
    const R = raiz(); if (!R || !CM.dados) return; CM.info = true; const d = CM.dados, c = d.comit, fechado = c.status === 'fechado', dono = c.criado_por === meuId() || d.admin;
    const linha = (ic, txt, fn, extra) => `<button class="cm-lin" onclick="${fn}">${ic}<span>${txt}</span>${extra || ''}</button>`;
    R.innerHTML = `<div class="cm-cab"><button class="cm-ib" onclick="CMver('${c.id}')" aria-label="Voltar">‹</button><h3>Dados do comit</h3><button class="cm-ib" onclick="CMfechaJanela()" aria-label="Fechar janela">✕</button></div>
      <div class="cm-corpo" style="padding:0 16px 16px">
        <div style="font-size:19px;margin:4px 0 2px">${E(c.titulo)}</div>${c.assunto ? `<div style="opacity:.65;font-size:13.5px;margin-bottom:6px">${E(c.assunto)}</div>` : ''}
        <div class="cm-sec" style="padding-left:0">${d.membros.length} participantes</div>
        <div class="cm-gridp">${d.membros.map(m => `<div class="gp" title="${E(m.nome)}">${bolinha(m.nome, m.tipo, 52)}<b>${E(String(m.nome).split(' ')[0])}</b><small>${m.tipo === 'ia' ? 'IA' : m.tipo === 'cliente' ? 'cliente' : 'equipe'}</small></div>`).join('')}
          ${fechado ? '' : `<button class="gp add" onclick="CMadd()"><span class="cm-ib" style="width:52px;height:52px;font-size:22px">＋</span><b>Adicionar</b></button>`}</div>
        <div class="cm-sec" style="padding-left:0">Ações</div>
        ${linha('⌕', 'Buscar na conversa', 'CMbuscaDaInfo()')}
        ${dono ? linha(c.publico ? GLOBO : CADEADO, c.publico ? 'Pública — a equipe encontra e entra' : 'Particular — só os participantes', 'CMpublico()', '<em>tornar ' + (c.publico ? 'particular' : 'pública') + '</em>') : ''}
        ${d.admin ? linha('⟲', 'Histórico de ações', 'CMlog()') : ''}
        ${d.admin ? (fechado ? linha('↺', 'Reabrir o comit', "CMfecharAbrir('reabrir')") : linha('✓', 'Encerrar o comit (o histórico fica)', "CMfecharAbrir('fechar')")) : ''}
      </div>`;
  };
  window.CMpublico = async function(){ try { const j = await api({ acao:'publico', id:CM.sel }); CM.dados.comit.publico = j.publico; CM.info ? CMinfo() : chat(); toast(j.publico ? 'Pública: a equipe encontra este comit.' : 'Particular: só os participantes.'); } catch(e){ toast(e.message); } };
  window.CMfecharAbrir = async function(op){
    if (op === 'fechar' && !(await perguntar('Fechar este comit?\nNada é apagado: o histórico fica guardado e dá para reabrir.', { ok:'Fechar comit' }))) return;
    try { await api({ acao:op, id:CM.sel }); toast(op === 'fechar' ? 'Comit fechado. O histórico fica guardado.' : 'Comit reaberto.'); CMver(CM.sel); } catch(e){ toast(e.message); } };
  window.CMlog = function(){
    const L = CM.dados.log || []; const R = raiz(); const old = R.innerHTML;
    R.innerHTML = `<div class="cm-cab"><button class="cm-ib" onclick="CMver('${CM.sel}')">‹</button><h3>Histórico de ações</h3><button class="cm-ib" onclick="CMfechaJanela()">✕</button></div>
      <div class="cm-corpo">${L.length ? L.map(l => `<div class="cm-it" style="cursor:default">${bolinha(l.quem || '?', 'h', 32)}<span class="t"><b>${E(l.acao)}</b><small>${E(l.quem || '')} · ${dia(l.em)} ${hora(l.em)}${l.detalhe ? ' · ' + E(l.detalhe) : ''}</small></span></div>`).join('') : '<div class="cm-sec">sem registros</div>'}</div>`;
  };
  /* busca dentro da conversa: realce na cor de quem falou, Enter/setas pulam */
  window.CMbuscaAqui = function(termo){
    const B = document.getElementById('cmBA'); if (!B) return; B.style.display = 'flex'; const i = document.getElementById('cmQa'); if (termo) i.value = termo; i.focus(); CMachar(i.value);
  };
  window.CMachar = function(q){
    document.querySelectorAll('#cmMsgs mark').forEach(m => { const p = m.parentNode; p.replaceChild(document.createTextNode(m.textContent), m); p.normalize(); });
    CM.acharEm = []; CM.acharI = 0; const ct = document.getElementById('cmCt'); q = String(q || '').trim(); if (q.length < 1){ if (ct) ct.textContent = ''; return; }
    const re = new RegExp(q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'gi');
    document.querySelectorAll('#cmMsgs .cm-m').forEach(el => { const tx = el.querySelector('.tx'); if (!tx || !re.test(tx.textContent)) return; re.lastIndex = 0;
      const c = cor(el.querySelector('.cm-bl small') ? el.querySelector('.cm-bl small').textContent.split(' · ')[0] : (typeof perfil !== 'undefined' && perfil ? perfil.name : ''));
      tx.innerHTML = E(tx.textContent).replace(new RegExp('(' + q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'gi'), `<mark style="--mc:${c}">$1</mark>`); tx.querySelectorAll('mark').forEach(m => CM.acharEm.push(m)); });
    CM.acharI = CM.acharEm.length ? CM.acharEm.length - 1 : 0; CMmarca();
  };
  function CMmarca(){ const ct = document.getElementById('cmCt'); CM.acharEm.forEach((m, i) => m.classList.toggle('at', i === CM.acharI)); const m = CM.acharEm[CM.acharI];
    if (m) m.scrollIntoView({ block:'center', behavior:'smooth' }); if (ct) ct.textContent = CM.acharEm.length ? (CM.acharI + 1) + '/' + CM.acharEm.length : '0'; }
  window.CMpula = function(d){ if (!CM.acharEm.length) return; CM.acharI = (CM.acharI + d + CM.acharEm.length) % CM.acharEm.length; CMmarca(); };

  /* ---------- novo comit / adicionar ---------- */
  async function carregaPessoas(){ if (!CM.pessoas){ try { CM.pessoas = await api({ acao:'pessoas' }); (CM.pessoas.humanos || []).forEach(x => { if (x.avatar) AVN[String(x.nome).toLowerCase()] = x.avatar; }); } catch(e){ toast(e.message); CM.pessoas = { humanos:[], ias:[] }; } } return CM.pessoas; }
  function seletor(p, jaTem){
    const g = (tit, L) => L.length ? `<div class="cm-sec">${tit}</div>` + L.map(x => `<label class="cm-esc" data-n="${E((x.nome + ' ' + x.sub).toLowerCase())}"><input type="checkbox" value="${x.tipo}|${E(x.ref)}">${bolinha(x.nome, x.tipo, 30)}<span class="t" style="flex:1"><b style="font-weight:500;font-size:14px">${E(x.nome)}</b><small style="display:block;opacity:.6;font-size:11.5px">${E(x.sub)}</small></span></label>`).join('') : '';
    const eu = meuId(); const tem = new Set(jaTem || []);
    return g('Equipe', p.humanos.filter(x => x.tipo === 'humano' && x.ref !== eu && !tem.has(x.ref))) + g('IAs da equipe e das ferramentas', p.ias.filter(x => !tem.has(x.ref))) + g('Clientes', p.humanos.filter(x => x.tipo === 'cliente' && x.ref !== eu && !tem.has(x.ref)));
  }
  window.CMfiltra = q => { q = String(q || '').toLowerCase(); document.querySelectorAll('#cmSel .cm-esc').forEach(l => { l.style.display = !q || l.dataset.n.includes(q) ? '' : 'none'; }); };
  const marcados = () => [...document.querySelectorAll('#cmSel input:checked')].map(i => { const [tipo, ref] = i.value.split('|'); return { tipo, ref }; });
  window.CMnovo = async function(pre){
    const R = raiz(); if (!R) return; const p = await carregaPessoas();
    R.innerHTML = `<div class="cm-cab"><button class="cm-ib" onclick="CMvolta()">‹</button><h3>Novo comit</h3><button class="cm-ib" onclick="CMfechaJanela()">✕</button></div>
      <div class="cm-form"><input type="text" id="cmT" placeholder="nome do grupo (ex.: Lançamento Ápice — campanha)"><textarea id="cmA" rows="2" placeholder="assunto (opcional) — as IAs leem isso para saber do que se trata"></textarea>
        <label class="cm-esc"><input type="checkbox" id="cmP"><span>${GLOBO}</span><span style="flex:1">Pública — a equipe encontra e entra</span></label>
        <input type="text" id="cmF" placeholder="buscar pessoa, IA ou cliente…" oninput="CMfiltra(this.value)"></div>
      <div class="cm-corpo" id="cmSel">${seletor(p)}</div>
      <div style="padding:10px 16px 16px;display:flex;gap:8px"><button class="cm-btn" style="flex:1" onclick="CMcria()">Abrir comit</button></div>`;
    if (pre) pre.forEach(v => { const i = document.querySelector(`#cmSel input[value="${v}"]`); if (i) i.checked = true; });
  };
  window.CMcria = async function(){
    const t = (document.getElementById('cmT').value || '').trim(); if (!t){ toast('Dê um nome ao comit.'); return; }
    try { const j = await api({ acao:'criar', titulo:t, assunto:document.getElementById('cmA').value, publico:document.getElementById('cmP').checked, membros:marcados() }); await carregaLista(); CMver(j.comit.id); }
    catch(e){ toast(e.message); }
  };
  window.CMadd = async function(){
    const p = await carregaPessoas(); const ja = (CM.dados.membros || []).map(m => m.ref);
    const R = raiz();
    R.innerHTML = `<div class="cm-cab"><button class="cm-ib" onclick="CMver('${CM.sel}')">‹</button><h3>Adicionar ao comit</h3><button class="cm-ib" onclick="CMfechaJanela()">✕</button></div>
      <div class="cm-form"><input type="text" placeholder="buscar…" oninput="CMfiltra(this.value)"></div><div class="cm-corpo" id="cmSel">${seletor(p, ja)}</div>
      <div style="padding:10px 16px 16px"><button class="cm-btn" style="width:100%" onclick="CMaddOk()">Adicionar</button></div>`;
  };
  window.CMaddOk = async function(){ const m = marcados(); if (!m.length){ CMver(CM.sel); return; } try { await api({ acao:'add', id:CM.sel, membros:m }); } catch(e){ toast(e.message); } CMver(CM.sel); };

  /* ---------- nova conversa com qualquer um (pessoa ou IA) ---------- */
  window.abrirNovaConversa = async function(){
    css(); const p = await carregaPessoas();
    abrirJanela(`<div class="caixa invertida cm" data-sem-fio style="padding:0;height:100%"><div class="cm-cab"><h3>Nova conversa</h3><button class="cm-ib" onclick="fecharJanela()">✕</button></div>
      <div class="cm-form"><input type="text" placeholder="com quem? pessoa, IA ou cliente…" oninput="CMfiltra(this.value)"></div>
      <div class="cm-corpo" id="cmSel"><button class="cm-it" onclick="fecharJanela();abrirComits();setTimeout(CMnovo,350)"><span class="cm-av" style="--c:#8bd">＋</span><span class="t"><b>Novo comit (grupo)</b><small>pessoas + IAs + clientes na mesma conversa</small></span></button>
      ${p.humanos.filter(x => x.ref !== meuId()).map(x => `<button class="cm-it cm-esc" data-n="${E((x.nome + ' ' + x.sub).toLowerCase())}" onclick="CMconversaCom('${x.tipo}','${E(x.ref)}','${E(x.nome).replace(/'/g, '’')}')">${bolinha(x.nome, x.tipo, 36)}<span class="t"><b>${E(x.nome)}</b><small>${E(x.sub)}</small></span></button>`).join('')}
      ${p.ias.map(x => `<button class="cm-it cm-esc" data-n="${E((x.nome + ' ' + x.sub).toLowerCase())}" onclick="CMconversaCom('ia','${E(x.ref)}','${E(x.nome)}')">${bolinha(x.nome, 'ia', 36)}<span class="t"><b>${E(x.nome)}</b><small>${E(x.sub)}</small></span></button>`).join('')}</div></div>`, true);
  };
  window.CMconversaCom = async function(tipo, ref, nome){
    if (tipo !== 'ia'){ fecharJanela(); try { abrirChatCom(nome); } catch(e){} return; }   // pessoa: o chat direto de sempre
    try { await carregaLista(); const j = await api({ acao:'listar', status:'aberto' }); const ex = (j.comits || []).find(c => (c.titulo === nome) && (c.membros || []).length <= 2 && (c.membros || []).some(m => m.tipo === 'ia' && m.ref === ref) && c.sou_membro);
      fecharJanela(); await abrirComits(); if (ex) return CMver(ex.id);
      const n = await api({ acao:'criar', titulo:nome, assunto:'Conversa direta com ' + nome, membros:[{ tipo:'ia', ref }] }); CMver(n.comit.id); } catch(e){ toast(e.message); }
  };
  /* limpar a conversa com uma pessoa (só para mim: some daqui, a outra pessoa continua com o histórico) */
  window.limparDM = async function(quem){
    if (!(await perguntar('Limpar a conversa com ' + String(quem).split(' ')[0] + '?\nSó some para você; a outra pessoa continua com o histórico.', { ok:'Limpar' }))) return;
    try { localStorage.setItem('sd_dm_limpo_' + quem, String(Date.now())); } catch(e){} try { THREADS[quem] = []; render(); } catch(e){} toast('Conversa limpa.');
  };

  /* ---------- som (sintetizado) ---------- */
  const SOM = (() => { let ac = null; const ok = () => { try { return localStorage.getItem('pd_comit_som') !== 'off'; } catch(e){ return true; } };
    const ctx = () => { if (!ok()) return null; try { ac = ac || new (window.AudioContext || window.webkitAudioContext)(); if (ac.state === 'suspended') ac.resume(); return ac; } catch(e){ return null; } };
    const nota = (f, t0, d, vol, tipo) => { const a = ctx(); if (!a) return; const t = a.currentTime + t0, o = a.createOscillator(), g = a.createGain(); o.type = tipo || 'sine'; o.frequency.setValueAtTime(f, t); g.gain.setValueAtTime(.0001, t); g.gain.exponentialRampToValueAtTime(vol, t + .012); g.gain.exponentialRampToValueAtTime(.0001, t + d); o.connect(g).connect(a.destination); o.start(t); o.stop(t + d + .03); };
    const destrava = () => { ctx(); try { if (ac && ac.state === 'running'){ removeEventListener('pointerdown', destrava, true); removeEventListener('keydown', destrava, true); } } catch(e){} };
    addEventListener('pointerdown', destrava, true); addEventListener('keydown', destrava, true);   // o navegador só libera som depois de um toque: libera no primeiro
    return { ping(){ nota(880, 0, .22, .16); nota(1318, .11, .3, .13); }, envia(){ nota(520, 0, .09, .08, 'triangle'); nota(780, .05, .1, .06, 'triangle'); } }; })();
  const visto = id => { try { localStorage.setItem('cm_visto_' + id, String(Date.now())); } catch(e){} try { window.clBadgeAtualiza && clBadgeAtualiza(); } catch(e){} };
  const jaVisto = c => { try { return +(localStorage.getItem('cm_visto_' + c.id) || 0) >= new Date(c.ultima_em).getTime() - 1500; } catch(e){ return true; } };

  /* ---------- comits como grupos na barra de Conversas ---------- */
  window.CMnaoLidos = () => (CM.side || []).filter(c => (c.sou_membro || c.publico) && !jaVisto(c) && !(CM.sel === c.id && raiz())).length;
  CM.side = []; let sideSig = '', sidePrimeira = true;
  window.CMsideHtml = function(fechaSe){
    const L = (CM.side || []).filter(c => c.sou_membro || c.publico).slice(0, 12); if (!L.length) return '';
    return `<div class="hist-sec">COMITS · GRUPOS</div>` + L.map(c => { const ms = (c.membros || []).slice(0, 3), nv = !jaVisto(c);
      return `<div class="hist-item" role="button" onclick="${fechaSe || ''}CMver('${c.id}')"><span class="hq" style="width:auto;min-width:36px;display:flex">${ms.map((m, i) => bolinha(m.nome, m.tipo, 26).replace('class="cm-av"', 'class="cm-av" data-i="' + i + '"')).join('')}</span><span class="ht"><span class="h1">${E(c.titulo)} ${nv ? '<b style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#dc2626;vertical-align:1px"></b>' : ''}</span><span class="h2">${E(c.ultima || c.assunto || 'sem mensagens')}</span></span></div>`; }).join(''); };
  window.CMsideRefresh = async function(){
    try { await carregaPessoas(); const j = await api({ acao:'listar', status:'aberto' }); CM.side = j.comits || [];
      const novas = CM.side.filter(c => (c.sou_membro || c.publico) && !jaVisto(c) && !(CM.sel === c.id && raiz()));
      const sig = JSON.stringify(CM.side.map(c => [c.id, c.ultima_em, c.titulo, jaVisto(c)]));
      if (!sidePrimeira && novas.length && sig !== sideSig) { SOM.ping(); try { toast('Comit: ' + novas[0].titulo + ' — ' + (novas[0].ultima || 'novidade')); } catch(e){} }
      if (sig !== sideSig){ sideSig = sig; try { if (typeof pintarHist === 'function' && typeof histAberto !== 'undefined' && histAberto) pintarHist(); } catch(e){} }
      sidePrimeira = false; try { window.clBadgeAtualiza && clBadgeAtualiza(); } catch(e){}
    } catch(e){}
  };

  /* ---------- propaganda de cursos: coluna à direita DENTRO da caixa do comit (capa do próprio curso, sem cortar) ---------- */
  const AD = { cursos: null, t: 0 };
  const PERS_DO = sk => ({ LP_ESTRUTURA:'nami', LP_REVISAO_ESTRUTURA:'kiro' }[sk] || (/^CURSO_/.test(sk) ? sk.slice(6).toLowerCase() : null));
  const FRASES = ['Tá com dificuldade de organizar uma ideia?', 'Seu comit não anda? Sua IA pode aprender isso.', 'Conversa boa vira decisão.', 'Quer que sua IA discorde e feche o assunto?'];
  async function adCarrega(){
    if (AD.cursos) return;
    try { const { data:{ session } } = await sbR.auth.getSession();
      const r = await fetch('/pd-escola.php', { method:'POST', headers:{ 'Content-Type':'application/json', 'Authorization':'Bearer ' + session.access_token }, body: JSON.stringify({ acao:'cursos_catalogo' }) });
      const j = await r.json(); AD.cursos = (j.cursos || []).filter(c => c.skill_id && c.nome && PERS_DO(c.skill_id)); } catch(e){ AD.cursos = []; }
  }
  function adCard(){
    const L = AD.cursos || []; if (!L.length) return '';
    const livres = L.filter(c => !c.inscrita), P = livres.length ? livres : L; const c = P[Math.floor(Math.random() * P.length)];
    const est = (c.alunos || []).length;
    return `<img class="cm-ad-img" src="/pd/personagens/${E(PERS_DO(c.skill_id))}/nascimento-p.jpg" alt="" onerror="this.remove()">
      <div class="cm-ad-tx"><b>${E(FRASES[Math.floor(Math.random() * FRASES.length)])}</b>
      <p>Põe sua IA num curso de <u>${E(c.nome)}</u>.</p><small>${est ? est + ' IA' + (est > 1 ? 's' : '') + ' estudando agora' : 'vagas abertas'}</small>
      <button type="button" onclick="CMabreCurso('${E(c.skill_id)}')">Ver o curso →</button></div>`;
  }
  window.CMabreCurso = skill => {
    document.getElementById('cm-curso-j')?.remove();
    const d = document.createElement('div'); d.id = 'cm-curso-j'; d.style.cssText = 'position:fixed;inset:0;z-index:99900;display:grid;place-items:center;background:rgba(10,10,12,.55);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);padding:16px';
    d.innerHTML = `<div style="position:relative;width:min(1100px,100%);height:min(88vh,820px);border-radius:22px;overflow:hidden;background:#fff;box-shadow:0 30px 90px rgba(0,0,0,.5)"><iframe title="Cursos" src="/escola-bastidores.html?modo=cursos&abrir=${encodeURIComponent(skill)}&v=${Date.now()}" style="width:100%;height:100%;border:0;display:block"></iframe>
      <button aria-label="Fechar" onclick="document.getElementById('cm-curso-j').remove()" style="position:absolute;top:14px;right:16px;width:36px;height:36px;border-radius:50%;border:0;background:rgba(0,0,0,.55);color:#fff;font-size:15px;line-height:1;cursor:pointer;display:flex;align-items:center;justify-content:center;padding:0">✕</button></div>`;
    d.addEventListener('click', e => { if (e.target === d) d.remove(); }); document.body.appendChild(d);
  };
  function adMonta(){
    clearInterval(AD.t); const col = document.getElementById('cmCurso'); if (!col) return;
    adCarrega().then(() => { const el = document.getElementById('cmCurso'); if (!el) return; const h = adCard(); if (!h){ el.style.display = 'none'; return; } el.innerHTML = h;
      AD.t = setInterval(() => { const e2 = document.getElementById('cmCurso'); if (!e2){ clearInterval(AD.t); return; } const n = adCard(); if (!n) return; e2.classList.add('troca'); setTimeout(() => { e2.innerHTML = n; e2.classList.remove('troca'); }, 300); }, 60000); });
  }
  setTimeout(() => { if (window.REAL !== false) CMsideRefresh(); }, 4000); setInterval(() => { if (!document.hidden) CMsideRefresh(); }, 15000);
})();
