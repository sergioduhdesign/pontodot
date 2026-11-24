# LEIA PRIMEIRO: toda tela/janela nova ou alterada segue o padrão, sem exceção
Antes de escrever uma linha de tela (ferramenta, janela, "Sala", "Gerenciar", painel admin, qualquer `.html` aberto dentro da PD):
1. Abra `seo.html` (moldura: `html.emb`, `.folha`, `.top` com seta `#btVolta`, `fecha()` por `postMessage`) e `abrirSeo()`/`fecharSeo()` em `index.html` (véu z-index 40, iframe, mensagem `x-fechar`, clique no menu do topo fecha).
2. COPIE esse par para a tela nova (trocando ids/mensagem). Nunca crie overlay próprio, botão "‹ Voltar" solto, X no canto, nem página com cabeçalho diferente. Exemplo de erro já cometido: `abrirSala()` com overlay z 9400 e "Voltar" próprio (corrigido na v620).
3. Se a tela já existe e só ganhou botão/seção, confira que o jeito de ABRIR também está no padrão.
4. Antes de entregar: lista de verificação abaixo (cabeçalho com seta, caixa branca no desktop, escuro no celular, sem X, Escape fecha, z 40, botões no topo, sem emoji, `SD_VERSAO` subida).
Quem refaz por não seguir isso gasta tempo e crédito do Sérgio: na dúvida, leia o modelo de novo.

# Regras fixas da PD (leia antes de criar/alterar qualquer tela)

## Ferramenta aberta na home / no app (iframe, `html.emb`)
- ESTRUTURA PADRÃO (não é X no canto): **caixa branca grande, central, com tudo dentro** (`.folha`), começando ABAIXO do menu do topo da PD (~68px), com a **seta de voltar** à esquerda do título (na primeira tela a seta fecha a ferramenta; nas internas volta um nível). Sem botão X de fechar. Escape também fecha. Modelo: `seo.html`.
- Desktop = caixa branca; celular (app) = tudo escuro, folha em tela cheia.
- O overlay da ferramenta usa z-index 40 (abaixo do menu do topo, z 41/400): clicar no menu fecha a ferramenta e segue.
- PDF: SEMPRE pelo `pdfDoc` do index.html (identidade da PD + logo do cliente + avatares, Baixar/Compartilhar/Copiar link, material de tela em wide 1920×1080, lista em A4). Nunca janela de impressão própria.
- Botões importantes no TOPO da janela, nunca no rodapé. Barra de rolagem fina só quando há conteúdo para rolar.
- Caixas/gadgets da home: mesma estrutura das outras (avatar do cliente, o que é, clica e vê o detalhe), "Ver todas" no topo, download pronto em PDF/Excel.
- Subir `SD_VERSAO` (index.html) e o `?v=` dos scripts a cada entrega.

- Sem emoji/ícone colorido (cadeado, 3D, desenhinho) nos rótulos e botões: texto limpo no estilo do site.
