-- v104 · Aulas na escola: material que a casa traz; a IA oferece à pessoa certa e acompanha.
alter table public.pd_skills add column if not exists tipo text not null default 'skill' check (tipo in ('skill','aula'));
alter table public.pd_skills add column if not exists conteudo text;        -- texto da aula (a IA ensina a partir dele)
alter table public.pd_skills add column if not exists link text;            -- vídeo/PDF/Drive, se houver
alter table public.pd_skills add column if not exists duracao_min int;
alter table public.pd_skills add column if not exists segmento text;        -- ex.: copy, campanha, design, atendimento, gestão

create table if not exists public.pd_skills_avisos (
  id        bigint generated always as identity primary key,
  skill_id  bigint not null references public.pd_skills(id) on delete cascade,
  user_id   uuid   not null,
  estado    text   not null default 'oferecida' check (estado in ('oferecida','aceita','concluida','agora_nao')),
  criado_em timestamptz not null default now(),
  atualizado_em timestamptz not null default now(),
  unique (skill_id, user_id)
);
alter table public.pd_skills_avisos enable row level security;
drop policy if exists pd_skills_avisos_ler on public.pd_skills_avisos;
create policy pd_skills_avisos_ler on public.pd_skills_avisos for select to authenticated using (user_id = auth.uid());
