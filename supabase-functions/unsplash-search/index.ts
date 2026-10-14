// Edge Function: unsplash-search
//
// Proxy pra busca de imagens no Unsplash — a chave (Access Key) fica só
// aqui no servidor (secret), nunca é exposta no navegador. O front manda
// só o termo de busca e recebe de volta uma lista simplificada de fotos.
//
// Duas ações, via query string:
//   ?action=search&query=fachada+moderna&page=1   -> busca fotos
//   ?action=track&download_location=<url>         -> avisa o Unsplash que
//                                                     a foto foi usada de
//                                                     fato (exigido pelas
//                                                     regras deles de API)
//
// Deploy:
//   supabase functions deploy unsplash-search
//   supabase secrets set UNSPLASH_ACCESS_KEY=...

import { serve } from "https://deno.land/std@0.203.0/http/server.ts";

const CORS_HEADERS = {
  "Access-Control-Allow-Origin": "*",
  "Access-Control-Allow-Headers": "authorization, x-client-info, apikey, content-type",
};

serve(async (req) => {
  if (req.method === "OPTIONS") {
    return new Response("ok", { headers: CORS_HEADERS });
  }

  const authHeader = req.headers.get("Authorization");
  if (!authHeader) {
    return new Response(JSON.stringify({ error: "Sem autenticação." }), {
      status: 401,
      headers: { ...CORS_HEADERS, "Content-Type": "application/json" },
    });
  }

  const accessKey = Deno.env.get("UNSPLASH_ACCESS_KEY");
  if (!accessKey) {
    return new Response(
      JSON.stringify({ error: "UNSPLASH_ACCESS_KEY não configurada nesse ambiente." }),
      { status: 500, headers: { ...CORS_HEADERS, "Content-Type": "application/json" } }
    );
  }

  try {
    const url = new URL(req.url);
    const action = url.searchParams.get("action") || "search";

    if (action === "track") {
      // Unsplash exige avisar quando uma foto é efetivamente usada (não só
      // vista na busca) — dispara isso quando o usuário escolhe a imagem.
      const downloadLocation = url.searchParams.get("download_location");
      if (!downloadLocation) {
        return new Response(JSON.stringify({ error: "Falta 'download_location'." }), {
          status: 400,
          headers: { ...CORS_HEADERS, "Content-Type": "application/json" },
        });
      }
      await fetch(downloadLocation, {
        headers: { Authorization: `Client-ID ${accessKey}` },
      });
      return new Response(JSON.stringify({ ok: true }), {
        headers: { ...CORS_HEADERS, "Content-Type": "application/json" },
      });
    }

    const query = url.searchParams.get("query");
    const page = url.searchParams.get("page") || "1";
    if (!query) {
      return new Response(JSON.stringify({ error: "Falta o campo 'query'." }), {
        status: 400,
        headers: { ...CORS_HEADERS, "Content-Type": "application/json" },
      });
    }

    const resp = await fetch(
      `https://api.unsplash.com/search/photos?query=${encodeURIComponent(query)}&page=${page}&per_page=24&content_filter=high`,
      { headers: { Authorization: `Client-ID ${accessKey}` } }
    );

    if (!resp.ok) {
      const errText = await resp.text();
      throw new Error(`Unsplash API ${resp.status}: ${errText}`);
    }

    const data = await resp.json();
    const results = (data.results || []).map((p: any) => ({
      id: p.id,
      thumb: p.urls?.small,
      full: p.urls?.regular,
      download_location: p.links?.download_location,
      credit_name: p.user?.name,
      credit_url: p.user?.links?.html,
      description: p.alt_description || p.description || "",
    }));

    return new Response(JSON.stringify({ results, total_pages: data.total_pages || 1 }), {
      headers: { ...CORS_HEADERS, "Content-Type": "application/json" },
    });
  } catch (e) {
    console.error(e);
    return new Response(JSON.stringify({ error: String(e?.message || e) }), {
      status: 500,
      headers: { ...CORS_HEADERS, "Content-Type": "application/json" },
    });
  }
});
