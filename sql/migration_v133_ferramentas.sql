-- v133 · Ferramentas do Studio liberadas por pessoa (Copys é de todos; o admin libera as outras 4)
-- Valores: digital, audio, video, grafica. Pode rodar mais de uma vez.
alter table public.profiles add column if not exists studio_areas text[] default '{}';
