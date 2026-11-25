# Release estável 1.0.0

## Base

A versão 1.0.0 congela funcionalmente a 0.19.0. Não há nova migration nem alteração de regra de negócio nesta consolidação.

## Verificações executadas no pacote

- lint PHP em todos os arquivos PHP;
- `node --check` nos scripts JavaScript próprios;
- suíte `php tests/run.php`;
- consistência documental de rotas;
- presença de todas as permissões declaradas no catálogo de migrations;
- integridade do ZIP;
- comparação das migrations 001 a 024 com a 0.19.0.

## Limite da homologação automatizada

O ambiente de construção não substitui uma instalação WordPress real com MariaDB/MySQL, impressora, diretório privado e contas institucionais. Por isso, a homologação operacional final deve seguir `docs/HOMOLOGACAO-1.0.md` no ambiente da SMTT.

A sincronização com a internet permanece desabilitada até a instalação e homologação do plugin público.
