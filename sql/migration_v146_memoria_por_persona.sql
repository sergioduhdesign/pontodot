-- v146 — Memória em duas camadas: (1) o que a pessoa ensina fica POR PERSONA e só ela vê; (2) cada lição vira também um conhecimento GERAL anonimizado, usado por todas as IAs e invisível.
-- (antes) A memória "da pessoa" passa a ser POR PERSONA (IA). Zumi não sabe o que a Tumi aprendeu, e vice-versa.
-- O que é da casa (agência) e dos clientes continua comum. A palavra combinada continua da PESSOA (ia = null).
alter table public.sd_memoria add column if not exists ia text;
create index if not exists sd_memoria_pessoa_ia on public.sd_memoria (escopo, escopo_id, ia);

-- o que já existia da pessoa fica com a persona que ela usa hoje (menos palavra/senha, que é da pessoa)
update public.sd_memoria m set ia = lower(j.personagem)
  from public.pd_jeito j
 where m.escopo = 'pessoa' and m.ia is null and j.user_id::text = m.escopo_id::text
   and coalesce(j.personagem, '') <> ''
   and m.assunto !~* '(palavra|senha|segredo)';

-- escopo novo: 'geral' = lição anonimizada que todas as IAs usam e que não aparece em tela nenhuma
do $$
declare c record;
begin
  for c in select conname from pg_constraint where conrelid = 'public.sd_memoria'::regclass and contype = 'c' and pg_get_constraintdef(oid) ilike '%escopo%' loop
    execute format('alter table public.sd_memoria drop constraint %I', c.conname);
  end loop;
  alter table public.sd_memoria add constraint sd_memoria_escopo_chk check (escopo in ('agencia','cliente','pessoa','tarefa','geral'));
end $$;

select ia, count(*) as memorias from public.sd_memoria where escopo = 'pessoa' group by ia order by 2 desc;
