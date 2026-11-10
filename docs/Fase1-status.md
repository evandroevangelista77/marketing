# Fase 1 (MVP) — situação em 2026-11-09

Ambiente: GLPI 11.0.11 de teste (`/var/www/glpi11test`). Código: `Plugins/Marketing-GLPI`.

## Entregue

| Requisito | Situação | Onde |
|---|---|---|
| RF-001 cadastro com código único e solicitante | Feito (sem anexos e sem dependências) | `front/demand.php`, `front/action.php` |
| RF-003 prazo desejado × confirmado | Feito | `desired_date`, `confirmed_date` |
| RF-004 bloqueio sem briefing | Feito | `Demand::transitionError()` |
| RF-004 complemento pelo solicitante | Feito: o solicitante edita o briefing enquanto a demanda está em "Aguardando solicitante" | `Demand::canComplement()` |
| RF-007 onze estados e transições | Feito | `Demand::TRANSITIONS` |
| RF-008 prioridade e responsável | Feito (responsável obrigatório para planejar) | tela de triagem |
| RF-009 lista com filtro | Lista feita; Kanban não | `front/demands.php` |
| RF-011 trilha | Parcial: eventos de situação, aceite, evidência, aprovação e complemento (`glpi_plugin_marketing_events`); histórico nativo de campos quando a demanda é atualizada pelo CommonDBTM. Falta comentário | |
| RF-012 evidências e aceite antes de concluir | Feito: links e notas (`glpi_plugin_marketing_evidences`); aceite do solicitante | `Demand::addEvidence()`, `Demand::acceptDelivery()` |
| RF-013 aprovação própria (D4) | Feito: aprovador indicado na demanda, decisão registrada com data e comentário (`glpi_plugin_marketing_approvals`). Sem alçada por valor nem versão | `Demand::requestApproval()`, `Demand::decide()` |
| RN-05 conclusão com exceção justificada | Feito: conclusão exige evidência e aceite; exceção só com direito de aprovação e justificativa | `Demand::completeWithException()` |
| RF-024 dashboard operacional | Feito: entradas no mês, em aberto, atrasadas, bloqueadas, concluídas, canceladas, em triagem, aguardando solicitante, e lista de atrasadas | `front/dashboard.php` |
| Menu "Marketing" e aba em Perfis | Feito | `setup.php`, `src/ProfileTab.php` |

## Pendente na Fase 1

- **RF-016 e-mails** (atribuição, atraso, complemento, conclusão). O laboratório não tem SMTP: planejar uma fila de avisos.
- **RF-009 Kanban** e **RF-011 comentários**.
- **RF-001 anexos** via `Document_Item` nativo.
- **RF-013 alçada por valor e versão** da aprovação; RN-07 (reabrir validação após mudança substancial).
- **Tempo por etapa** no dashboard (RF-024 pede; hoje só há contagens).
- **E2 visual**: conferir a aba "Marketing" em Perfis no navegador.
- **E6 por URL**: testar `demand.php?id=N` com usuário sem direito, por login real no navegador. Os testes cobrem os métodos de item.
- **RN-08** (Em risco e Bloqueada) segue provisória.

## Riscos e limitações conhecidas

- **Visibilidade:** a lista mostra todas as demandas da entidade para quem tem leitura. A especificação pede que aprovadores vejam só o que lhes foi atribuído (CA-07, RNF-02). Precisa de regra por responsável ou solicitante.
- **Exceção persistente:** `exception_note` fica gravada na demanda. Se ela for reaberta e concluída de novo, a regra de evidência e aceite não é reaplicada. Corrigir ao implementar a RN-07.
- **Gravação direta:** a mudança de situação grava no banco direto (sem o `update` do CommonDBTM), porque o aprovador pode não ter o direito de atualização. As permissões são verificadas nas telas (`front/action.php`).
- **Páginas sem teste de tela:** `front/*.php` passaram só por `php -l` no laboratório. Não foram abertas no navegador.

## Validação

- `tests/poc.php` (E1 a E8): passa.
- `tests/fase1.php` (regras de transição, persistência, trilha, direito por item): passa.
- `tests/fase1b.php` (evidências, aceite, aprovação, exceção): passa, 24 verificações.
