# Retomada — setembro de 2026

Diário da retomada que transforma o planejador de conteúdo em publicador para o
Instagram. Cada fase registra o que foi encontrado e o que foi verificado.

---

## Fase 0 — Estado inicial (2026-09-28)

**Repositório:** `main` limpo e sincronizado com `origin/main`, último commit `1cacc2b`.

**Banco local:** o cluster 16 na porta 5433 estava de pé, mas o `pg_ctl status`
dizia o contrário e caiu no meio da primeira execução da suíte. Um `pg_ctl start`
disparado por um shell que depois se encerra leva o servidor junto. Suba-o
desacoplado:

```powershell
Start-Process C:\Users\mysho\bin\pgsql16\bin\pg_ctl.exe -ArgumentList 'start','-D','C:\Users\mysho\pgdata\16','-l','C:\Users\mysho\pgdata\pg16.log' -WindowStyle Hidden
```

**Migrations:** o banco de desenvolvimento estava três migrations atrás
(`add_timezone_to_projects`, `index_one_active_strategy_per_project`,
`add_rewriter_to_ai_runs_agents`). Aplicadas com `php artisan migrate` — só
acrescentam coluna, índice e valor de enum; nenhum dado apagado.

**Suítes:**

| | Resultado |
|---|---|
| PHPUnit (Postgres real) | 309 testes, 782 asserções, verdes (76 s) |
| vitest | 109 testes em 15 arquivos, verdes (o README dizia 88) |
| `tsc -b` | sem erros |
| `pnpm build` | ok |

**Fumaça:** `php artisan serve` responde `/up` 200, a SPA responde nos deep links,
o login devolve 422 com credencial errada, `queue:work --stop-when-empty` roda sem
jobs presos e `failed_jobs` está vazio.

**IA:** `AI_PROVIDER=mock` local. O modelo padrão `claude-opus-4-8` continua
válido e com o mesmo preço do Opus 5. Migrar para `claude-opus-5` muda o
comportamento de thinking (ligado por padrão) e fica como recomendação separada,
fora desta retomada.

**Nada impeditivo encontrado.** A única pendência de ambiente é o Postgres local,
que não é serviço e precisa ser iniciado à mão.

---

## Fase 1 — ADR-13 (2026-09-28)

"A IA propõe, o humano aprova e o sistema publica" — ver
[ARCHITECTURE.md](ARCHITECTURE.md#adr-13--a-ia-propõe-o-humano-aprova-e-o-sistema-publica).
Aprovar passou a exigir `reviewer`+ (antes qualquer editor aprovava, contra o
ADR-01), ganhou `approved_by`/`approved_at` e o `PublishGate` recusa peça cuja
aprovação é mais velha que a última mudança de texto ou imagem.

---

## Fase 2 — Biblioteca de imagens (2026-09-28)

**Onde as imagens moram.** A Meta não recebe o arquivo: ela *busca* a imagem numa
URL pública na hora de publicar. O disco precisa sobreviver a deploys e servir por
HTTPS.

| Opção | A favor | Contra |
|---|---|---|
| **Volume Docker na VPS, servido pelo Nginx** ✅ | Zero custo novo, zero dependência, backup junto do banco | O disco da VPS é o limite; se a VPS cair, a Meta não busca |
| Cloudflare R2 | Sem custo de saída, CDN | Mais uma conta e chave; pacote `league/flysystem-aws-s3-v3` (+ AWS SDK) |
| AWS S3 | Padrão de mercado | Custo de saída; mesma dependência |

**Decidido:** volume persistente na VPS (`MEDIA_DISK=public`). O código só fala com
`Storage::disk(config('media.disk'))`: migrar para R2 é instalar o adaptador S3,
declarar o disco e trocar a variável — nenhuma linha de domínio muda.

**Validação no upload, não na publicação.** JPEG/PNG/WebP até 8 MB, largura ≥ 320 px,
proporção entre 4:5 e 1.91:1. Tudo é reencodado para JPEG (o único formato que a
API aceita para imagem), reduzido a 1440 px de largura e sai sem EXIF. O nome do
arquivo é um uuid — a URL é pública, então não pode ser adivinhável.

**Remoção segura.** Imagem presa a peça aprovada, agendada ou publicada não sai (409).
Presa só a rascunhos, sai e os rascunhos ficam sem imagem. Trocar a imagem de uma
peça grava revisão com o de-para e só vale antes da aprovação.

---

## Fase 3 — Integração oficial com o Instagram (2026-09-28)

Fluxo escolhido: **Instagram API with Instagram Login** — ver ADR-14.

- `InstagramGateway` com dois adaptadores: `GraphInstagramGateway` (Meta) e
  `FakeInstagramGateway` (padrão; conecta e publica sem sair da máquina).
- OAuth: `POST /projects/{id}/instagram:connect` (admin+) devolve a URL da Meta; a
  Meta volta em `GET /api/v1/instagram/callback`, que confere o `state`, troca o
  code, pega o token longo, exige os dois escopos e conta profissional, e grava a
  conta. `DELETE /projects/{id}/instagram` desconecta (apaga o token, guarda a linha).
- Renovação diária às 03:17 (`instagram:refresh-tokens`).
- Toda falha da Meta sai classificada: `transient`, `permanent`, `auth` ou
  `unknown` — este último só no `media_publish`, onde a Meta pode ter publicado
  mesmo sem responder.

---

## Fase 4 — Publicação automática (2026-09-28)

**O ciclo.** O agendador roda `publications:dispatch` a cada minuto. Toda peça do
Instagram agendada cuja hora chegou vira uma linha em `publications` — `pending`,
ou já `failed` com o motivo se alguma porta estiver fechada (sem aprovação, texto
mudado depois dela, sem imagem, sem conta, token vencido, legenda acima de 2200,
horário perdido há mais de 12 h). O `PublishJob` roda na fila `publishing`, só dela.

**Idempotência, em três camadas:**
1. Uma publicação por peça **por horário** (índice único). Rodar o agendador duas vezes não cria duas.
2. Nunca duas publicações **vivas** da mesma peça (índice único parcial).
3. Reivindicação atômica: `UPDATE ... SET status='publishing' WHERE status IN (pending, unknown)`. Dois workers com o mesmo job: um passa, o outro não faz nada.

**Resultado desconhecido não se repete às cegas.** Timeout ou 5xx no
`media_publish` deixa a publicação `unknown`. Dois minutos depois o sistema
pergunta o estado do container: `PUBLISHED` → conclui (acha a mídia pela legenda
entre as recentes), `FINISHED` → a Meta não publicou e é seguro publicar, `EXPIRED`
→ novo container. Worker morto no meio (`publishing` há mais de 10 min) vira
`unknown` e segue o mesmo caminho. Se nada decidir, um reviewer confere o perfil e
decide à mão (`resolve`).

**Falhas.** Transitórias (limite de taxa, cota de 24 h, 5xx antes de publicar)
tentam de novo em 1, 5, 15, 30 e 60 min e depois falham. Permanentes (imagem
recusada) falham na hora. Token recusado falha e marca a conta como `expired`.
Cada conversa com a Meta fica em `publication_attempts`.

**Tentar de novo** = remarcar a peça para agora: abre uma publicação nova e a que
falhou continua no histórico.

**Fuso.** Corrigido um defeito anterior: a hora sem fuso vinda da tela
(`<input datetime-local>`) era lida como UTC e o post remarcado para 18:30 sairia
às 15:30 em São Paulo. Agora é lida no fuso do projeto — o teste que fixava o
comportamento antigo foi corrigido junto.

---

## Fase 5 — Interface administrativa (2026-09-28)

Telas novas, no mesmo design system (tokens do `@theme`, `Card`/`Button`/`Input`):

| Onde | O quê |
|---|---|
| `/projects/{id}/instagram` | Conectar/desconectar (com confirmação em dois cliques), validade da autorização, resultado do OAuth; histórico com indicadores, link do post, aprovador, erro, "Tentar de novo agora" e a decisão humana ("Está no ar"/"Não saiu") para resultado desconhecido |
| `/projects/{id}/library` | Upload com o motivo da recusa, grade com dimensões/tamanho/uso, remoção com confirmação |
| Conteúdo → **Editar/Abrir** | Editor de publicação: título, legenda, CTA, hashtags (contador 30), escolha da imagem e **prévia do post** com contador de 2200 caracteres. Aprovada: somente leitura, mostra quem aprovou e agenda no fuso do projeto |
| Quadro | Coluna **Publicado**, miniatura, "Aprovada por", chip do estado no Instagram; o erro do gesto (403 ao aprovar sem ser revisor, 409 com publicação em andamento) aparece em vez de sumir |
| Conteúdo e Calendário | Alerta de conexão: ausente, vencida ou vencendo em menos de 7 dias |

Backend que a interface pediu: `PATCH /contents/{id}/draft` (edição do texto só antes
da aprovação, com revisão de-para).

**Verificado no navegador** (driver `fake`, usuário descartável num workspace
separado): conectar → aprovar como dono → agendar para dali a 2 min → o
`publications:dispatch` criou a publicação e o `queue:work --queue=publishing,default`
publicou → histórico mostrou "Publicado" com mídia e aprovador. A hora 20:48
digitada foi gravada como `20:48-03`.

Dois defeitos achados nessa verificação e corrigidos: o callback do OAuth devolvia
um `Location` absoluto com o host interno (atrás do proxy do Vite — e seria o mesmo
atrás do Nginx); agora é relativo. E uma publicação que já teve resultado
desconhecido podia virar `failed` se a própria conferência falhasse várias vezes,
o que ofereceria "tentar de novo" sobre um post possivelmente no ar; agora ela fica
`unknown` até um humano decidir.
