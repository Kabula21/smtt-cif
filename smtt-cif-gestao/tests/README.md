# Testes do SMTT CIF - Gestão

A suíte básica não depende do WordPress nem de um banco ativo. Execute:

```bash
php tests/run.php
```

Cobertura atual inclui:

- protocolo automático e código `AJU##SEBR@@@@`;
- ciclo individual por agente/processo e sequencial global contínuo;
- migration de controle de ciclo e preservação de códigos históricos;
- token Base64URL de 256 bits;
- validação/máscara de CPF;
- transições principais da máquina de estados;
- autenticação e sessões próprias do CIF;
- perfis e autorização;
- CSRF próprio;
- gestão de usuários e troca de senha;
- auditoria e encadeamento SHA-256;
- arte CR80, qualidade biométrica e preview;
- descarte pré-emissão;
- sincronização da projeção pública;
- listagens e índices de performance;
- relatórios navegáveis;
- integração de ocorrências/recolhimentos ao histórico da emissão;
- consistência entre Router e controllers.

Os fluxos transacionais completos (MySQL/MariaDB, WordPress, upload, arte/impressão e sincronização HTTP) devem ser homologados no ambiente real por meio da tela **Diagnóstico** e do roteiro de homologação.
