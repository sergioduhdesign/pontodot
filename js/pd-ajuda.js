// ============================================================================
// PD · AJUDA — o "?" elegante e o tour "Entenda a plataforma".
// Ideia da casa: a plataforma não carrega textão. Cada ferramenta tem um ?
// do lado; o clique abre uma caixinha que explica. E o primeiro usuário ganha
// a jornada completa, passo a passo, no botão "Entenda a plataforma".
// ============================================================================
window.PD_AJUDA = {
  plataforma: { t: 'Bem-vindo à PD', p: 'Oi! Eu sou a Gigi, recebo quem chega. Aqui você e as IAs da casa trabalham juntos. Tudo que entra vira tarefa, a produção acontece na área de Trabalho, passa pela aprovação e sai publicado. Este tour mostra o caminho inteiro — e, em cada ferramenta, o ? do ladinho explica o resto.' },
  lertarefa: { t: 'Como ler uma tarefa', p: 'Cada linha é uma tarefa: a carinha diz o estado (sorrindo = andando, piscando = pausada, séria = parada), o nome diz o que é, e o chip do lado diz quem cuida — IA ou pessoa. Clique na linha e ela abre inteira: briefing, prazo, cliente, conversa e o play pra ligar o cronômetro. O ponto colorido avisa que tem novidade desde a última vez.' },
  tarefas: { t: 'Tarefas', p: 'Tudo começa aqui: cada pedido vira uma tarefa com responsável e prazo. Dá pra criar pela plataforma ou mandando mensagem no zap da PD. O play liga o cronômetro; quem é marcado recebe aviso na hora, aqui e no zap.' },
  trabalho: { t: 'Trabalho', p: 'A área de produção da casa. Por enquanto a ferramenta aberta pra equipe é a de Copys — as outras (arte, locução, vídeo, gráfica) aparecem apagadinhas até serem liberadas pra você, pessoa por pessoa, pelo admin.' },
  copys: { t: 'Copys', p: 'Preencha o briefing, cole os dados estratégicos, escolha um estilo e peça. A IA escreve as variantes; você lapida direto nos cards. O que estiver bom segue pra revisão interna e, depois, pro cliente — sem redigitar nada.' },
  aprovacoes: { t: 'Aprovação', p: 'O trabalho pronto entra num lote de aprovação. Primeiro a revisão interna — o "tá bom pra mostrar" da equipe —, depois o cliente. Correção volta pra IA com o apontamento; aprovação segue pro próximo passo sozinha.' },
  social: { t: 'Social', p: 'O conteúdo aprovado entra na fila de publicação e agenda nas redes do cliente: feed, carrossel, stories e reels. Os comentários chegam aqui também, pra responder sem sair da plataforma.' },
  publicacao: { t: 'Publicação', p: 'A fila do que vai ao ar: o que está agendado, pra quando, em qual rede. Deu problema em alguma publicação, é aqui que aparece.' },
  anuncios: { t: 'Anúncios', p: 'As campanhas de mídia dos clientes: investimento, resultado e alertas. Os números vêm direto das contas conectadas.' },
  suaia: { t: 'Antes de tudo: crie a sua IA', p: 'Cada pessoa da casa tem uma IA própria — a sua dupla de trabalho, que aprende o seu jeito e responde por você no chat e no WhatsApp. Ela ainda não nasceu: clique no ovo, escolha quem ela é e dê um nome. Tudo o que vem depois neste tour passa por ela.' },
  minhaia: { t: 'Minha IA', p: 'A sua dupla de trabalho. Ela aprende o seu jeito, lembra do que vocês combinaram e resolve pedidos no chat. Quanto mais você usa, melhor ela fica.' },
  cronograma: { t: 'Cronograma', p: 'A linha do tempo dos trabalhos: o que está pra hoje, o que vem depois e o que está atrasado de verdade (briefing não conta como atraso).' },
  zap: { t: 'Zap', p: 'A PD no seu bolso. Pelo WhatsApp dá pra criar tarefa, pedir lembrete (até pros colegas), receber avisos e perguntar qualquer coisa. É a mesma conversa da plataforma — o que vale aqui, vale lá.' },
  escola: { t: 'Escola das IAs', p: 'As IAs da casa estudam todos os dias: fazem exercícios, recebem correção e só assumem trabalho de verdade quando provam que aprenderam. Os cursos pra humanos abrem em breve — por enquanto, a turma é delas.' },
  oficina: { t: 'Saída de materiais', p: 'O balcão de entrega: tudo que a oficina terminou aparece aqui pra você abrir, conferir e usar.' },
  ias: { t: 'Inteligências da casa', p: 'O time de IAs da PD: cada personagem tem um ofício e um jeito. Elas trabalham nas ferramentas, aprendem na escola e aparecem aqui com o que estão fazendo.' },
  lembretes: { t: 'Lembretes', p: 'Peça aqui ou pelo zap: "me lembra amanhã às 9h", com ou sem repetição, pra você ou pra um colega. O aviso chega na plataforma e no zap.' },
  catumIA: { t: 'Hora de Catum', p: 'É o tempo de ofício que essa IA já acumulou: o que assistiu você fazer, o que entregou e o que estudou. Quanto mais sobe, mais ela faz sozinha.' },
  catum: { t: 'Hora de Catum', p: 'O termômetro de confiança: de tantos trabalhos, quantos a IA acertou de primeira — "de primeira" é quando a equipe topa usar sem corrigir. Correção por mudança de direção ou briefing vago não conta contra ela. Quando a leitura firma, você sabe que já dá pra confiar aquela tarefa àquela IA; o valor por trás é tempo humano devolvido.' },
  equipe: { t: 'Equipe e funções', p: 'Marque as funções de cada pessoa (designer, copy, atendimento…): o fluxo encaminha o trabalho por função, com rodízio de carga. O botão verde liga e desliga o acesso sem apagar nada. E as ferramentas de Trabalho são liberadas pessoa por pessoa — Copys é de todos.' },
  aprovacao_interna: { t: 'Revisão interna', p: 'Antes do cliente ver, a equipe confere: aprovou, libera; precisa mexer, o apontamento volta pra IA corrigir. É esse "tá bom pra mostrar" que conta pra Hora de Catum.' },
  verso: { t: 'O outro lado', p: 'A casa tem dois lados. Girou, apareceu a gestão completa: o funil de trabalho, os quadros, os clientes e o painel de números. Tudo que acontece na frente mora aqui atrás, organizado.' },
  escolaag: { t: 'Escola Agêntica', p: 'A primeira aba do verso é a escola das IAs vista por dentro: cursos, aulas e a evolução de cada personagem. Ela ainda está em finalização pra uso — por enquanto você só acompanha; os cursos abrem pra equipe em breve.' },
  lista: { t: 'Lista', p: 'Os mesmos trabalhos do funil, em linhas: dá pra ordenar, filtrar por cliente, responsável ou status e ver prazo de tudo de uma vez. Ideal pra conferir muita coisa rápido.' },
  fluxo: { t: 'Fluxo', p: 'O caminho de cada trabalho em forma de mapa: de onde veio, por quem passou e o que falta pra sair. Mostra gargalos e dependências sem precisar abrir card por card.' },
  mural: { t: 'Mural', p: 'O quadro de avisos da casa: recados, referências e combinados da equipe num lugar só, pra ninguém depender de mensagem perdida.' },
  redes: { t: 'Redes sociais', p: 'A fila de publicação por rede: o que está agendado, o que já saiu e o que espera aprovação. Daqui você acompanha o calendário de postagens de cada cliente.' },
  funil: { t: 'Funil', p: 'Todo trabalho anda por aqui: entra como briefing, vira produção, passa pela aprovação e sai entregue. Os cards andam pelas colunas com prazo e responsável sempre à vista.' },
  clientes: { t: 'Clientes', p: 'A ficha de cada cliente: marca, acessos, redes conectadas e o zap. É aqui que você liga a Gigi pra atender o cliente no WhatsApp e escolhe quem entra no social.' },
  painel: { t: 'Painel', p: 'Os números da casa: o que está atrasado de verdade (briefing não conta), a carga de cada pessoa e o ritmo das entregas. O raio-x pra decidir sem achismo.' },
  pronto: { t: 'É isso', p: 'Foi um prazer te mostrar a casa. O S do canto gira entre os dois lados quando você quiser. Qualquer dúvida, procura o ? do ladinho de cada ferramenta — e este tour fica guardado no "Entenda a plataforma".' },
};
window.PD_AJUDA_TIT = { 'Trabalho':'trabalho', 'Escola das IAs':'escola', 'Saída de materiais':'oficina', 'Inteligências da casa':'ias', 'Publicação':'publicacao', 'Anúncios':'anuncios', 'Social':'social', 'Minha IA':'minhaia', 'Cronograma':'cronograma', 'Zap':'zap', 'Lembretes':'lembretes' };
window.PD_TOUR = ['plataforma','tarefas','lertarefa','trabalho','copys','aprovacoes','social','zap','escola','verso','escolaag','funil','lista','fluxo','mural','redes','clientes','pronto'];
window.PD_AJUDA_ALVO = {
  tarefas: ['#cxTarefas', '.gad[data-fk="gad-tarefas"]'],
  lertarefa: ['#cxTarefas .t-linha', '#cxTarefas'],
  trabalho: ['.gad[data-fk="gad-estudio"]', '#estBarra'],
  copys: ['.gad[data-fk="gad-estudio"] [title^="Copys"]', '.gad[data-fk="gad-estudio"]', '#ajudaCopys', '.top'],
  aprovacoes: ['.gad[data-fk="gad-aprovacoes"]'],
  social: ['.gad[data-fk="gad-social"]'],
  publicacao: ['.gad[data-fk="gad-publicacao"]'],
  anuncios: ['.gad[data-fk="gad-anuncios"]'],
  minhaia: ['.gad[data-fk="gad-minhaia"]'],
  suaia: ['.gad[data-fk="gad-minhaia"]', '.gad-ico:has(.gad-ovo)', '.gad-ovo'],
  cronograma: ['.gad[data-fk="gad-cronograma"]'],
  zap: ['.gad[data-fk="gad-zap"]'],
  escola: ['.gad[data-fk="gad-escola"]'],
  oficina: ['.gad[data-fk="gad-oficina"]'],
  ias: ['.gad[data-fk="gad-ias"]'],
  catum: ['#catPil'],
  plataforma: [], verso: [], pronto: [],
  escolaag: ['app::#view-feed', 'app::#tab-feed'], lista: ['app::#view-lista', 'app::#tab-lista'], fluxo: ['app::#view-gantt', 'app::#tab-gantt'],
  mural: ['app::#view-mural', 'app::#tab-mural'], redes: ['app::#view-fila', 'app::#tab-fila'],
  funil: ['app::#kanban-board > *:first-child', 'app::#view-kanban'], clientes: ['app::#view-clientes .entity-card', 'app::#view-clientes'], painel: ['app::#view-admin'],
};
function ajudaAlvoEl(chave, origem){
  // devolve { el, fr }: fr é o iframe do verso quando o alvo mora lá dentro
  if (origem && origem.closest){ const cx = origem.closest('.caixa,.card,.cxa,.top,.lado'); if (cx) return { el: cx, fr: null }; }
  for (const sel of (window.PD_AJUDA_ALVO[chave] || [])){
    let el = null, fr = null;
    if (sel.indexOf('app::') === 0){
      fr = document.getElementById('appFrame') || document.getElementById('appMovelFrame');
      try { el = fr && fr.contentDocument ? fr.contentDocument.querySelector(sel.slice(5)) : null; } catch(e){ el = null; }
    } else el = document.querySelector(sel);
    if (el && el.getBoundingClientRect().width > 4 && (fr || el.offsetParent !== null)) return { el, fr };
  }
  return null;
}
function ajudaRectW(w){
  const r = w.el.getBoundingClientRect();
  if (!w.fr) return r;
  const o = w.fr.getBoundingClientRect();
  return { top: r.top + o.top, left: r.left + o.left, width: r.width, height: r.height, bottom: r.bottom + o.top, right: r.right + o.left };
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
  .ajuda-dica{position:fixed;z-index:100003;max-width:min(264px,calc(100vw - 20px));background:rgba(22,23,25,.97);color:#ecebe6;border:.5px solid rgba(255,255,255,.13);border-radius:11px;padding:10px 12px 11px;box-shadow:0 12px 32px rgba(0,0,0,.4);font:12px/1.5 'Helvetica Neue',system-ui,sans-serif;opacity:0;transition:opacity .16s ease;text-align:left}
  .ajuda-dica b{display:block;font-size:12px;font-weight:600;margin-bottom:3px;letter-spacing:-.005em}
  .ajuda-dica span{display:block;opacity:.72}
  label>.ajuda-q,.field-label>.ajuda-q,.lab>.ajuda-q{margin-left:6px}
  h1>.ajuda-q,h2>.ajuda-q,h3>.ajuda-q,h4>.ajuda-q{float:right;margin-left:8px;text-transform:none;letter-spacing:0;position:relative}
  .ajuda-q{position:relative}.ajuda-q::after{content:'';position:absolute;inset:-9px}
  #pdAjudaOv{position:fixed;inset:0;z-index:100002}
  #pdAjudaFoco{position:fixed;z-index:100001;pointer-events:none;border:0;border-radius:14px;opacity:1;box-shadow:0 0 16px 2px rgba(255,255,255,.5),0 0 44px 12px rgba(255,255,255,.2),0 0 0 9999px rgba(0,0,0,.45);transition:opacity .32s ease;animation:ajBrilho 2.6s ease-in-out infinite}
  @keyframes ajBrilho{0%,100%{box-shadow:0 0 14px 2px rgba(255,255,255,.45),0 0 40px 10px rgba(255,255,255,.16),0 0 0 9999px rgba(0,0,0,.45)}50%{box-shadow:0 0 22px 4px rgba(255,255,255,.75),0 0 60px 16px rgba(255,255,255,.3),0 0 0 9999px rgba(0,0,0,.45)}}
  #pdAjudaOv .cxa{position:fixed;z-index:100002;background:#fff;color:#121212;border-radius:14px;width:min(330px,calc(100vw - 24px));padding:18px 18px 16px;text-align:left;box-shadow:0 18px 50px rgba(0,0,0,.35);opacity:1;transition:opacity .3s ease}
  #pdAjudaOv .cxa.centro{left:50%;top:50%;transform:translate(-50%,-50%)}
  #pdAjudaOv .cxa.canto{top:auto!important;left:14px!important;bottom:74px;transform:none!important}
  @keyframes ajSurge{from{opacity:0}to{opacity:1}}
  #pdAjudaOv .kk{font-size:10px;letter-spacing:.15em;text-transform:uppercase;opacity:.45;margin-bottom:7px}
  #pdAjudaOv .guia{display:flex;align-items:center;gap:9px;margin-bottom:10px}
  #pdAjudaOv .guia img{width:34px;height:34px;border-radius:50%;object-fit:cover;box-shadow:0 0 0 1.5px rgba(255,255,255,.18)}
  #pdAjudaOv .guia b{display:block;font-size:12.5px;font-weight:600;line-height:1.15}
  #pdAjudaOv .guia small{display:block;font-size:10px;letter-spacing:.12em;text-transform:uppercase;opacity:.5;margin-top:2px}
  #pdAjudaOv h3{margin:0 0 7px;font-size:16.5px;font-weight:600;line-height:1.3;letter-spacing:-.01em}
  #pdAjudaOv p{margin:0 0 14px;font-size:13px;line-height:1.55;opacity:.8}
  #pdAjudaOv .rodape{display:flex;align-items:center;gap:8px}
  #pdAjudaOv .pts{display:flex;gap:4px;flex:1}
  #pdAjudaOv .pts i{width:5px;height:5px;border-radius:50%;background:#121212;opacity:.18}#pdAjudaOv .pts i.on{opacity:.9}
  #pdAjudaOv .bt{border:1px solid rgba(0,0,0,.2);background:none;color:#121212;border-radius:999px;padding:8px 16px;font:500 12px/1 inherit;font-family:inherit;cursor:pointer;white-space:nowrap;flex:0 0 auto}
  #pdAjudaOv .bt.p{background:#121212;color:#fff;border-color:#121212}
  .entenda-pd{position:fixed;left:14px;bottom:14px;z-index:89;border:1px solid rgba(128,128,128,.35);background:rgba(20,20,22,.6);color:#fff;backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);border-radius:999px;padding:7px 13px;font:500 11px/1 inherit;font-family:inherit;letter-spacing:.03em;cursor:pointer;opacity:.6;display:inline-flex;align-items:center;gap:6px}
  .entenda-pd:hover{opacity:1}
  @media (max-width:999px){.entenda-pd{bottom:calc(14px + env(safe-area-inset-bottom));left:10px;padding:6px 11px}}`;
  document.head.appendChild(st);
}
// A dica do "?": caixinha escura, discreta, colada no ? (hover no desktop, toque no celular). Sem blur, sem centro da tela.
let _dicaEl = null, _dicaDono = null, _dicaT = 0;
function ajudaDicaFecha(rapido){
  clearTimeout(_dicaT);
  const d = _dicaEl; _dicaEl = null; _dicaDono = null; if (!d) return;
  d.style.opacity = '0'; setTimeout(() => d.remove(), rapido ? 0 : 180);
}
function ajudaDica(chave, dono, clique){
  const d0 = window.PD_AJUDA[chave]; if (!d0 || !dono) return;
  if (_dicaDono === dono){ if (clique && !(window.matchMedia && window.matchMedia('(hover:hover)').matches)) ajudaDicaFecha(); return; }
  ajudaDicaFecha(true); ajudaCss();
  const d = document.createElement('div'); d.className = 'ajuda-dica';
  d.innerHTML = `<b>${d0.t}</b><span>${d0.p}</span>`;
  d.addEventListener('mouseenter', () => clearTimeout(_dicaT));
  d.addEventListener('mouseleave', () => { _dicaT = setTimeout(() => ajudaDicaFecha(), 200); });
  document.body.appendChild(d); _dicaEl = d; _dicaDono = dono;
  const r = dono.getBoundingClientRect(), c = d.getBoundingClientRect(), vw = innerWidth, vh = innerHeight, m = 8;
  let top = r.bottom + m, left = r.left + r.width / 2 - 18;
  if (top + c.height > vh - 8) top = Math.max(8, r.top - m - c.height);
  left = Math.max(10, Math.min(left, vw - c.width - 10));
  d.style.top = top + 'px'; d.style.left = left + 'px';
  requestAnimationFrame(() => { d.style.opacity = '1'; });
}
document.addEventListener('mouseover', e => {
  const q = e.target && e.target.closest && e.target.closest('.ajuda-q'); if (!q || q === _dicaDono) { if (q) clearTimeout(_dicaT); return; }
  if (window.matchMedia && !window.matchMedia('(hover:hover)').matches) return;
  const m = (q.getAttribute('onclick') || '').match(/abrirAjuda\('([^']+)'/); if (!m) return;
  clearTimeout(_dicaT); _dicaT = setTimeout(() => ajudaDica(m[1], q, false), 140);
});
document.addEventListener('mouseout', e => {
  const q = e.target && e.target.closest && e.target.closest('.ajuda-q'); if (!q) return;
  clearTimeout(_dicaT); _dicaT = setTimeout(() => { if (_dicaDono === q || !_dicaEl) ajudaDicaFecha(); }, 220);
});
document.addEventListener('pointerdown', e => { if (_dicaEl && !(e.target.closest && (e.target.closest('.ajuda-dica') || e.target.closest('.ajuda-q')))) ajudaDicaFecha(); }, true);
document.addEventListener('keydown', e => { if (e.key === 'Escape') ajudaDicaFecha(); });
addEventListener('scroll', () => ajudaDicaFecha(true), true);
let _ajudaDesfaz = null, _ajudaSegue = 0, _ajudaGrupo = null;
window.PD_AJUDA_GRUPO = window.PD_AJUDA_GRUPO || {};   // passos que moram dentro da mesma caixa (ex.: trabalho e copys)
function fecharAjuda(trocando){ _ajudaSegue++;
  const o = document.getElementById('pdAjudaOv'), f = document.getElementById('pdAjudaFoco');
  if (o){ o.id = ''; o.style.pointerEvents = 'none'; const cx = o.querySelector('.cxa'); if (cx) cx.style.opacity = '0'; setTimeout(() => o.remove(), 260); }
  if (f){ f.id = ''; f.style.opacity = '0'; setTimeout(() => f.remove(), 380); }
  if (_ajudaDesfaz){ try { _ajudaDesfaz(); } catch(e){} _ajudaDesfaz = null; _ajudaGrupo = null; }
  if (!trocando && window._ajudaDesfazTour){ try { window._ajudaDesfazTour(); } catch(e){} window._ajudaDesfazTour = null; } }
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
  ajudaCss();
  if (typeof passo !== 'number' && origem && origem.nodeType === 1) return ajudaDica(chave, origem, true);
  const tour = typeof passo === 'number', seq = window.PD_TOUR, i = tour ? passo : -1;
  const d = window.PD_AJUDA[chave]; if (!d) return;
  const tok = ++_ajudaSegue;
  const cabeca = tour
    ? `<div class="guia"><img src="/pd/personagens/gigi/avatar.jpg" alt="Gigi"><div><b>Gigi, a anfitriã da casa</b><small>te apresenta a PD · ${i + 1} de ${seq.length}</small></div></div>`
    : `<div class="kk">Pontodot</div>`;
  const corpo = `${cabeca}<h3>${d.t}</h3><p>${d.p}</p><div class="rodape"><span style="flex:1"></span>${tour
    ? `${i > 0 ? `<button class="bt" onclick="abrirAjuda('${seq[i-1]}',${i-1})">Voltar</button>` : `<button class="bt" onclick="fecharAjuda()">Sair</button>`}
       ${i < seq.length - 1 ? `<button class="bt p" onclick="abrirAjuda('${seq[i+1]}',${i+1})">Próximo</button>` : `<button class="bt p" onclick="fecharAjuda()">Bora trabalhar</button>`}`
    : `<button class="bt p" onclick="fecharAjuda()">Entendi</button>`}</div>`;
  const abreProximo = () => {
    // 3) abre o que precisa (se precisa) e só então acende a luz
    let espera = 0;
    if (tour && !origem){
      const abre = (window.PD_AJUDA_ABRE || {})[chave];
      const gNovo = window.PD_AJUDA_GRUPO[chave] || null;
      if (gNovo && _ajudaDesfaz && _ajudaGrupo === gNovo){ /* já está aberta: segue por dentro dela, sem reabrir */ }
      else if (abre){ try { const desfaz = abre(); if (desfaz){ _ajudaDesfaz = desfaz; _ajudaGrupo = gNovo; espera = 560; } } catch(e){} }
    }
    setTimeout(() => { if (tok === _ajudaSegue) ajudaMira(chave, origem); }, espera);
  };
  let ov = document.getElementById('pdAjudaOv');
  if (ov){
    // troca de passo: 1) apaga a caixa e a luz  2) fecha o que estava aberto  3) abre o novo, acende, e a caixa aparece
    const cx = ov.querySelector('.cxa'), f = document.getElementById('pdAjudaFoco');
    if (cx){ cx.style.opacity = '0'; cx.dataset.espera = '1'; }
    if (f){ f.id = ''; f.style.opacity = '0'; setTimeout(() => f.remove(), 360); }
    setTimeout(() => {
      if (tok !== _ajudaSegue) return;
      let fechou = 0;
      const gProx = window.PD_AJUDA_GRUPO[chave] || null;
      if (_ajudaDesfaz && !(tour && !origem && gProx && _ajudaGrupo === gProx)){ try { _ajudaDesfaz(); } catch(e){} _ajudaDesfaz = null; _ajudaGrupo = null; fechou = 480; }
      if (cx) cx.innerHTML = corpo;
      setTimeout(() => { if (tok === _ajudaSegue) abreProximo(); }, fechou);
    }, 340);
    if (tour) try { localStorage.setItem(ajudaTourChave(), '1'); } catch(e){}
    return;
  }
  if (_ajudaDesfaz){ try { _ajudaDesfaz(); } catch(e){} _ajudaDesfaz = null; }
  ov = document.createElement('div'); ov.id = 'pdAjudaOv';
  ov.onclick = e => { if (e.target === ov) fecharAjuda(); };
  ov.innerHTML = `<div class="cxa centro" style="opacity:0" data-espera="1">${corpo}</div>`;
  document.body.appendChild(ov);
  abreProximo();
  if (tour) try { localStorage.setItem(ajudaTourChave(), '1'); } catch(e){}
}
function ajudaMira(chave, origem, deNovo){
  // caixa que abriu VAZIA não fica exposta: fecha de volta e a luz destaca o ícone fechado
  if (_ajudaDesfaz && !deNovo){
    const g0 = ajudaAlvoEl(chave, origem), g = g0 && g0.el;
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
  let ultimo = null, sumiuEm = 0;
  const quadro = () => {
    if (seg !== _ajudaSegue) return;
    const ov = document.getElementById('pdAjudaOv'); if (!ov) return;
    const cx = ov.querySelector('.cxa'); if (!cx) return;
    const noVerso = document.documentElement.classList.contains('noVerso');
    if (noVerso && !cx.classList.contains('canto')){ cx.classList.remove('centro'); cx.classList.add('canto'); cx.style.top = ''; cx.style.left = ''; }
    if (!noVerso && cx.classList.contains('canto')) cx.classList.remove('canto');
    const alvo = ajudaAlvoEl(chave, origem);
    const r = alvo ? ajudaRectW(alvo) : null;
    if (!r || r.width < 3 || r.height < 3){
      // alvo sumiu (tela trocando, caixa fechando): espera um pouco antes de mexer em qualquer coisa —
      // só se demorar de verdade o foco se apaga em fade e a caixinha vai pro centro
      const agora = performance.now();
      if (!sumiuEm) sumiuEm = agora;
      if (agora - sumiuEm > 400){
        const f = document.getElementById('pdAjudaFoco');
        if (f && !f.dataset.saindo){ f.dataset.saindo = '1'; f.style.opacity = '0'; setTimeout(() => { if (f.dataset.saindo) f.remove(); }, 400); }
      }
      if (agora - sumiuEm > (!origem && !((window.PD_AJUDA_ALVO || {})[chave] || []).length ? 60 : 900)){
        if (!noVerso && !cx.classList.contains('centro')){ cx.classList.add('centro'); cx.style.top = ''; cx.style.left = ''; cx.style.transform = ''; ultimo = null; }
        if (cx.dataset.espera){ delete cx.dataset.espera; cx.style.opacity = '1'; }
      }
      requestAnimationFrame(quadro); return;
    }
    sumiuEm = 0;
    const pad = 3, rr = { top: r.top - pad, left: r.left - pad, width: r.width + pad * 2, height: r.height + pad * 2 };
    if (rr.width > innerWidth * .86 && rr.height > innerHeight * .78){
      const mx = 18, my = 16;
      rr.top += my; rr.left += mx; rr.width -= mx * 2; rr.height -= my * 2; rr.cheia = true;
    }
    rr.bottom = rr.top + rr.height; rr.right = rr.left + rr.width;
    const mudou = !ultimo || Math.abs(rr.top - ultimo.top) > 1.5 || Math.abs(rr.left - ultimo.left) > 1.5 || Math.abs(rr.width - ultimo.width) > 1.5 || Math.abs(rr.height - ultimo.height) > 1.5;
    if (mudou){
      let f = document.getElementById('pdAjudaFoco');
      if (!f){ f = document.createElement('div'); f.id = 'pdAjudaFoco'; f.style.opacity = '0'; document.body.appendChild(f); requestAnimationFrame(() => f.style.opacity = '1'); }
      if (f.dataset.saindo){ delete f.dataset.saindo; f.style.opacity = '1'; }
      f.style.top = rr.top + 'px'; f.style.left = rr.left + 'px';
      f.style.width = rr.width + 'px'; f.style.height = rr.height + 'px';
      if (rr.cheia) f.style.borderRadius = '18px';
      else try { const cs = alvo.el.ownerDocument.defaultView.getComputedStyle(alvo.el);
        f.style.borderRadius = ['borderTopLeftRadius','borderTopRightRadius','borderBottomRightRadius','borderBottomLeftRadius']
          .map(k => (Math.max(0, parseFloat(cs[k]) || 0) + pad) + 'px').join(' ');
      } catch(e){}
      if (!noVerso){
        if (cx.classList.contains('centro')){ cx.classList.remove('centro'); cx.style.transform = 'none'; }
        if (!ultimo || Math.abs(rr.top - ultimo.top) > 6 || Math.abs(rr.left - ultimo.left) > 6 || Math.abs(rr.height - ultimo.height) > 6 || Math.abs(rr.width - ultimo.width) > 6) ajudaPosiciona(cx, rr);
      }
      ultimo = rr;
      if (cx.dataset.espera){ delete cx.dataset.espera; requestAnimationFrame(() => { cx.style.opacity = '1'; }); }
    }
    requestAnimationFrame(quadro);
  };
  { const w = ajudaAlvoEl(chave, origem); if (w && !w.fr){ try { w.el.scrollIntoView({ block:'nearest', inline:'nearest' }); } catch(e){} } }
  quadro();
}
function ajudaBotaoCanto(){
  ajudaCss();
  if (document.querySelector('.entenda-pd')) return;
  const b = document.createElement('button'); b.type = 'button'; b.className = 'entenda-pd'; b.onclick = tourPlataforma;
  b.innerHTML = '<span style="display:inline-grid;place-items:center;width:14px;height:14px;border:1px solid currentColor;border-radius:50%;font-size:9.5px;font-weight:600">?</span>Entenda a plataforma';
  document.body.appendChild(b);
}
function ajudaTourChave(){ const uid = (window.perfil && window.perfil.id) || ''; return 'sd_tour_v1' + (uid ? ':' + uid : ''); }   // por pessoa, não por aparelho
function ajudaPrimeiraVez(){
  ajudaBotaoCanto();
  let visto = null; try { visto = localStorage.getItem(ajudaTourChave()); } catch(e){}
  if (!visto) setTimeout(tourPlataforma, 1200);
}
function tourPlataforma(){
  // sem IA ainda: o segundo passo do tour é "crie a sua IA" (e some quando ela já nasceu)
  const semIA = (typeof temIA === 'function') ? !temIA() : false;
  window.PD_TOUR = (window.PD_TOUR || []).filter(k => k !== 'suaia');
  if (semIA && window.PD_TOUR[0] === 'plataforma') window.PD_TOUR.splice(1, 0, 'suaia');
  abrirAjuda(window.PD_TOUR[0], 0);
}

/* ===== "?" automático: explicação longa (nota/dica) vira uma linha curta + ? ao lado; clicou, a caixinha explica ===== */
(function(){
  const SEL = '.nota,.vazio,.esc-lead2,.esc-nota,.hint,.dica,.ip-leg,.ip-sub,.esc-al-nota,.esc-ficcao,.esc-sub,.page-subtitle,.modal-subtitle,.field-hint,.form-hint,.jt-rodape,.jt-intro,.av-legenda,.help-text,.legenda';
  const MUDO = /\b(erro|falhou|falha|n[ãa]o foi poss[ií]vel|rode o sql|expir|sem permiss|tente de novo|n[ãa]o consegui|servidor)\b/i;
  const NAO = '.comment,.comments-list,.cmt,.msg,.bolha,.conversa,.t-linha,.det-pop,textarea,input,pre,code,td,th,.cxa,#pdAjudaOv,[contenteditable],.balao,#toast,.toast,.ajuda-ok,.entity-card,#admin-users-active,#admin-users-paused,.gente,.user-card,.perfil-card';
  const ESTILO = '[style*="color:var(--text3)"],[style*="color:var(--branco2)"],[style*="color: var(--text3)"],[style*="color:var(--text2)"],[style*="opacity:.5"],[style*="opacity:.6"],[style*="opacity:.55"],[style*="opacity:.65"],[style*="opacity:.7"]';
  let n = 0, agenda = 0;
  function limpo(t){ return String(t || '').replace(/\s+/g, ' ').trim(); }
  function ehCandidato(el){
    if (!el || el.dataset.pdq || !el.closest) return false;
    if (el.closest(NAO)) return false;
    if (el.querySelector('div,p,ul,ol,table,button,input,select,textarea,img,svg,h1,h2,h3,h4,a[href]')) return false;
    if ((el.getAttribute('style') || '').indexOf('pre-wrap') > -1) return false;
    const t = limpo(el.textContent); if (t.length < 40 || MUDO.test(t)) return false;
    if (!el.matches(SEL)){
      const fs = parseFloat((el.style && el.style.fontSize) || '0');
      if (!(fs > 0 && fs <= 12.6)) return false;
    }
    return true;
  }
  function titulo(el){
    let x = el;
    for (let i = 0; i < 4 && x; i++, x = x.parentElement){
      let a = x.previousElementSibling;
      while (a){ if (/^H[1-4]$/.test(a.tagName) || /gad-tit|cx-cab/.test(a.className || '')){ const t = limpo(a.textContent).replace(/\?$/, ''); if (t) return t; } a = a.previousElementSibling; }
      const h = x.querySelector && x.querySelector('h2,h3,h4'); if (h){ const t = limpo(h.textContent); if (t) return t; }
    }
    return 'Como funciona';
  }
  // o cabeçalho (título da seção) logo acima do texto: o ? mora no cantinho dele
  function cabecalhoDe(el){
    const r0 = el.getBoundingClientRect(), oculto = !r0.height && !r0.width;
    let x = el;
    for (let i = 0; i < (oculto ? 2 : 3) && x && x.parentElement; i++, x = x.parentElement){
      let a = x.previousElementSibling;
      while (a){
        if (/^(H[1-4]|LABEL)$/.test(a.tagName) || /(^|\s)(gad-tit|cx-cab|sec-t|tit|field-label|form-label|lab|ip-h|modal-title)(\s|$)/.test(a.className || '')){
          if (limpo(a.textContent).length >= 70) return null;
          if (oculto) return a;
          const r = a.getBoundingClientRect();
          if (r0.top - r.bottom < 150 && r.height < 70) return a;
          return null;
        }
        a = a.previousElementSibling;
      }
    }
    return null;
  }
  function resumo(t){
    const m = t.match(/^(.{20,95}?[.!?])(\s|$)/); if (m) return m[1];
    if (t.length <= 95) return t;
    const c = t.slice(0, 84); const k = c.lastIndexOf(' '); return (k > 40 ? c.slice(0, k) : c) + '…';
  }
  function converte(el){
    const chave = 'auto' + (++n), html = el.innerHTML, t = limpo(el.textContent);
    window.PD_AJUDA[chave] = { t: titulo(el), p: html };
    el.dataset.pdq = '1';
    const q = `<button type="button" class="ajuda-q" onclick="event.stopPropagation();abrirAjuda('${chave}',undefined,this)" title="Ler a explicação" aria-label="Explicação">?</button>`;
    // logo abaixo de um título: o texto some e o ? vai pro lado do título
    // um ? por caixinha: se a caixa já tem o dela, o texto entra nele (ou some, se o ? é manual)
    const caixa = el.closest('.gad,.caixa,.modal');
    if (caixa){ const ex = caixa.querySelector('.ajuda-q'); if (ex && !el.contains(ex)){ const k = ex.dataset.auto; if (k && window.PD_AJUDA[k]) window.PD_AJUDA[k].p += '<br><br>' + html; delete window.PD_AJUDA[chave]; el.style.display = 'none'; return; } }
    const vazio = /^(nenhum|nenhuma|sem |ainda n|nada |prancheta vazia)/i.test(t);
    const cab = vazio ? null : cabecalhoDe(el);
    if (cab){
      const ja = cab.querySelector('.ajuda-q');
      if (ja){ const k = ja.dataset.auto; if (k && window.PD_AJUDA[k]) window.PD_AJUDA[k].p += '<br><br>' + html; delete window.PD_AJUDA[chave]; }
      else { cab.insertAdjacentHTML('beforeend', q.replace('<button ', '<button data-auto="' + chave + '" ')); }
      el.style.display = 'none'; return;
    }
    el.innerHTML = vazio ? esc2(resumo(t)).replace(/\s+(\S+)$/, '\u00a0$1') + '\u00a0' + q : q;   // sem título por perto: só o ?, sem texto
  }
  function esc2(s){ return String(s).replace(/[&<>]/g, c => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;' }[c])); }
  function subDeTitulo(raiz){
    raiz.querySelectorAll('h1 .vazio,h2 .vazio,h3 .vazio,h4 .vazio,h3 small.nota,h3 small.dica').forEach(sm => {
      if (sm.dataset.pdq || sm.id) return;
      const t = limpo(sm.textContent).replace(/^[·•\-–—\s]+/, ''); if (t.length < 10 || MUDO.test(t)) return;
      const h = sm.closest('h1,h2,h3,h4'); if (!h || h.closest(NAO)) return;
      sm.dataset.pdq = '1';
      const txt = esc2(t.charAt(0).toUpperCase() + t.slice(1));
      const ja = h.querySelector('.ajuda-q');
      if (ja){ const k = ja.dataset.auto; if (k && window.PD_AJUDA[k]) window.PD_AJUDA[k].p += '<br><br>' + txt; sm.style.display = 'none'; return; }
      const chave = 'auto' + (++n);
      window.PD_AJUDA[chave] = { t: limpo(h.firstChild && h.firstChild.textContent) || 'Como funciona', p: txt };
      sm.className = ''; sm.removeAttribute('style');
      sm.innerHTML = `<button type="button" class="ajuda-q" data-auto="${chave}" onclick="event.stopPropagation();abrirAjuda('${chave}',undefined,this)" aria-label="Explicação">?</button>`;
    });
  }
  function umPorTitulo(raiz){
    raiz.querySelectorAll('h1,h2,h3,h4,label,.field-label,.gad-tit,.cx-cab').forEach(h => {
      const qs = h.querySelectorAll('.ajuda-q'); for (let i = 1; i < qs.length; i++) qs[i].remove();
    });
  }
  function varre(raiz){
    if (!raiz || raiz.nodeType !== 1) return;
    ajudaCss(); subDeTitulo(raiz.nodeType === 1 ? raiz : document);
    const lista = []; if (raiz.matches && (raiz.matches(SEL) || raiz.matches(ESTILO))) lista.push(raiz);
    raiz.querySelectorAll(SEL + ',' + ESTILO).forEach(e => lista.push(e));
    lista.forEach(e => { try { if (ehCandidato(e)) converte(e); } catch(err){} });
  }
  function inicia(){
    if (window.__pdqIniciado) return; window.__pdqIniciado = true;
    varre(document.body); umPorTitulo(document);
    const fila = new Set();
    new MutationObserver(ms => {
      ms.forEach(m => m.addedNodes.forEach(nd => { if (nd.nodeType === 1 && !(nd.classList && (nd.classList.contains('ajuda-q') || nd.id === 'pdAjudaOv' || nd.id === 'pdAjudaFoco'))) fila.add(nd); }));
      if (!agenda && fila.size) agenda = setTimeout(() => { agenda = 0; const l = [...fila]; fila.clear(); l.forEach(varre); umPorTitulo(document); }, 120);
    }).observe(document.body, { childList: true, subtree: true });
  }
  window.ajudaAutoVarre = varre;
  if (document.body) inicia(); else document.addEventListener('DOMContentLoaded', inicia);
})();
