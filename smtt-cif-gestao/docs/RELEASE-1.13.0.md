# 1.13.0 — Fast Path

- Remove a verificação/regravação do WP-Cron de todo request `init`; o agendamento é mantido na ativação e apenas uma vez por versão.
- Evita varrer banco e migrations em todo `admin_init`; a checagem pesada roda somente após mudança de versão.
- Mantém sincronização automática e os contratos existentes.
- Mantém carregamento progressivo da interface da 1.12.0.
