-- v136 — Pessoa nova entra pelo SISTEMA, sem SQL por pessoa.
-- O responsável da tarefa (tasks.resp) é o enum team_member, fechado no começo do projeto
-- (SERGIO, JOY, LUANA, JUAN, CLIENTE, OUTROS). Cadastrar gente nova dava a chave no perfil,
-- mas o enum recusava na hora de atribuir tarefa ("invalid input value for enum team_member").
-- Esta função acrescenta a chave no enum; a plataforma chama ela sozinha no cadastro,
-- em "Equipe e funções" e no admin. Rode UMA vez. Pode rodar de novo sem quebrar.

create or replace function public.pd_team_member_garante(k text)
returns boolean
language plpgsql
security definer
set search_path = public
as $$
declare
  papel text;
begin
  k := upper(trim(k));
  if k !~ '^[A-Z][A-Z0-9]{1,19}$' then return false; end if;
  -- só admin (logado) ou o servidor (chave secreta)
  if auth.role() is distinct from 'service_role' then
    select role into papel from public.profiles where id = auth.uid();
    if papel is distinct from 'admin' then raise exception 'só admin'; end if;
  end if;
  if not exists (
    select 1 from pg_enum e join pg_type t on t.oid = e.enumtypid
    where t.typname = 'team_member' and e.enumlabel = k
  ) then
    execute format('alter type public.team_member add value %L', k);
  end if;
  return true;
end
$$;
revoke all on function public.pd_team_member_garante(text) from public;
grant execute on function public.pd_team_member_garante(text) to authenticated, service_role;

-- quem já está cadastrado com chave própria e ainda não está no enum: entra agora
do $$
declare r record;
begin
  for r in select distinct upper(team_key) as k from public.profiles
           where team_key is not null and team_key ~ '^[A-Za-z][A-Za-z0-9]{1,19}$'
  loop
    if not exists (select 1 from pg_enum e join pg_type t on t.oid = e.enumtypid where t.typname = 'team_member' and e.enumlabel = r.k) then
      execute format('alter type public.team_member add value %L', r.k);
    end if;
  end loop;
end $$;
