# Escola das IAs — papéis e contrato (v217)

## Papéis (nunca misturar)
| Papel | Quem | Faz | Não faz |
|---|---|---|---|
| Produção da aula | PD | monta o exercício, a aluna (Claude) entrega, guarda cada versão, reconhece a correção | não corrige |
| Transporte | dot | recebe `[SD-IA] AULA · CORRIGIR`, leva aula/rodada/arquivos/histórico ao GPT do Sérgio (acha o lugar por CLIENTE › TIPO DE TRABALHO › O QUE FAZER), traz o parecer, grava `CORRECAO.json` | não julga, não escreve parecer, não substitui o professor |
| Professor | O GPT do Sérgio (o que o conhece) | problemas específicos, motivo, orientação, nota, lição sugerida | — |
| Aprovador | Sérgio | decisão final (aprovar / revisar / reprovar); edita a lição | — |

Modo `api` (GPT cru por chave) existe só como PILOTO explícito. Nunca é fallback silencioso.

## Fluxo
1. PD: exercício + versão da aluna → pasta no Drive (ENTRADA: `entrega-R<n>.png/.svg`, `aula.json`; raiz: `00_BRIEFING_IA.txt`, `00_FORMATO_CORRECAO.json`) + e-mail `[SD-IA] AULA · CORRIGIR · …`. A aula fica **AGUARDANDO**.
2. dot leva ao GPT (hierarquia CLIENTE › TIPO › O QUE FAZER vem no e-mail), recolhe o parecer.
3. Se o professor não respondeu: célula B2 = `AGUARDANDO-PROFESSOR`, **sem** CORRECAO.json. A aula continua aguardando.
4. Se respondeu: `SAIDA/CORRECAO.json` + B2 = `TERMINEI <chamado>`.
5. PD valida o contrato. Inválido → rejeita, escreve o motivo em B2, aula continua aguardando. Válido → registra o parecer (idempotente por rodada).
6. Nota ≥ mínima e `aprovado` → vai para a decisão do Sérgio. Senão a aluna refaz (nova rodada, nova versão, novo aviso).
7. Sérgio decide. Só depois disso a lição (editável por ele) entra no repertório. Lição `cliente` nunca vira regra geral; dado de cliente não entra no acervo comum.

Cadência: no máximo `chamados_dia` (padrão 5) avisos ao dot por dia; a PD só avança com fato novo (entrega ou parecer novo). Reenvio de aviso é manual.

## CORRECAO.json — contrato `pd-correcao/2`
```json
{
  "contrato": "pd-correcao/2",
  "aula_id": "<uuid da aula, igual ao do aula.json>",
  "rodada": 1,
  "chamado": "<id do último chamado, igual ao do aviso>",
  "origem": { "papel": "professor", "fonte": "chatgpt_projeto", "projeto": "<onde o dot encontrou o professor — opcional>", "transportado_por": "dot" },
  "parecer_do_professor": {
    "texto_do_professor": "<parecer do professor, copiado sem resumir>",
    "nota": 0,
    "aprovado": false,
    "problemas": [ { "problema": "", "motivo": "", "correcao": "" } ],
    "pontos_fortes": ""
  },
  "licao_sugerida": { "regra": "", "escopo": "habilidade|universal|cliente", "exemplo_bom": "", "exemplo_ruim": "" }
}
```
Rejeitado pela PD: contrato/aula_id/rodada/chamado que não batem; `origem.papel` ≠ `professor`; `origem.fonte` ≠ `chatgpt_projeto`; `texto_do_professor` com menos de 60 caracteres; nota fora de 0–100; reprovação sem ao menos um problema com `problema` + `motivo`.

## Rodada de teste ponta a ponta (trava)
Aula `modo = teste`: PD entrega → dot leva ao GPT → GPT analisa → dot devolve → PD reconhece → Sérgio confirma. Não conta para a formação, não cria nota nem lição. Só depois da confirmação (`pd_incubacao.teste_confirmado_em`) o treino automático pode ser ligado (a API recusa antes). Produção normal (`avisarIA`, `[SD-IA] PRODUZIR/CORRIGIR…`) não é tocada.

## Pilotos futuros (não aprovados)
O próprio dot como professor, comparando a crítica dele com a do Sérgio, é piloto separado.


## O envelope CORRECAO.json
É o mesmo para qualquer tipo de tarefa e quase não muda (versão `pd-correcao/2`). O que evolui são os critérios de cada curso e as lições aprovadas, não o envelope.

## Permissões (v113)
Por pessoa, em `pd_escola_perm`: matricular a própria IA · acompanhar (evolução, receita, marcos) · criar cursos. Admin tem tudo. Treino automático e setup só na página do admin.
