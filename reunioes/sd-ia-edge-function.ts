// ============================================================
// SD — Edge Function "sd-ia"
// O cérebro do "Fale comigo" do /novo/, com a chave NO SERVIDOR.
//
// COMO PUBLICAR (uma vez, no seu terminal):
//   supabase functions deploy sd-ia --project-ref ibkflplbraltewsjjsqm
//
// SECRET: a função procura a chave nesta ordem:
//   SD_IA_KEY  →  ANTHROPIC_API_KEY
// Se a sua secret no painel (Edge Functions → Secrets) tem OUTRO nome,
// me diz o nome que eu ajusto a linha abaixo — ou renomeia lá pra SD_IA_KEY.
//
// O /novo/ já chama esta função sozinho; enquanto ela não existir,
// ele responde com os dados do banco (atrasadas, resumo, criar tarefa).
// ============================================================
import "jsr:@supabase/functions-js/edge-runtime.d.ts";

const CHAVE = Deno.env.get("SD_IA_KEY") ?? Deno.env.get("ANTHROPIC_API_KEY") ?? "";

const cors = {
  "Access-Control-Allow-Origin": "*",
  "Access-Control-Allow-Headers": "authorization, apikey, content-type",
};

Deno.serve(async (req) => {
  if (req.method === "OPTIONS") return new Response("ok", { headers: cors });
  try {
    if (!CHAVE) throw new Error("Secret da IA não configurada (SD_IA_KEY).");

    // só gente logada no SD chega aqui
    const auth = req.headers.get("authorization") ?? "";
    if (!auth.startsWith("Bearer ")) {
      return new Response(JSON.stringify({ error: "sem sessão" }), { status: 401, headers: cors });
    }

    const { mensagem, tarefas } = await req.json();

    const sistema = `Você é a SD — a inteligência da casa do SD Workflow, agência de branding
imobiliário do Sérgio. Responde SEMPRE em português do Brasil, direto, caloroso e curto
(2 a 6 linhas), no tom da casa. Pode usar <b></b> e <br> no texto (vira HTML no chat).
Você recebe a lista de tarefas abertas em JSON: use-a para responder sobre status,
atrasos, clientes e prioridades. Hoje é ${new Date().toLocaleDateString("pt-BR")}.
Nunca invente tarefa que não está na lista. Se pedirem algo que você não faz daqui
(aprovar peça, mexer em arquivo), diga qual botão do SD faz isso.`;

    const r = await fetch("https://api.anthropic.com/v1/messages", {
      method: "POST",
      headers: {
        "x-api-key": CHAVE,
        "anthropic-version": "2023-06-01",
        "content-type": "application/json",
      },
      body: JSON.stringify({
        model: "claude-sonnet-4-5",
        max_tokens: 600,
        system: sistema,
        messages: [{
          role: "user",
          content: `TAREFAS ABERTAS:\n${JSON.stringify(tarefas ?? [], null, 0)}\n\nMENSAGEM DO USUÁRIO:\n${mensagem ?? ""}`,
        }],
      }),
    });

    const j = await r.json();
    if (!r.ok) throw new Error(j?.error?.message ?? "API da IA recusou");
    const reply = (j.content ?? []).map((c: { text?: string }) => c.text ?? "").join("");

    return new Response(JSON.stringify({ reply }), {
      headers: { ...cors, "content-type": "application/json" },
    });
  } catch (e) {
    return new Response(JSON.stringify({ error: String(e?.message ?? e) }), {
      status: 500,
      headers: { ...cors, "content-type": "application/json" },
    });
  }
});
