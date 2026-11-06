/* PD · MODELOS E PADRÕES — catálogo único dos arquivos oficiais de trabalho (home e painel usam este mesmo).
   Para acrescentar um modelo novo: um item em PDM.itens. Arquivo estático = url; planilha viva do Drive = vivo:true. */
(function(){
const ICO = '<path d="M19.4 7.85c-.05.32.06.65.29.88l1.57 1.57c.47.47.71 1.09.71 1.7s-.24 1.23-.71 1.7l-1.61 1.61a.98.98 0 0 1-.84.28c-.47-.07-.8-.48-.97-.93a2.5 2.5 0 1 0-3.21 3.21c.45.17.86.5.93.97a.98.98 0 0 1-.28.84l-1.61 1.61a2.4 2.4 0 0 1-1.7.71 2.4 2.4 0 0 1-1.7-.71l-1.57-1.57a1.03 1.03 0 0 0-.88-.29c-.49.07-.84.5-1.02.97a2.5 2.5 0 1 1-3.24-3.24c.46-.18.89-.53.97-1.02a1.03 1.03 0 0 0-.29-.88L2.7 13.7A2.4 2.4 0 0 1 2 12c0-.62.24-1.23.71-1.7l1.52-1.53c.24-.24.58-.35.92-.3.51.08.88.53 1.07 1.01a2.5 2.5 0 1 0 3.26-3.26c-.48-.2-.93-.56-1.01-1.07-.05-.34.06-.68.3-.92l1.53-1.53A2.4 2.4 0 0 1 12 2c.62 0 1.23.24 1.7.71l1.57 1.57c.23.23.56.34.88.29.49-.07.84-.5 1.02-.97a2.5 2.5 0 1 1 3.24 3.24c-.46.18-.89.53-.97 1.02z"/>';
const PDM = window.PDM = {
  icone: ICO,
  titulo: 'Modelos e padrões',
  sub: 'Baixe aqui os arquivos oficiais de trabalho da PD para trânsito de dados, informações, formatos, briefings e afins.',
  itens: [
    { id:'importacao', titulo:'Importação de tarefas', nota:'Cria e atualiza tarefas em lote (cliente, produto, responsável, datas, mãe/filha).', como:'Preencha uma linha por tarefa (SD_ID vazio cria, preenchido atualiza) e suba em Painel › Importar Excel.', url:'/STUDIO_DESIGN_modelo_importacao.xlsx' },
    { id:'lote', titulo:'Lote de aprovação', nota:'Peças e legendas para o cliente aprovar, com agendamento.', como:'Preencha a aba IMPORTACAO e suba junto com as imagens/vídeos em Novo lote de aprovação.', url:'/SD_modelo_lote_aprovacao.xlsx' },
    { id:'vistoria', titulo:'Vistoria semanal de social', nota:'A planilha da Camila, sempre a versão de agora (direto do Drive).', como:'A PD lê esta planilha todo dia e lembra a Camila pelo zap do que falta. Edite pelo link do Drive; aqui você baixa uma cópia.', vivo:true },
    { id:'textos', titulo:'Textos da fornada', nota:'Título, textos, legenda e palavras-chave de cada peça de uma fornada.', como:'Uma linha por peça. Na Design: peça modelo aprovada › Fornada › subir Excel de textos.', url:'/modelos/PD_modelo_textos_fornada.xlsx' },
    { id:'agenda', titulo:'Agendamento de posts', nota:'Legenda, tipo (feed/story/reels), arquivo e data/hora de cada post.', como:'A mídia já precisa estar na Biblioteca do cliente. Na Design: agendamento do cliente › subir Excel.', url:'/modelos/PD_modelo_agendamento_posts.xlsx' },
    { id:'aula', titulo:'Aula da Escola das IAs', nota:'Ficha de uma aula/skill: objetivo, entradas, método, critérios, limites e exemplos.', como:'Preencha só a coluna C da aba Aula e suba em Escola — bastidores › Subir planilha preenchida.', url:'/modelos/PD_modelo_aula_escola.xlsx' },
    { id:'dig', titulo:'Base de leads do Dig', nota:'Empresas para prospecção: site, contato, cidade, segmento, porte.', como:'Uma empresa por linha (só Empresa é obrigatória). No Dig › Entrar › Subir a base inteira.', url:'/modelos/PD_modelo_base_dig.xlsx' }
  ],
  _esc: s => String(s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])),
  svg: (s) => `<svg width="${s||18}" height="${s||18}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">${ICO}</svg>`,
  async baixarVivo(btn, token){
    const o = btn.innerHTML; btn.disabled = true; btn.style.opacity = .5;
    try {
      const r = await fetch('/pd-modelos.php', { method:'POST', headers:{ 'Content-Type':'application/json', Authorization:'Bearer ' + token }, body: JSON.stringify({ acao:'baixar' }) });
      if (!r.ok) throw new Error((await r.json().catch(()=>({}))).erro || 'Falha ' + r.status);
      const b = await r.blob(), a = document.createElement('a'); a.href = URL.createObjectURL(b); a.download = 'SD_Vistoria_Social_Semanal.xlsx';
      document.body.appendChild(a); a.click(); a.remove(); setTimeout(() => URL.revokeObjectURL(a.href), 4000);
    } catch(e){ alert('Não consegui baixar agora: ' + e.message); }
    btn.disabled = false; btn.style.opacity = ''; btn.innerHTML = o;
  },
  // Lista completa: título, o que é, como usar e botão de baixar (ícone) com link direto.
  listaHtml(getToken, max){
    const bx = '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4v11M7.5 11 12 15.5 16.5 11M5 19.5h14"/></svg>';
    return (max ? PDM.itens.slice(0, max) : PDM.itens).map(i => `
      <div style="display:flex;align-items:flex-start;gap:12px;padding:11px 0;border-top:1px solid rgba(127,127,127,.18)">
        <div style="flex:1;min-width:0"><div style="font-size:13.5px;font-weight:600;line-height:1.3">${PDM._esc(i.titulo)}</div>
          <div style="font-size:12px;opacity:.78;line-height:1.45;margin-top:2px">${PDM._esc(i.nota)}</div>
          ${max ? '' : `<div style="font-size:11.5px;opacity:.6;line-height:1.45;margin-top:4px"><b>Como usar:</b> ${PDM._esc(i.como)}</div>`}</div>
        ${i.vivo
          ? `<button class="btn" title="Baixar (versão de agora)" aria-label="Baixar ${PDM._esc(i.titulo)}" style="min-height:36px;padding:7px 10px;flex-shrink:0" onclick="event.stopPropagation();PDM.baixarVivo(this,${getToken})">${bx}</button>`
          : `<a class="btn" href="${i.url}" download title="Baixar" aria-label="Baixar ${PDM._esc(i.titulo)}" style="min-height:36px;padding:7px 10px;flex-shrink:0;text-decoration:none;display:inline-flex;align-items:center" onclick="event.stopPropagation()">${bx}</a>`}
      </div>`).join('');
  }
};
})();
