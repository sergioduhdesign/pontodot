// ============================================================
// Pontodot — Edge Function "pd-maestro"  (v1)
//
// O MAESTRO: recebe um briefing em conversa normal e devolve um
// DEBRIEFING estruturado — as peças, quem produz cada uma (GPT cria,
// Claude organiza, humano cuida), prazos e a ordem (tarefas derivadas).
// O humano valida na tela; a execução (criar tarefas, pasta, play)
// é feita pela frente com a sessão de quem validou.
//
// Também conversa: "cliente novo, site em WP" → ele explica em
// português o que precisa e devolve o plano do conector.
//
// COLAR: Supabase → Edge Functions → criar "pd-maestro" → colar → Deploy.
// SECRETS: ANTHROPIC_API_KEY (obrigatória). Opcionais: SD_MAESTRO_MODEL
// (padrão claude-sonnet-4-5), SD_ALLOWED_ORIGIN.
// SUPABASE_URL/ANON_KEY o Supabase injeta sozinho.
// ============================================================

const CHAVE = Deno.env.get("ANTHROPIC_API_KEY") ?? "";
const SB_URL = Deno.env.get("SUPABASE_URL") ?? "";
const SB_API = Deno.env.get("SUPABASE_ANON_KEY") ?? "";
const MODEL = Deno.env.get("SD_MAESTRO_MODEL") ?? "claude-sonnet-4-5";
const CORS = {
  "Access-Control-Allow-Origin": Deno.env.get("SD_ALLOWED_ORIGIN") ?? "*",
  "Access-Control-Allow-Headers": "authorization, apikey, content-type, x-client-info",
  "Access-Control-Allow-Methods": "POST, OPTIONS",
};
const json = (d: unknown, s = 200) =>
  new Response(JSON.stringify(d), { status: s, headers: { ...CORS, "Content-Type": "application/json" } });

async function buscar(url: string, init: RequestInit = {}, ms = 20000) {
  const c = new AbortController();
  const t = setTimeout(() => c.abort(), ms);
  try { return await fetch(url, { ...init, signal: c.signal }); } finally { clearTimeout(t); }
}

// corpo com limite (ninguém manda 10 MB pro maestro)
async function lerCorpo(req: Request, max = 400_000): Promise<string> {
  const r = req.body?.getReader();
  if (!r) return "";
  let tam = 0; const partes: Uint8Array[] = [];
  for (;;) {
    const { done, value } = await r.read();
    if (done) break;
    tam += value.length;
    if (tam > max) { r.cancel(); throw new Error("grande demais"); }
    partes.push(value);
  }
  return new TextDecoder().decode(new Uint8Array(await new Blob(partes as BlobPart[]).arrayBuffer()));
}

const limpo = (s: unknown, n = 4000) =>
  String(s ?? "").replace(/[\u0000-\u0008\u000b-\u001f\u007f\u2028\u2029]/g, " ").slice(0, n);

// ------------------------------------------------------------------
// O CONTRATO DO DEBRIEFING (o que o maestro devolve, sempre JSON)
// ------------------------------------------------------------------
const REGRAS = `Você é o MAESTRO da pontodot, a plataforma da agência. Você recebe briefings em
linguagem normal (às vezes bagunçados, falados, colados de WhatsApp) e devolve um DEBRIEFING:
o plano de produção estruturado, pra um humano validar antes de qualquer execução.

QUEM FAZ O QUÊ (regra da casa):
- "gpt" produz CRIAÇÃO: design, artes, narrativa, textos de peça, legendas, layouts, vídeo.
- "claude" cuida de ORGANIZAÇÃO: estrutura, estratégia, pesquisa externa, agendamento,
  configuração (site, conector, esteira), análise.
- TODA peça tem um humano responsável (resp) que valida — use os nomes da equipe fornecida.
- Tarefas são DERIVADAS: briefing → conceito → peças → aprovação → publicação. Use depende_de
  (índice da peça anterior na lista) pra encadear. Nada começa antes do que alimenta ele.

PRAZOS: datas reais (AAAA-MM-DD), espaçadas com folga de produção e aprovação. Hoje é {HOJE}.
Prazo interno sempre antes do prazo do cliente.

TIPOS de peça: feed, carrossel, stories, reels, material (impresso/press/ooh), lp, site,
email_mkt, apresentacao, papelaria, blog, capa_site, pesquisa, config.

SITE DE CLIENTE: se o pedido envolve site novo ou conector (wp/html), preencha "site" com o
que você PRECISA em português simples (ex.: "endereço do site" e "uma senha de aplicativo do
WordPress — mando o passo a passo pro cliente"). Nunca peça a senha no chat: diga que o
formulário seguro da plataforma vai abrir pra colar.

RESPONDA SÓ com um JSON válido, nada antes nem depois, neste formato:
{
 "fala": "resposta curta e humana pro chat (pt-BR), resumindo o plano e o que falta",
 "resumo": "uma frase do que é o job",
 "cliente": "nome do cliente do cadastro, ou null",
 "cliente_novo": false,
 "pecas": [
   {"titulo":"...", "tipo":"feed", "produtor":"gpt|claude|humano", "resp":"NOME",
    "prazo":"AAAA-MM-DD", "prazo_cliente":"AAAA-MM-DD ou null", "detalhe":"o que é, espec, referência",
    "depende_de": null}
 ],
 "site": null,
 "perguntas": ["só o que IMPEDE começar; dúvida pequena vira hipótese registrada no detalhe"]
}
Peça o mínimo: na dúvida, assuma a melhor hipótese e registre no detalhe. Até 25 peças.`;

// ------------------------------------------------------------------
{
  Deno.serve(async (req) => {
    if (req.method === "OPTIONS") return new Response(null, { headers: CORS });
    if (req.method !== "POST") return json({ error: "só POST" }, 405);
    if (!CHAVE) return json({ error: "ANTHROPIC_API_KEY não configurada no Supabase." }, 500);

    let corpo: Record<string, unknown>;
    try { corpo = JSON.parse(await lerCorpo(req) || "{}"); }
    catch { return json({ error: "pedido inválido" }, 400); }

    // ---- quem chama (JWT de verdade + papel do banco)
    const auth = req.headers.get("authorization") ?? "";
    if (!auth.startsWith("Bearer ")) return json({ error: "sem sessão" }, 401);
    const uR = await buscar(`${SB_URL}/auth/v1/user`, { headers: { apikey: SB_API, authorization: auth } });
    if (!uR.ok) return json({ error: "sessão inválida" }, 401);
    const user = await uR.json();
    const pR = await buscar(
      `${SB_URL}/rest/v1/profiles?id=eq.${encodeURIComponent(user.id)}&select=id,name,role&limit=1`,
      { headers: { apikey: SB_API, authorization: auth } });
    const perfil = (await pR.json().catch(() => []))?.[0];
    if (!perfil || !["admin", "team"].includes(String(perfil.role))) {
      return json({ error: "só a equipe fala com o maestro" }, 403);
    }
    const lerUser = (path: string) =>
      buscar(`${SB_URL}/rest/v1/${path}`, { headers: { apikey: SB_API, authorization: auth } })
        .then((r) => (r.ok ? r.json() : [])).catch(() => []);

    const acao = String(corpo.acao ?? "debriefing");
    if (acao !== "debriefing") return json({ error: "ação desconhecida" }, 400);

    const texto = limpo(corpo.texto, 24000);
    if (!texto.trim()) return json({ error: "briefing vazio" }, 400);
    const debriefingId = typeof corpo.debriefing_id === "string" ? corpo.debriefing_id : null;

    // ---- contexto real: clientes, equipe, site do cliente (com a permissão do usuário)
    const [clientes, equipe] = await Promise.all([
      lerUser("clients?select=id,name,active&order=name&limit=200"),
      lerUser("profiles?select=name,team_key,client_id&limit=100"),
    ]);
    const nomesClientes = (clientes as Array<{ name?: string; active?: boolean }>)
      .filter((c) => c.active !== false).map((c) => c.name).filter(Boolean);
    const nomesEquipe = (equipe as Array<{ name?: string; team_key?: string; client_id?: string }>)
      .filter((p) => !p.client_id)
      .map((p) => (p.team_key || String(p.name ?? "").split(" ")[0] || "").toUpperCase())
      .filter(Boolean);

    // refinamento: traz o plano anterior e a conversa
    let anterior = null as Record<string, unknown> | null;
    if (debriefingId) {
      const d = await lerUser(`pd_debriefings?id=eq.${encodeURIComponent(debriefingId)}&select=briefing,debriefing&limit=1`);
      anterior = (d as Array<Record<string, unknown>>)[0] ?? null;
    }

    const hoje = new Date(Date.now() - 3 * 3600_000).toISOString().slice(0, 10); // Brasília
    const sistema = REGRAS.replace("{HOJE}", hoje)
      + `\n\nCLIENTES DO CADASTRO: ${nomesClientes.join(", ") || "(nenhum)"}`
      + `\nEQUIPE (use em resp): ${[...new Set(nomesEquipe)].join(", ") || "SERGIO"}`;

    const mensagens: Array<{ role: string; content: string }> = [];
    if (anterior) {
      mensagens.push({ role: "user", content: "BRIEFING ORIGINAL:\n" + limpo(anterior.briefing, 20000) });
      mensagens.push({ role: "assistant", content: JSON.stringify(anterior.debriefing ?? {}) });
      mensagens.push({ role: "user", content: "CORREÇÃO DO HUMANO (refaça o JSON inteiro aplicando isto):\n" + texto });
    } else {
      mensagens.push({ role: "user", content: "BRIEFING:\n" + texto });
    }

    // ---- Claude
    const aR = await buscar("https://api.anthropic.com/v1/messages", {
      method: "POST",
      headers: { "x-api-key": CHAVE, "anthropic-version": "2023-06-01", "content-type": "application/json" },
      body: JSON.stringify({ model: MODEL, max_tokens: 8000, system: sistema, messages: mensagens }),
    }, 90000);
    if (!aR.ok) {
      const e = await aR.text().catch(() => "");
      console.error("maestro: anthropic", aR.status);
      return json({ error: "O maestro não respondeu (" + aR.status + "). " + limpo(e, 200) }, 502);
    }
    const resp = await aR.json();
    if (resp.stop_reason && resp.stop_reason !== "end_turn") {
      return json({ error: "O maestro parou no meio (" + resp.stop_reason + "). Tente um briefing menor." }, 502);
    }
    const bruto = (resp.content ?? []).filter((b: { type: string }) => b.type === "text")
      .map((b: { text: string }) => b.text).join("\n");

    // JSON com paciência: acha o primeiro { ... } balanceado
    let plano: Record<string, unknown> | null = null;
    const ini = bruto.indexOf("{");
    if (ini >= 0) {
      let prof = 0, emStr = false, esc = false;
      for (let i = ini; i < bruto.length; i++) {
        const ch = bruto[i];
        if (emStr) { if (esc) esc = false; else if (ch === "\\") esc = true; else if (ch === '"') emStr = false; continue; }
        if (ch === '"') emStr = true;
        else if (ch === "{") prof++;
        else if (ch === "}") { prof--; if (!prof) { try { plano = JSON.parse(bruto.slice(ini, i + 1)); } catch { /* segue */ } break; } }
      }
    }
    if (!plano || !Array.isArray(plano.pecas)) {
      return json({ error: "O maestro devolveu um plano que não consegui ler. Tente de novo." }, 502);
    }

    // ---- validação dura do plano (nada de nome inventado)
    const TIPOS = ["feed","carrossel","stories","reels","material","lp","site","email_mkt","apresentacao","papelaria","blog","capa_site","pesquisa","config"];
    const equipeOk = new Set([...new Set(nomesEquipe)]);
    const pecas = (plano.pecas as Array<Record<string, unknown>>).slice(0, 25).map((p, i) => ({
      titulo: limpo(p.titulo, 140) || "Peça " + (i + 1),
      tipo: TIPOS.includes(String(p.tipo)) ? String(p.tipo) : "material",
      produtor: ["gpt","claude","humano"].includes(String(p.produtor)) ? String(p.produtor) : "humano",
      resp: equipeOk.has(String(p.resp ?? "").toUpperCase()) ? String(p.resp).toUpperCase() : (perfil.name ?? "SD").split(" ")[0].toUpperCase(),
      prazo: /^\d{4}-\d{2}-\d{2}$/.test(String(p.prazo)) ? String(p.prazo) : null,
      prazo_cliente: /^\d{4}-\d{2}-\d{2}$/.test(String(p.prazo_cliente)) ? String(p.prazo_cliente) : null,
      detalhe: limpo(p.detalhe, 2000),
      depende_de: Number.isInteger(p.depende_de) && (p.depende_de as number) >= 0 && (p.depende_de as number) < i ? p.depende_de : null,
    }));
    const clienteNome = nomesClientes.find((n) => String(n).toLowerCase() === String(plano!.cliente ?? "").toLowerCase()) ?? null;
    const clienteId = clienteNome
      ? (clientes as Array<{ id: string; name: string }>).find((c) => c.name === clienteNome)?.id ?? null
      : null;
    const debriefing = {
      resumo: limpo(plano.resumo, 300), cliente: clienteNome, cliente_novo: plano.cliente_novo === true,
      pecas, site: plano.site && typeof plano.site === "object" ? plano.site : null,
      perguntas: Array.isArray(plano.perguntas) ? (plano.perguntas as unknown[]).map((q) => limpo(q, 300)).slice(0, 8) : [],
    };

    // ---- grava (RLS do usuário) — refinamento atualiza a mesma linha
    const grava = debriefingId
      ? buscar(`${SB_URL}/rest/v1/pd_debriefings?id=eq.${encodeURIComponent(debriefingId)}`, {
          method: "PATCH",
          headers: { apikey: SB_API, authorization: auth, "content-type": "application/json", prefer: "return=representation" },
          body: JSON.stringify({ debriefing, status: "aguardando_validacao", atualizado_em: new Date().toISOString() }),
        })
      : buscar(`${SB_URL}/rest/v1/pd_debriefings`, {
          method: "POST",
          headers: { apikey: SB_API, authorization: auth, "content-type": "application/json", prefer: "return=representation" },
          body: JSON.stringify({ client_id: clienteId, criado_por: user.id, origem: limpo(corpo.origem, 20) || "chat",
            briefing: texto, debriefing }),
        });
    const gR = await grava;
    const linha = (await gR.json().catch(() => []))?.[0] ?? null;
    if (!gR.ok || !linha) {
      console.error("maestro: gravar", gR.status);
      return json({ error: "Montei o plano mas não consegui gravar (rodou o SQL da v62?)." }, 502);
    }

    return json({ ok: true, debriefing_id: linha.id, fala: limpo(plano.fala, 1200), plano: debriefing });
  });
}
