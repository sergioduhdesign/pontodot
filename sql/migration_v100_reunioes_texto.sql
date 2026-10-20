-- v100 — Espelho das reuniões (Read.ai): o TEXTO INTEIRO no Supabase, com o cliente identificado.
-- Rode INTEIRO no Supabase → SQL Editor → Run. Esperado: "Success". Não apaga nada.

create table if not exists public.reunioes_texto (
  file_id        text primary key,            -- id do Doc do Read.ai no Drive
  titulo         text not null,
  pasta          text,                        -- subpasta onde a nota está
  reuniao_em     date,                        -- data (AAAA-MM-DD do título, ou a de modificação)
  cliente        text,                        -- nome do cliente identificado (ou null)
  client_id      uuid,                        -- id do cliente identificado (ou null)
  como_achou     text,                        -- titulo | conteudo | ia | nao_achou
  url            text,
  texto          text not null,               -- a transcrição/nota INTEIRA, sem resumo
  caracteres     integer,
  modificado_em  timestamptz,                 -- última modificação no Drive (pra saber se mudou)
  sincronizado_em timestamptz not null default now()
);
create index if not exists reunioes_texto_data_idx    on public.reunioes_texto (reuniao_em desc);
create index if not exists reunioes_texto_cliente_idx on public.reunioes_texto (client_id);

alter table public.reunioes_texto enable row level security;
-- equipe lê tudo; cliente só as reuniões do cliente dele; ninguém escreve (só o servidor)
drop policy if exists reunioes_texto_ler on public.reunioes_texto;
create policy reunioes_texto_ler on public.reunioes_texto for select to authenticated using (
  exists (select 1 from public.profiles p where p.id = auth.uid() and p.role in ('admin','team'))
  or exists (select 1 from public.profiles p where p.id = auth.uid() and p.role = 'client'
             and p.client_id is not null and p.client_id = reunioes_texto.client_id)
);

-- a leitura passa a rodar a cada 20 minutos (antes: 4 vezes ao dia)
select cron.alter_job((select jobid from cron.job where jobname = 'sd-reunioes-4x'), schedule := '*/20 * * * *');
