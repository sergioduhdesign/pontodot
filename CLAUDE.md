# Regras fixas da PD (leia antes de criar/alterar qualquer tela)

## Ferramenta aberta na home / no app (iframe, `html.emb`)
- ESTRUTURA PADRÃO (não é X no canto): **caixa branca grande, central, com tudo dentro** (`.folha`), começando ABAIXO do menu do topo da PD (~68px), com a **seta de voltar** à esquerda do título (na primeira tela a seta fecha a ferramenta; nas internas volta um nível). Sem botão X de fechar. Escape também fecha. Modelo: `seo.html`.
- Desktop = caixa branca; celular (app) = tudo escuro, folha em tela cheia.
- O overlay da ferramenta usa z-index 40 (abaixo do menu do topo, z 41/400): clicar no menu fecha a ferramenta e segue.
- PDF: SEMPRE pelo `pdfDoc` do index.html (identidade da PD + logo do cliente + avatares, Baixar/Compartilhar/Copiar link, material de tela em wide 1920×1080, lista em A4). Nunca janela de impressão própria.
- Botões importantes no TOPO da janela, nunca no rodapé. Barra de rolagem fina só quando há conteúdo para rolar.
- Caixas/gadgets da home: mesma estrutura das outras (avatar do cliente, o que é, clica e vê o detalhe), "Ver todas" no topo, download pronto em PDF/Excel.
- Subir `SD_VERSAO` (index.html) e o `?v=` dos scripts a cada entrega.
