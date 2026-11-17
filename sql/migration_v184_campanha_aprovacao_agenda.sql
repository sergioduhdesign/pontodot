-- Campanha: aprovação do plano pelo cliente + play/pausa agendados (rodar no Supabase, SQL Editor)
alter table public.pd_campanhas_lancadas add column if not exists aprovacao_set_id uuid;
alter table public.pd_campanhas_lancadas add column if not exists aprovacao text not null default 'rascunho';
alter table public.pd_campanhas_lancadas add column if not exists aprovacao_em timestamptz;
alter table public.pd_campanhas_lancadas add column if not exists play_em timestamptz;
alter table public.pd_campanhas_lancadas add column if not exists pausar_em timestamptz;
alter table public.pd_campanhas_lancadas add column if not exists agendada_por uuid;
alter table public.pd_campanhas_lancadas add column if not exists agenda_erro text;
alter table public.pd_campanhas_lancadas drop constraint if exists pd_camp_lanc_aprovacao_chk;
alter table public.pd_campanhas_lancadas add constraint pd_camp_lanc_aprovacao_chk check (aprovacao in ('rascunho','pendente','ajuste','aprovada'));
create index if not exists pd_camp_lanc_agenda_idx on public.pd_campanhas_lancadas (play_em) where play_em is not null;
