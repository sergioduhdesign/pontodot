// =====================================================================
// SD Workflow — A ARTE DO CLIENTE NÃO É DE GRAÇA
//
// Tira o botão direito, o arrastar e o "salvar imagem" das telas de
// aprovação. Vale dizer com todas as letras o que isto é e o que não é:
//
//   É  um limite de bom senso. Quem só queria arrastar a arte pra área
//      de trabalho, não arrasta. É o mesmo que a vitrine fechada à noite.
//   NÃO É segurança. Print da tela existe, e quem abre as ferramentas
//      do navegador acha o endereço. Nenhum site do mundo resolve isso.
//
// O que de fato protege é o endereço assinado do arte.php: sem ele,
// ninguém descobre a arte de outro cliente trocando um número na URL.
// =====================================================================
(function () {
  const alvo = e => e.target && e.target.closest('img, .arte, .peca, canvas, picture, [data-arte]');

  document.addEventListener('contextmenu', e => { if (alvo(e)) e.preventDefault(); });
  document.addEventListener('dragstart',  e => { if (alvo(e)) e.preventDefault(); });

  // No celular o "salvar imagem" vem de segurar o dedo. Este CSS corta
  // o menu do iOS e do Android sem atrapalhar o rolar da página.
  const css = document.createElement('style');
  css.textContent = `
    img, .arte img, canvas { -webkit-touch-callout:none; -webkit-user-drag:none;
      user-select:none; -webkit-user-select:none; }
    .arte, .peca { -webkit-touch-callout:none; }`;
  document.head.appendChild(css);

  // Ctrl/Cmd+S não baixa a página de aprovação inteira.
  document.addEventListener('keydown', e => {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') e.preventDefault();
  });
})();
