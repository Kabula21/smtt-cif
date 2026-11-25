# SMTT CIF Gestão 1.15.0 — Fast Route

- Despacho das rotas `/cif` antecipado de `template_redirect` para `init`, evitando o pipeline de query/template do WordPress que não é necessário para a aplicação CIF.
- Callbacks pesados do worker de sincronização registrados apenas em requisições WP-Cron.
- Indicador global passa a surgir somente após 450 ms, evitando feedback visual em navegações curtas.
- Mantidos banco dedicado, autorização, CSRF, sincronização automática e compatibilidade funcional.
