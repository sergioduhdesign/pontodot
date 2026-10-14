# Pontodot — o código da plataforma

Este repositório é a FONTE do que roda em sd.sergioduh.com.

## Regras de casa
- Código muda SÓ por aqui. Push na `main` = o robô publica no servidor em ~2 min (aba Actions).
- Nunca subir nada por FTP na mão: o robô sobrescreve na próxima publicação.
- O que é só do servidor (sd-private/, pub-midia/, .cache-*) o robô NUNCA toca.
- Segredos não entram no repositório. Chaves moram no sd-private/sd-config.php (servidor) e nos Secrets (GitHub/Supabase).
- `index.html` da raiz É a frente (o antigo novo/index.html). `app.html` é o verso.
- SQL de migração: pasta sql/ (bloqueada pra web). Apps Script da ponte: pasta ponte/ (idem).
