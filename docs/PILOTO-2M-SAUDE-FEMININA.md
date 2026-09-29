# Piloto — @2msaudefeminina

O primeiro projeto real a publicar pelo sistema. Este guia vai da preparação à primeira publicação **autorizada**. Nada aqui publica sozinho: a troca de `INSTAGRAM_DRIVER=fake` para `graph`, a conexão da conta e a primeira aprovação são gestos humanos, feitos por quem responde pela marca.

---

## 1. O projeto (pronto)

```bash
php artisan pilot:2m-saude-feminina <workspace-id-ou-slug> --owner=<email-do-responsável>
```

Idempotente: rodar de novo não duplica nada e não sobrescreve o que foi editado na tela. Cria:

| O quê | Conteúdo |
|---|---|
| Projeto | "2M Saúde Feminina", fuso `America/Sao_Paulo` |
| Perfil da marca | Público, persona, tom de voz **com as regras de saúde e beleza**, palavras obrigatórias e proibidas, cores |
| Estratégia ativa | Linha editorial + 5 pilares (as categorias de conteúdo) |
| Calendário inicial | 12 ideias (1 mês, 3 por semana) na coluna **Ideia**, distribuídas pelos pilares |

No ambiente local já foi rodado no workspace "2M Negocios" da conta do responsável (projeto #6).

### Categorias (pilares)

| Pilar | Peso | O que entra |
|---|---|---|
| Educação em saúde | 35% | Ciclo, hormônios, fases da vida, sem jargão |
| Prevenção e exames | 25% | Check-up, papanicolau, mamografia, vacinas |
| Bem-estar e autocuidado | 20% | Sono, alimentação, movimento, saúde emocional — sem padrão estético |
| Mitos e verdades | 15% | Crenças comuns × consenso médico, com fonte |
| Bastidores e confiança | 5% | Quem produz e como (**a confirmar** o que pode ser mostrado) |

### Regras de conteúdo de saúde e beleza

Estão no `tone_of_voice` e nas `forbidden_words` do perfil — é o que o **copywriter** respeita ao escrever e o que o **revisor** cobra ao julgar.

- Informar e orientar, **nunca diagnosticar nem prescrever** (sem dose, sem "use o remédio X").
- **Nenhuma promessa** de resultado, cura ou prazo.
- Sintoma citado → orientar a procurar avaliação profissional.
- **Sem medo como gatilho.** Sem expor paciente ou caso identificável.
- Fontes reconhecidas (Ministério da Saúde, FEBRASGO, INCA, OMS); na dúvida, não publicar.
- Beleza é autocuidado, não padrão: **sem "antes e depois"**, sem "corpo perfeito", sem comparação.
- Expressões proibidas: *cura garantida, resultado garantido, 100% eficaz, milagre, milagroso, sem efeitos colaterais, tratamento definitivo, emagreça rápido, corpo perfeito, antes e depois, dispensa consulta, não precisa de médico*.

> Estas regras são um ponto de partida editorial, **não parecer jurídico**. Se o perfil for de um serviço médico, a publicidade segue as normas do conselho profissional (hoje, a Resolução CFM nº 2.336/2023 para médicos). Valide com quem assina o conteúdo antes do piloto.

### A confirmar com a marca (o sistema não inventa)

- [ ] Quem assina o conteúdo (nome, profissão, registro no conselho) — vai para a `description`
- [ ] Se há atendimento/serviço e quais (`services` está como "A CONFIRMAR")
- [ ] Site e outros canais
- [ ] Diferenciais reais do serviço
- [ ] Cores e identidade visual (as atuais são uma sugestão)
- [ ] O que pode aparecer em "Bastidores"

### Fluxo de revisão

```
Ideia ─▶ Produção ─▶ Revisão ─▶ Aprovado ─▶ Agendado ─▶ Publicado (o sistema)
          │            │           │
    copywriter    revisor IA    SÓ reviewer+ aprova (403 para editor)
    + imagem      aponta        texto e imagem congelam
    (biblioteca)  violações     a aprovação tem nome e hora
```

Para o piloto, recomendação: **duas pessoas** — quem escreve (`editor`) e quem aprova (`reviewer`, idealmente a profissional de saúde que assina). O sistema impede que o editor aprove.

**Cadência inicial sugerida:** 3 posts por semana (ter, qui, sáb). O horário é hipótese — as métricas reais do Instagram ainda não entram no sistema (fase opcional); comece por ter/qui 19h e sáb 10h e ajuste pela própria tela de insights do Instagram.

---

## 2. Publicações de teste (sem tocar o Instagram)

Com `INSTAGRAM_DRIVER=fake` (o padrão), o fluxo inteiro roda de verdade — conexão, aprovação, agendamento, fila, histórico — e o "Instagram" é simulado:

1. Tela **Instagram** do projeto → **Conectar Instagram** (conecta `@conta_de_teste`).
2. Suba uma imagem na **Biblioteca**; abra uma peça em Revisão → **Editar** → escolha a imagem → confira a prévia.
3. Como reviewer: **Avançar** (aprova). **Abrir** → agende para daqui a 2 minutos.
4. Com o `scheduler` e o `worker` rodando, em até um minuto a publicação aparece em **Publicações** como *Publicado*, com o id `fake-media-…`.
5. **Antes de conectar a conta real, desconecte a de teste.**

Foi assim que o fluxo foi verificado nesta retomada (ver `RETOMADA.md`, Fase 5).

---

## 3. App da Meta (pré-requisitos da conexão real)

Fluxo: **Instagram API with Instagram Login** (ADR-14). Passos no painel da Meta — os nomes dos menus mudam com frequência; confira na documentação atual:

1. **A conta @2msaudefeminina precisa ser profissional** (Empresa ou Criador de conteúdo). No app do Instagram: Configurações → Tipo de conta.
2. Em **developers.facebook.com**, crie um app e adicione o caso de uso de **API do Instagram com login do Instagram**.
3. Na configuração da API do Instagram, anote o **ID do app do Instagram** e a **chave secreta do app do Instagram** (são diferentes do ID do app do Facebook) → `INSTAGRAM_APP_ID` e `INSTAGRAM_APP_SECRET` no `deploy/vps/.env`.
4. Em **Configurações de login da empresa**, cadastre a URI de redirecionamento OAuth, **idêntica**: `https://2msocialai.site/api/v1/instagram/callback`.
5. Permissões usadas: `instagram_business_basic` e `instagram_business_content_publish` — nada mais.
6. **Papel no app:** adicione a conta @2msaudefeminina como testadora do Instagram (Funções do app) e aceite o convite no Instagram (Configurações → Apps e sites → Convites de testador). Para publicar **na própria conta**, com papel no app, o acesso padrão costuma bastar sem App Review — **confirme na documentação atual da Meta** antes de contar com isso. Publicar para contas de terceiros exige App Review e acesso avançado.
7. A imagem precisa estar numa URL **pública em HTTPS**: faça o teste do passo 5 do `DEPLOY-VPS.md` (abrir `/storage/...` numa janela anônima).

## 4. Conexão e primeira publicação real — só com autorização

Pré-condições: deploy da VPS concluído pelo checklist, backup rodado, a marca revisou o Perfil.

1. No `deploy/vps/.env`: `INSTAGRAM_DRIVER=graph` + ID e segredo do app → `docker compose up -d` (recria os containers com a variável nova).
2. Um **admin** abre a tela Instagram do projeto → **Conectar Instagram** → autoriza **logado como @2msaudefeminina**. O sistema recusa conta pessoal ou autorização sem a permissão de publicar, e diz o motivo.
3. **Primeira publicação:** uma peça só, revisada pela profissional, aprovada por ela, agendada para dali a 10 minutos, com alguém acompanhando a tela **Publicações** e o perfil no Instagram.
4. Conferir: o post está no perfil; o link da tela abre o post; `status.sh` mostra `published`.

## 5. Se algo der errado

| Sinal | O que fazer |
|---|---|
| Publicação **Falhou** com o motivo | Corrija (imagem, texto, reconectar) e use **Tentar de novo agora** |
| **Aguardando confirmação** | O sistema confere sozinho com a Meta. Se pedir decisão, olhe o perfil e clique **Está no ar** ou **Não saiu** — nunca republique antes de conferir |
| Aviso de conexão vencida | Reconectar (tela Instagram). As publicações agendadas voltam a sair; as que falharam no período, tentar de novo |
| Parar tudo agora | **Desconectar** na tela Instagram (nada mais sai), ou `INSTAGRAM_DRIVER=fake` + `docker compose up -d` |
| Revogar o acesso do app | No Instagram: Configurações → Apps e sites → remover o app |
