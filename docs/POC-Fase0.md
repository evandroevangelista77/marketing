# POC — Fase 0 do plugin Marketing (GLPI 11)

Base: `Requisitos_Plugin_GLPI11_Gestao_Marketing_Oakmont.docx` (v1.0, 09/11/2026).
Ambiente de validação: GLPI **11.0.11** de teste (`/var/www/glpi11test`).

## 1. Objetivo da POC

Responder às decisões que a especificação deixa para depois da POC (seção 4 e seção 8):

1. A demanda deve ser **chamado nativo** (`Ticket`) ou **objeto próprio** do plugin?
2. Projeto, tarefa e subtarefa podem ser as classes nativas (`Project`, `ProjectTask`)?
3. "Em risco" e "Bloqueada" são **status** ou **sinalizações independentes**?
4. Aprovação e aceite podem usar `TicketValidation` nativo?
5. SLA pode usar `SLM`/`SLA` nativos com calendário útil?

## 2. Mapeamento com as classes nativas do GLPI 11.0.11

Verificado no código-fonte do laboratório:

| Conceito da spec | Classe nativa encontrada | Observação |
|---|---|---|
| Demanda (RF-001) | `Ticket` / `CommonITILObject` | Estados nativos `INCOMING, ASSIGNED, PLANNED, WAITING, SOLVED, CLOSED` não cobrem os 11 estados do RF-007. Campos de briefing (público, entrega, prazo desejado) não existem. |
| Projeto (RF-005) | `Project` | Tem `priority`, `plan_start_date`, `real_end_date` e estados (`ProjectState`). Serve bem como agrupador. |
| Tarefa/subtarefa (RF-005, RF-010) | `ProjectTask` (com `parent`) | Tem `percent_done`, datas planejadas/reais, equipe (`ProjectTaskTeam`) e vínculo com chamado (`ProjectTask_Ticket`). |
| Aprovação (RF-013) | `TicketValidation` / `CommonITILValidation` | Estados `WAITING, ACCEPTED, REFUSED` e `VALIDATE = 1024`. Não tem alçada, versão nem evidência. |
| Comentários/histórico (RF-011) | `ITILFollowup`, `TicketTask`, log nativo | Cobre comentários e histórico de campos. |
| SLA (RF-015) | `SLA`, `SLM`, `Calendar` | Suporta calendário e feriados. Pausas e escalonamento por processo precisam de confirmação. |
| Anexos (RF-001) | `Document_Item` | Nativo, com permissões herdadas do item. |

## 3. Pontos já verificados

- **Versão:** GLPI 11.0.11 confirmado no arquivo `version/` do laboratório. PHP 8.5.4.
- **Plugins no lab:** `crm` e `vmo` já instalados no mesmo GLPI de teste; não há conflito de nome com `marketing`.
- **Padrão de referência:** plugin `VMO` (mesmo autor). Reutilizamos o padrão de `Base`, `Access`, `ProfileTab` e direitos com bits 1024+.

## 4. Modelo de dados proposto (objeto próprio)

Tabela `glpi_plugin_marketing_demands` (a ser validada na POC):

- Identidade: `id`, `entities_id`, `is_recursive`, `name`, `code` (ID único, RF-001).
- Solicitação: `requester_users_id`, `area`, `objective`, `audience`, `deliverable`, `context`, `desired_date`.
- Confirmação: `confirmed_date` e `sent_at`, separados do prazo desejado (RF-003, RN-01, CA-04).
- Fluxo: `status` (ver seção 5), `priority`, `users_id_assigned`, `users_id_approver`.
- Vínculos nativos: `projects_id` (classe `Project`) e `tickets_id` (opcional, para o caso de chamado nativo).

Recomendação preliminar: **objeto próprio** para a demanda, porque os 11 estados e os campos de briefing não cabem em `Ticket` sem distorcer o fluxo nativo. Projeto e tarefas continuam nativos, se o experimento de vínculo abaixo passar.

## 5. Estados de demanda (RF-007) e a dúvida da RN-08

Os 11 estados da spec estão em `src/Demand.php`. Para "Em risco" e "Bloqueada" a POC propõe a alternativa mais simples de testar:

- **Opção A (status):** um único campo `status`, com as 11 opções. Simples para o Kanban, mas perde a informação de que a demanda está bloqueada enquanto está em aprovação.
- **Opção B (flags):** `status` com 9 opções + `is_at_risk` e `is_blocked` independentes. Mais fiel à realidade, mas o Kanban precisa de uma regra para a cor.

A decisão final depende da validação com Marketing (RN-08). A POC implementa a opção A por padrão, para manter o código simples até a decisão.

## 6. Roteiro de experimentos (a executar no GLPI 11 de teste)

| # | Experimento | Critério de sucesso |
|---|---|---|
| E1 | Instalar o plugin no GLPI 11 de teste (`plugin:install`, `plugin:activate`) | Instala e cria a tabela sem erro |
| E2 | Aba "Marketing" em Administração → Perfis | Mostra a matriz de direitos para o perfil central |
| E3 | Criar demanda com campos obrigatórios vazios | `missingInputs()` lista os campos e o status não avança para "Planejada" (RF-004, CA-02) |
| E4 | Criar demanda completa | Gera código único e registra criação (CA-01) |
| E5 | Vincular demanda a um `Project` nativo e criar `ProjectTask` filha | Vínculo persiste e aparece no projeto (RF-005) |
| E6 | Usuário sem direito acessa a demanda por URL | Negado (CA-07) |
| E7 | Calcular SLA em dias úteis com `Calendar` | Prazo respeita feriados cadastrados (CA-10) |
| E8 | Aprovação com `TicketValidation` ou alçada própria | Decide se a aprovação é nativa ou própria (RF-013) |

## 6.1 Resultados (GLPI 11.0.11 de teste, 2026-11-09)

| # | Resultado |
|---|---|
| E1 | **Passou.** `plugin:install` e `plugin:activate` sem erro; plugin aparece como "Habilitado". Backup prévio: `/root/backup-glpi11test-pre-marketing-20261109-1655.sql.gz` (no servidor). |
| E2 | **Parcial.** Direito `plugin_marketing` gravado para 8 perfis; classe `ProfileTab` carregada. Falta conferir a aba visualmente em Administração → Perfis. |
| E3 | **Passou.** `missingInputs()` lista os faltantes; briefing incompleto não libera planejamento. |
| E4 | **Passou.** Demanda criada com código, prazo desejado separado de prazo confirmado, `date_creation` gravada; registro de teste removido. |
| E5 | **Passou.** Demanda aponta para `Project` nativo; tarefa e subtarefa (`ProjectTask` com `projecttasks_id`) no projeto. Dados de teste removidos. |
| E6 | **Parcial.** `Access::canView/canCreate/canUpdate` negam sem o bit e liberam só com leitura. **Não testado** o bloqueio por URL: o plugin ainda não tem páginas em `front/`. |
| E7 | **Passou.** `Calendar::computeEndDate` pula fim de semana e feriado cadastrado (via `Calendar_Holiday`). Observação: o delay é em segundos; `Calendar_Holiday` deve ser criado pela classe nativa, senão o cache de feriados fica desatualizado. |
| E8 | **Resultado de descoberta.** `TicketValidation::$itemtype` é fixo em `Ticket`, portanto aprovação nativa não serve a demanda de objeto próprio. Confirma a necessidade de aprovação própria (com alçada, versão e evidência). |

Testes: `tests/poc.php` (rodar com `sudo -u www-data env GLPI_ROOT=/var/www/glpi11test php plugins/marketing/tests/poc.php`).

Observação: em linha de comando o `Session::init` do GLPI falha por falta de sessão web; o teste simula o usuário e o perfil na sessão em vez disso.

## 7. Pendências que a POC não resolve

- Alçadas e matriz de aprovação (RN-04): exige reunião com donos do processo.
- Asana (RF-029): decisão de negócio, fora da POC.
- Versão de produção do GLPI 11 e infraestrutura de homologação (seção 13 da spec).
- Fórmula e origem dos indicadores (RF-024 a RF-027).

## 7.1 Decisões registradas (2026-11-09)

| # | Decisão | Base |
|---|---|---|
| D1 | **Demanda é objeto próprio** (`glpi_plugin_marketing_demands`), não `Ticket`. | Estados e campos de briefing não cabem em `Ticket` (seção 2); E4 passou. |
| D2 | **Projeto, tarefa e subtarefa são nativos** (`Project`, `ProjectTask`), vinculados à demanda por `projects_id`. | E5 passou. |
| D3 | **SLA usa o `Calendar` nativo**, com feriados via `Calendar_Holiday`. | E7 passou. |
| D4 | **Aprovação é própria** (alçada, versão e evidência). `TicketValidation` não serve a demanda. | E8: `$itemtype` fixo em `Ticket`. |
| D5 | **"Em risco" e "Bloqueada" continuam pendentes (RN-08).** Implementação provisória com opção A (status único). | Depende da validação com Marketing; não bloqueia a Fase 1. |

**Fase 0 concluída** com as pendências abaixo transferidas para a Fase 1:
- E2: conferir visualmente a aba em Administração → Perfis.
- E6: testar bloqueio por URL quando existirem páginas em `front/`.

## 8. Critério de saída da Fase 0

A POC está concluída quando E1 a E7 passam no GLPI 11 de teste, E8 tem decisão registrada, e a decisão sobre objeto próprio ou chamado nativo está escrita neste documento e aprovada pelo dono do produto.
