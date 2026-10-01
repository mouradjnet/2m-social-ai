# CP-04E — Versões não se apagam

Fecha o outro risco residual do CP-04C: o trigger de `content_versions` só recusava UPDATE. DELETE continuava possível por SQL direto ou pelo cascade da peça (e do projeto/workspace), e um TRUNCATE limpava o histórico inteiro.

## O que mudou

Migration `2026_10_01_020000`: dois triggers novos que reaproveitam a função `content_versions_imutavel()` do CP-04C.

| Tentativa | Antes | Agora |
|---|---|---|
| `DELETE FROM content_versions` | apagava | recusado |
| apagar a peça (cascade) | apagava as versões junto | recusado, a peça também fica |
| apagar projeto/workspace (cascade) | apagava tudo | recusado |
| `TRUNCATE content_versions` | limpava o histórico | recusado |

**Consequência:** peça, projeto ou workspace que tenha versões não pode mais ser apagado por nenhum caminho comum. Hoje nenhuma rota apaga essas linhas. Uma futura exclusão de conta (LGPD) terá de ser um caminho deliberado, que desligue o trigger de forma explícita e registrada. Um superusuário do banco ainda consegue desligar o trigger; isso fica fora do alcance da aplicação.

**Backup/restore:** o `restore.sh` usa `pg_restore --clean`, que faz DROP TABLE, e DROP não dispara esses triggers. Conferido com o PG16 local: dump do banco de dev restaurado num banco descartável, depois `--clean --if-exists --exit-on-error` por cima do banco já populado. Zero erros, 29 versões iguais às da origem, os 3 triggers presentes na cópia.

## Testes

- `ContentVersionsTest::test_versao_nao_se_apaga_nem_pelo_cascade_nem_por_truncate`: DELETE direto, cascade da peça, cascade do projeto e TRUNCATE, cada um num savepoint. Todos recusados, e a versão e a peça continuam lá. **Falsificado:** sem a migration, falha logo no DELETE direto.
- `PublishingTest::test_sem_imagem_sem_conta_ou_com_conta_vencida_nao_publica` apagava as peças para reaproveitar o cenário. Agora tira a peça antiga do agendamento em vez de apagá-la.

Gate: pint ok, `php artisan test` 615/615. Migration up → down → up no banco local.

## Riscos residuais

- Nenhum caminho de exclusão de conta/projeto existe. Quando for preciso, ver "Consequência" acima.
