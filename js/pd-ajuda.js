// ============================================================================
// PD · AJUDA — o "?" elegante e o tour "Entenda a plataforma".
// Ideia da casa: a plataforma não carrega textão. Cada ferramenta tem um ?
// do lado; o clique abre uma caixinha que explica. E o primeiro usuário ganha
// a jornada completa, passo a passo, no botão "Entenda a plataforma".
// ============================================================================
window.PD_AJUDA = {
  plataforma: { t: 'Bem-vindo à PD', p: 'Aqui você e as IAs da casa trabalham juntos. Tudo que entra vira tarefa, a produção acontece na área de Trabalho, passa pela aprovação e sai publicado. Este tour mostra o caminho inteiro — e, em cada ferramenta, o ? do ladinho explica o resto.' },
  tarefas: { t: 'Tarefas', p: 'Tudo começa aqui: cada pedido vira uma tarefa com responsável e prazo. Dá pra criar pela plataforma ou mandando mensagem no zap da PD. O play liga o cronômetro; quem é marcado recebe aviso na hora, aqui e no zap.' },
  trabalho: { t: 'Trabalho', p: 'A área de produção da casa. Por enquanto a ferramenta aberta pra equipe é a de Copys — as outras (arte, locução, vídeo, gráfica) aparecem apagadinhas até serem liberadas pra você, pessoa por pessoa, pelo admin.' },
  copys: { t: 'Copys', p: 'Preencha o briefing, cole os dados estratégicos, escolha um estilo e peça. A IA escreve as variantes; você lapida direto nos cards. O que estiver bom segue pra revisão interna e, depois, pro cliente — sem redigitar nada.' },
  aprovacoes: { t: 'Aprovação', p: 'O trabalho pronto entra num lote de aprovação. Primeiro a revisão interna — o "tá bom pra mostrar" da equipe —, depois o cliente. Correção volta pra IA com o apontamento; aprovação segue pro próximo passo sozinha.' },
  social: { t: 'Social', p: 'O conteúdo aprovado entra na fila de publicação e agenda nas redes do cliente: feed, carrossel, stories e reels. Os comentários chegam aqui também, pra responder sem sair da plataforma.' },
  publicacao: { t: 'Publicação', p: 'A fila do que vai ao ar: o que está agendado, pra quando, em qual rede. Deu problema em alguma publicação, é aqui que aparece.' },
  anuncios: { t: 'Anúncios', p: 'As campanhas de mídia dos clientes: investimento, resultado e alertas. Os números vêm direto das contas conectadas.' },
  minhaia: { t: 'Minha IA', p: 'A sua dupla de trabalho. Ela aprende o seu jeito, lembra do que vocês combinaram e resolve pedidos no chat. Quanto mais você usa, melhor ela fica.' },
  cronograma: { t: 'Cronograma', p: 'A linha do tempo dos trabalhos: o que está pra hoje, o que vem depois e o que está atrasado de verdade (briefing não conta como atraso).' },
  zap: { t: 'Zap', p: 'A PD no seu bolso. Pelo WhatsApp dá pra criar tarefa, pedir lembrete (até pros colegas), receber avisos e perguntar qualquer coisa. É a mesma conversa da plataforma — o que vale aqui, vale lá.' },
  escola: { t: 'Escola das IAs', p: 'As IAs da casa estudam todos os dias: fazem exercícios, recebem correção e só assumem trabalho de verdade quando provam que aprenderam. Os cursos pra humanos abrem em breve — por enquanto, a turma é delas.' },
  oficina: { t: 'Saída de materiais', p: 'O balcão de entrega: tudo que a oficina terminou aparece aqui pra você abrir, conferir e usar.' },
  ias: { t: 'Inteligências da casa', p: 'O time de IAs da PD: cada personagem tem um ofício e um jeito. Elas trabalham nas ferramentas, aprendem na escola e aparecem aqui com o que estão fazendo.' },
  lembretes: { t: 'Lembretes', p: 'Peça aqui ou pelo zap: "me lembra amanhã às 9h", com ou sem repetição, pra você ou pra um colega. O aviso chega na plataforma e no zap.' },
  catum: { t: 'Hora de Catum', p: 'O termômetro de confiança: de tantos trabalhos, quantos a IA acertou de primeira — "de primeira" é quando a equipe topa usar sem corrigir. Correção por mudança de direção ou briefing vago não conta contra ela. Quando a leitura firma, você sabe que já dá pra confiar aquela tarefa àquela IA; o valor por trás é tempo humano devolvido.' },
  equipe: { t: 'Equipe e funções', p: 'Marque as funções de cada pessoa (designer, copy, atendimento…): o fluxo encaminha o trabalho por função, com rodízio de carga. O botão verde liga e desliga o acesso sem apagar nada. E as ferramentas de Trabalho são liberadas pessoa por pessoa — Copys é de todos.' },
  aprovacao_interna: { t: 'Revisão interna', p: 'Antes do cliente ver, a equipe confere: aprovou, libera; precisa mexer, o apontamento volta pra IA corrigir. É esse "tá bom pra mostrar" que conta pra Hora de Catum.' },
};
window.PD_AJUDA_TIT = { 'Trabalho':'trabalho', 'Escola das IAs':'escola', 'Saída de materiais':'oficina', 'Inteligências da casa':'ias', 'Publicação':'publicacao', 'Anúncios':'anuncios', 'Social':'social', 'Minha IA':'minhaia', 'Cronograma':'cronograma', 'Zap':'zap', 'Lembretes':'lembretes' };
window.PD_TOUR = ['plataforma','tarefas','trabalho','copys','aprovacoes','social','zap','escola'];
window.PD_AJUDA_ALVO = {
  tarefas: ['#cxTarefas', '.gad[data-fk="gad-tarefas"]'],
  trabalho: ['.gad[data-fk="gad-estudio"]', '#estBarra'],
  copys: ['.gad[data-fk="gad-estudio"] [title^="Copys"]', '.gad[data-fk="gad-estudio"]', '#ajudaCopys', '.top'],
  aprovacoes: ['.gad[data-fk="gad-aprovacoes"]'],
  social: ['.gad[data-fk="gad-social"]'],
  publicacao: ['.gad[data-fk="gad-publicacao"]'],
  anuncios: ['.gad[data-fk="gad-anuncios"]'],
  minhaia: ['.gad[data-fk="gad-minhaia"]'],
  cronograma: ['.gad[data-fk="gad-cronograma"]'],
  zap: ['.gad[data-fk="gad-zap"]'],
  escola: ['.gad[data-fk="gad-escola"]'],
  oficina: ['.gad[data-fk="gad-oficina"]'],
  ias: ['.gad[data-fk="gad-ias"]'],
  catum: ['#catPil'],
  plataforma: [],
};
function ajudaAlvoEl(chave, origem){
  if (origem && origem.closest){ const cx = origem.closest('.caixa,.card,.cxa,.top,.lado'); if (cx) return cx; }
  for (const sel of (window.PD_AJUDA_ALVO[chave] || [])){
    const el = document.querySelector(sel);
    if (el && el.offsetParent !== null && el.getBoundingClientRect().width > 4) return el;
  }
  return null;
}

function ajudaBtn(chave, claro){
  if (!window.PD_AJUDA[chave]) return '';
  return `<button type="button" class="ajuda-q${claro ? ' claro' : ''}" onclick="event.stopPropagation();abrirAjuda('${chave}',undefined,this)" title="O que é isso?" aria-label="Ajuda">?</button>`;
}
function ajudaCss(){
  if (document.getElementById('ajudaCss')) return;
  const st = document.createElement('style'); st.id = 'ajudaCss';
  st.textContent = `
  .ajuda-q{display:inline-grid;place-items:center;width:17px;height:17px;border-radius:50%;border:1px solid currentColor;background:none;color:inherit;opacity:.42;font:600 11px/1 inherit;font-family:inherit;cursor:pointer;vertical-align:2px;margin-left:5px;padding:0;flex:0 0 auto}
  .ajuda-q:hover{opacity:.95}
  #pdAjudaOv{position:fixed;inset:0;z-index:100002}
  #pdAjudaFoco{position:fixed;z-index:100001;pointer-events:none;border:0;border-radius:14px;box-shadow:0 0 16px 2px rgba(255,255,255,.5),0 0 44px 12px rgba(255,255,255,.2),0 0 0 9999px rgba(0,0,0,.45);transition:top .28s ease,left .28s ease,width .28s ease,height .28s ease,border-radius .28s ease}
  #pdAjudaOv .cxa{position:fixed;z-index:100002;background:#fff;color:#121212;border-radius:14px;width:min(330px,calc(100vw - 24px));padding:18px 18px 16px;text-align:left;box-shadow:0 18px 50px rgba(0,0,0,.35);animation:ajSurge .22s ease}
  #pdAjudaOv .cxa.centro{left:50%;top:50%;transform:translate(-50%,-50%)}
  @keyframes ajSurge{from{opacity:0}to{opacity:1}}
  #pdAjudaOv .kk{font-size:10px;letter-spacing:.15em;text-transform:uppercase;opacity:.45;margin-bottom:7px}
  #pdAjudaOv h3{margin:0 0 7px;font-size:16.5px;font-weight:600;line-height:1.3;letter-spacing:-.01em}
  #pdAjudaOv p{margin:0 0 14px;font-size:13px;line-height:1.55;opacity:.8}
  #pdAjudaOv .rodape{display:flex;align-items:center;gap:8px}
  #pdAjudaOv .pts{display:flex;gap:4px;flex:1}
  #pdAjudaOv .pts i{width:5px;height:5px;border-radius:50%;background:#121212;opacity:.18}#pdAjudaOv .pts i.on{opacity:.9}
  #pdAjudaOv .bt{border:1px solid rgba(0,0,0,.2);background:none;color:#121212;border-radius:999px;padding:8px 16px;font:500 12px/1 inherit;font-family:inherit;cursor:pointer}
  #pdAjudaOv .bt.p{background:#121212;color:#fff;border-color:#121212}
  .entenda-pd{position:fixed;left:14px;bottom:14px;z-index:89;border:1px solid rgba(128,128,128,.35);background:rgba(20,20,22,.6);color:#fff;backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);border-radius:999px;padding:7px 13px;font:500 11px/1 inherit;font-family:inherit;letter-spacing:.03em;cursor:pointer;opacity:.6;display:inline-flex;align-items:center;gap:6px}
  .entenda-pd:hover{opacity:1}
  @media (max-width:999px){.entenda-pd{bottom:calc(14px + env(safe-area-inset-bottom));left:10px;padding:6px 11px}}`;
  document.head.appendChild(st);
}
let _ajudaDesfaz = null, _ajudaSegue = 0;
function fecharAjuda(){ _ajudaSegue++; const o = document.getElementById('pdAjudaOv'); if (o) o.remove(); const f = document.getElementById('pdAjudaFoco'); if (f) f.remove();
  if (_ajudaDesfaz){ try { _ajudaDesfaz(); } catch(e){} _ajudaDesfaz = null; } }
function ajudaPosiciona(cx, r){
  // a caixinha fica PERTO da coisa: embaixo quando cabe, senão em cima, senão do lado
  const m = 10, vw = innerWidth, vh = innerHeight, c = cx.getBoundingClientRect();
  let top, left = Math.max(12, Math.min(r.left, vw - c.width - 12));
  if (r.bottom + m + c.height <= vh - 8) top = r.bottom + m;
  else if (r.top - m - c.height >= 8) top = r.top - m - c.height;
  else { top = Math.max(8, Math.min(r.top, vh - c.height - 8)); left = r.right + m + c.width <= vw - 8 ? r.right + m : Math.max(8, r.left - m - c.width); }
  cx.style.top = top + 'px'; cx.style.left = left + 'px';
}
function abrirAjuda(chave, passo, origem){
  ajudaCss(); fecharAjuda();
  const tour = typeof passo === 'number', seq = window.PD_TOUR, i = tour ? passo : -1;
  const d = window.PD_AJUDA[chave]; if (!d) return;
  let espera = 0;
  if (tour && !origem){
    const abre = (window.PD_AJUDA_ABRE || {})[chave];
    if (abre){ try { const desfaz = abre(); if (desfaz){ _ajudaDesfaz = desfaz; espera = 520; } } catch(e){} }
  }
  const ov = document.createElement('div'); ov.id = 'pdAjudaOv';
  ov.onclick = e => { if (e.target === ov) fecharAjuda(); };
  const pts = tour ? `<div class="pts">${seq.map((_, k) => `<i class="${k === i ? 'on' : ''}"></i>`).join('')}</div>` : '<span style="flex:1"></span>';
  const bts = tour
    ? `${i > 0 ? `<button class="bt" onclick="abrirAjuda('${seq[i-1]}',${i-1})">Voltar</button>` : `<button class="bt" onclick="fecharAjuda()">Sair</button>`}
       ${i < seq.length - 1 ? `<button class="bt p" onclick="abrirAjuda('${seq[i+1]}',${i+1})">Próximo</button>` : `<button class="bt p" onclick="fecharAjuda()">Bora trabalhar</button>`}`
    : `<button class="bt p" onclick="fecharAjuda()">Entendi</button>`;
  ov.innerHTML = `<div class="cxa centro"><div class="kk">${tour ? 'Entenda a plataforma · ' + (i + 1) + ' de ' + seq.length : 'Pontodot'}</div><h3>${d.t}</h3><p>${d.p}</p><div class="rodape">${pts}${bts}</div></div>`;
  document.body.appendChild(ov);
  setTimeout(() => ajudaMira(chave, origem), espera);
  if (tour) try { localStorage.setItem('sd_tour_v1', '1'); } catch(e){}
}
function ajudaMira(chave, origem, deNovo){
  // caixa que abriu VAZIA não fica exposta: fecha de volta e a luz destaca o ícone fechado
  if (_ajudaDesfaz && !deNovo){
    const g = ajudaAlvoEl(chave, origem);
    const corpo = g && g.querySelector ? g.querySelector('.gad-corpo') : null;
    const txt = corpo ? (corpo.innerText || '').replace(/\s+/g, ' ').trim() : null;
    if (corpo && txt !== null && txt.length < 6){
      try { _ajudaDesfaz(); } catch(e){} _ajudaDesfaz = null;
      setTimeout(() => ajudaMira(chave, origem, true), 340);
      return;
    }
    if (corpo && /carregando|…$/i.test(txt) && txt.length < 24){
      // ainda nascendo: espera mais um pouco e confere de novo (uma vez)
      setTimeout(() => {
        const t2 = (corpo.innerText || '').replace(/\s+/g, ' ').trim();
        if (document.getElementById('pdAjudaOv') && _ajudaDesfaz && t2.length < 6){
          try { _ajudaDesfaz(); } catch(e){} _ajudaDesfaz = null;
          setTimeout(() => ajudaMira(chave, origem, true), 340);
        } else if (document.getElementById('pdAjudaOv')) ajudaMira(chave, origem, true);
      }, 1300);
    }
  }
  const seg = ++_ajudaSegue;
  let ultimo = null;
  const quadro = () => {
    if (seg !== _ajudaSegue) return;
    const ov = document.getElementById('pdAjudaOv'); if (!ov) return;
    const cx = ov.querySelector('.cxa'); if (!cx) return;
    const alvo = ajudaAlvoEl(chave, origem);
    const r = alvo ? alvo.getBoundingClientRect() : null;
    if (!r || r.width < 3 || r.height < 3){
      // alvo sumiu (re-render, caixa fechando): some o foco e centraliza
      const f = document.getElementById('pdAjudaFoco'); if (f) f.remove();
      if (!cx.classList.contains('centro')){ cx.classList.add('centro'); cx.style.top = ''; cx.style.left = ''; cx.style.transform = ''; }
      ultimo = null; requestAnimationFrame(quadro); return;
    }
    const pad = 3, rr = { top: r.top - pad, left: r.left - pad, width: r.width + pad * 2, height: r.height + pad * 2 };
    rr.bottom = rr.top + rr.height; rr.right = rr.left + rr.width;
    const mudou = !ultimo || Math.abs(rr.top - ultimo.top) > 1.5 || Math.abs(rr.left - ultimo.left) > 1.5 || Math.abs(rr.width - ultimo.width) > 1.5 || Math.abs(rr.height - ultimo.height) > 1.5;
    if (mudou){
      let f = document.getElementById('pdAjudaFoco');
      if (!f){ f = document.createElement('div'); f.id = 'pdAjudaFoco'; document.body.appendChild(f); }
      f.style.top = rr.top + 'px'; f.style.left = rr.left + 'px';
      f.style.width = rr.width + 'px'; f.style.height = rr.height + 'px';
      try { const cs = getComputedStyle(alvo);
        f.style.borderRadius = ['borderTopLeftRadius','borderTopRightRadius','borderBottomRightRadius','borderBottomLeftRadius']
          .map(k => (Math.max(0, parseFloat(cs[k]) || 0) + pad) + 'px').join(' ');
      } catch(e){}
      if (cx.classList.contains('centro')){ cx.classList.remove('centro'); cx.style.transform = 'none'; }
      if (!ultimo || Math.abs(rr.top - ultimo.top) > 6 || Math.abs(rr.left - ultimo.left) > 6 || Math.abs(rr.height - ultimo.height) > 6 || Math.abs(rr.width - ultimo.width) > 6) ajudaPosiciona(cx, rr);
      ultimo = rr;
    }
    requestAnimationFrame(quadro);
  };
  if (ajudaAlvoEl(chave, origem)){ try { ajudaAlvoEl(chave, origem).scrollIntoView({ block:'nearest', inline:'nearest' }); } catch(e){} }
  quadro();
}
function tourPlataforma(){ abrirAjuda(window.PD_TOUR[0], 0); }
function ajudaBotaoCanto(){
  ajudaCss();
  if (document.querySelector('.entenda-pd')) return;
  const b = document.createElement('button'); b.type = 'button'; b.className = 'entenda-pd'; b.onclick = tourPlataforma;
  b.innerHTML = '<span style="display:inline-grid;place-items:center;width:14px;height:14px;border:1px solid currentColor;border-radius:50%;font-size:9.5px;font-weight:600">?</span>Entenda a plataforma';
  document.body.appendChild(b);
}
function ajudaPrimeiraVez(){
  ajudaBotaoCanto();
  let visto = null; try { visto = localStorage.getItem('sd_tour_v1'); } catch(e){}
  if (!visto) setTimeout(tourPlataforma, 1200);
}
