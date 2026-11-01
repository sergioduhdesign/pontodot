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

function ajudaBtn(chave, claro){
  if (!window.PD_AJUDA[chave]) return '';
  return `<button type="button" class="ajuda-q${claro ? ' claro' : ''}" onclick="event.stopPropagation();abrirAjuda('${chave}')" title="O que é isso?" aria-label="Ajuda">?</button>`;
}
function ajudaCss(){
  if (document.getElementById('ajudaCss')) return;
  const st = document.createElement('style'); st.id = 'ajudaCss';
  st.textContent = `
  .ajuda-q{display:inline-grid;place-items:center;width:17px;height:17px;border-radius:50%;border:1px solid currentColor;background:none;color:inherit;opacity:.42;font:600 11px/1 inherit;font-family:inherit;cursor:pointer;vertical-align:2px;margin-left:5px;padding:0;flex:0 0 auto}
  .ajuda-q:hover{opacity:.95}
  #pdAjudaOv{position:fixed;inset:0;z-index:100000;background:rgba(0,0,0,.55);display:flex;align-items:center;justify-content:center;padding:20px;backdrop-filter:blur(3px);-webkit-backdrop-filter:blur(3px)}
  #pdAjudaOv .cxa{background:#fff;color:#121212;border-radius:16px;max-width:400px;width:100%;padding:26px 24px 22px;text-align:center;box-shadow:0 24px 70px rgba(0,0,0,.35);animation:ajSurge .22s ease}
  @keyframes ajSurge{from{transform:translateY(8px);opacity:0}to{transform:none;opacity:1}}
  #pdAjudaOv .kk{font-size:10.5px;letter-spacing:.16em;text-transform:uppercase;opacity:.45;margin-bottom:10px}
  #pdAjudaOv h3{margin:0 0 10px;font-size:19px;font-weight:600;line-height:1.3;letter-spacing:-.01em}
  #pdAjudaOv p{margin:0 0 18px;font-size:13.5px;line-height:1.6;opacity:.8;text-align:left}
  #pdAjudaOv .pts{display:flex;gap:5px;justify-content:center;margin-bottom:14px}
  #pdAjudaOv .pts i{width:5px;height:5px;border-radius:50%;background:#121212;opacity:.18}#pdAjudaOv .pts i.on{opacity:.9}
  #pdAjudaOv .bts{display:flex;gap:8px;justify-content:center}
  #pdAjudaOv .bt{border:1px solid rgba(0,0,0,.2);background:none;color:#121212;border-radius:999px;padding:9px 20px;font:500 12.5px/1 inherit;font-family:inherit;cursor:pointer}
  #pdAjudaOv .bt.p{background:#121212;color:#fff;border-color:#121212}
  .entenda-pd{position:fixed;left:14px;bottom:14px;z-index:89;border:1px solid rgba(128,128,128,.35);background:rgba(20,20,22,.6);color:#fff;backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);border-radius:999px;padding:7px 13px;font:500 11px/1 inherit;font-family:inherit;letter-spacing:.03em;cursor:pointer;opacity:.6;display:inline-flex;align-items:center;gap:6px}
  .entenda-pd:hover{opacity:1}
  @media (max-width:999px){.entenda-pd{bottom:calc(14px + env(safe-area-inset-bottom));left:10px;padding:6px 11px}}`;
  document.head.appendChild(st);
}
function fecharAjuda(){ const o = document.getElementById('pdAjudaOv'); if (o) o.remove(); }
function abrirAjuda(chave, passo){
  ajudaCss(); fecharAjuda();
  const tour = typeof passo === 'number', seq = window.PD_TOUR, i = tour ? passo : -1;
  const d = window.PD_AJUDA[chave]; if (!d) return;
  const ov = document.createElement('div'); ov.id = 'pdAjudaOv';
  ov.onclick = e => { if (e.target === ov) fecharAjuda(); };
  const pts = tour ? `<div class="pts">${seq.map((_, k) => `<i class="${k === i ? 'on' : ''}"></i>`).join('')}</div>` : '';
  const bts = tour
    ? `<div class="bts">${i > 0 ? `<button class="bt" onclick="abrirAjuda('${seq[i-1]}',${i-1})">Voltar</button>` : `<button class="bt" onclick="fecharAjuda()">Sair</button>`}
       ${i < seq.length - 1 ? `<button class="bt p" onclick="abrirAjuda('${seq[i+1]}',${i+1})">Próximo</button>` : `<button class="bt p" onclick="fecharAjuda()">Bora trabalhar</button>`}</div>`
    : `<div class="bts"><button class="bt p" onclick="fecharAjuda()">Entendi</button></div>`;
  ov.innerHTML = `<div class="cxa"><div class="kk">${tour ? 'Entenda a plataforma · ' + (i + 1) + ' de ' + seq.length : 'Pontodot'}</div><h3>${d.t}</h3><p>${d.p}</p>${pts}${bts}</div>`;
  document.body.appendChild(ov);
  if (tour) try { localStorage.setItem('sd_tour_v1', '1'); } catch(e){}
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
