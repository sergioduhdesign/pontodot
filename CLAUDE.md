# Regras fixas da PD (leia antes de criar/alterar qualquer tela)

## Ferramenta aberta na home / no app (iframe, `html.emb`)
- Cabeçalho e botão de fechar (X) ficam SEMPRE **abaixo do menu do topo** da PD, nunca embaixo/ao lado dele. Use `<link rel="stylesheet" href="css/pd-ferramenta.css">` e a estrutura `.top` (título, `.fl1`, botões, `#btFechar`).
- X: sem bolinha/círculo, na linha do título, canto direito; ícones perigosos (excluir) longe do X. Um único padrão em toda a plataforma.
- O overlay da ferramenta usa z-index 40 (abaixo do menu do topo, z 41/400): clicar no menu fecha a ferramenta e segue.
- Botões importantes no TOPO da janela, nunca no rodapé. Barra de rolagem fina só quando há conteúdo para rolar.
- Caixas/gadgets da home: mesma estrutura das outras (avatar do cliente, o que é, clica e vê o detalhe), "Ver todas" no topo, download pronto em PDF/Excel.
- Subir `SD_VERSAO` (index.html) e o `?v=` dos scripts a cada entrega.
