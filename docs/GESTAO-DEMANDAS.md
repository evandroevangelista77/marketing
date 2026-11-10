# Gestão de Demandas (plugin `marketing`) — v0.3.0

Plugin para GLPI 11 e 12 que cobre o ciclo de demandas de Marketing, na seção **Marketing** do menu lateral.
Base: `Requisitos_Plugin_GLPI11_Gestao_Marketing_Oakmont.docx` v1.0, com recursos inspirados no monday.
Decisões da POC (objeto próprio para a demanda, aprovação própria, calendário nativo para SLO): `POC-Fase0.md`.

## Menu "Marketing"

| Item | O que faz |
|---|---|
| Painel de Demandas | Visão executiva: filtros de data (atalhos 7 dias a Tudo), tipo, responsável, situação e área; 10 cartões com **variação sobre o período anterior** (mesma duração) e minigráfico; gráficos em Apache ECharts (a biblioteca que o GLPI já traz): situação (rosca), entradas × concluídas por semana, **funil de conversão** (recebidas → triadas → planejadas → em execução → concluídas), **tempo médio por etapa** com o maior gargalo, envelhecimento das abertas, placar de desempenho (entregas no prazo, SLOs cumpridos, taxa de conclusão), tipo, prioridade e carga por responsável; cartões e gráficos se ajustam à largura da tela; clique abre a gaveta lateral com as demandas; botão **Exportar PDF executivo** (com uma página de gráficos) |
| Demandas | Lista com busca, filtros e paginação |
| Quadro Kanban | Duas visões nas abas do topo (a escolha fica salva por usuário): **Tabela principal** no estilo monday (grupos por mês do prazo, Resp., Status editável direto na célula, Timeline de progresso, Prazo e Prioridade em estrelas) e **Kanban** (colunas por situação, cartões arrastáveis, mesmas regras da ficha) |
| Configurações | Só com o direito **Configurar**: demandas e tipos, status e cores, SLOs, notificações, automações |

## Pop-up com som na atribuição

- Ao atribuir uma demanda (na triagem, por automação ou pelo responsável padrão), quem recebe vê um **pop-up no canto inferior direito** em qualquer página do GLPI, com link para a demanda.
- O som é gerado pelo navegador (dois tons curtos, sem arquivo). Navegadores só liberam áudio depois do primeiro clique na página; se o aviso chegar antes, o pop-up aparece e o som toca no primeiro toque. O sino do pop-up silencia (fica salvo no navegador).
- O navegador consulta `front/alerts.php` a cada N segundos (padrão 20; configurável). Cada aviso é entregue uma vez.
- Quem executa a ação não recebe aviso dela. Pop-up e som podem ser desligados em Configurações → Notificações, por evento.
- Eventos: atribuída, aprovação solicitada, aprovação decidida, situação alterada, complemento solicitado, nova atualização, SLO vencido (cada um com canal de pop-up e de e-mail).

## Configurações

- **Demandas e tipos**: campos obrigatórios do briefing (geral e por tipo), responsável padrão da triagem, tipos (Campanha, Evento, Comunicação, Conteúdo, GTM, CRM e inteligência) com cor.
- **Status e cores**: cor de cada um dos 11 estados (Kanban, painel e PDF).
- **SLOs**: tempo entre entrar num estado e chegar a um estado final, em dias úteis, por calendário do GLPI (feriados), com escalonamento. Os três SLOs iniciais vêm do briefing e **nascem desligados** até a homologação (RF-017).
- **Notificações**: pop-up, som, intervalo, matriz evento × canal, fila de e-mails.
- **Automações** (estilo monday): "quando [gatilho] então [ação]". Gatilhos: criada, atribuída, situação alterada, aprovação solicitada, SLO vencido. Ações: avisar com pop-up, atribuir, definir prioridade, registrar atualização. Três receitas de exemplo, desligadas.

## Recursos inspirados no monday

Quadro Kanban arrastável, atualizações (feed de comentários por demanda), automações "quando/então", status coloridos configuráveis, filtros "Só minhas", painel com cartões clicáveis.

## Direitos (Administração → Perfis → aba "Gestão de Demandas")

Ler, Criar, Atualizar, Excluir permanentemente, **Aprovar** (decide aprovações e conclui com exceção) e **Configurar**. Padrão na instalação: Super-Admin e Admin = tudo; demais perfis centrais = só leitura.

## Cobertura da especificação

| Requisito | Situação |
|---|---|
| RF-001 cadastro | Feito, sem anexos, dependências e orçamento/centro de custo |
| RF-002 formulários por tipo | Parcial: tipos com campos obrigatórios próprios; sem campos personalizados |
| RF-003, RF-004, RF-007, RF-008 | Feito |
| RF-005 vínculo com projeto, tarefa e subtarefa | **Pendente**: a coluna `projects_id` existe (decisão D2), falta a tela de vínculo com `Project`/`ProjectTask` |
| RF-006 recorrentes e proativas | Pendente |
| RF-009 lista e Kanban | Feito |
| RF-010 calendário, marcos, checklists | Pendente |
| RF-011 trilha, comentários | Feito |
| RF-012 evidência e aceite | Feito |
| RF-013 aprovação | Parcial: aprovador indicado; sem alçada por valor e sem versão |
| RF-014 reabrir validação | Pendente |
| RF-015, RF-017 SLA | Parcial: SLOs em dias úteis com calendário e escalonamento; sem pausas e exceções |
| RF-016 avisos | Feito: pop-up e e-mail em fila (o laboratório não tem SMTP, a fila espera) |
| RF-018 a RF-023 processos por frente | Pendente (Fase 2) |
| RF-024 dashboard operacional | Feito, inclusive tempo médio por etapa |
| RF-025 gerencial | Parcial: volume, carga, SLO, entregas no prazo; faltam reprogramações |
| RF-026, RF-027 portfólio e calendário editorial | Pendente |
| RF-028 a RF-034 integrações | Pendente (Fase 3) |

## Gestão integrada (v0.4.0)

Segunda devolutiva (10/11/2026): a ferramenta passou a cobrir também o trabalho do próprio Marketing, não só as solicitações de outras áreas. Detalhes e classificação de cada item em `docs/MATRIZ-DEVOLUTIVA-2.md`.

- **Origens:** *Solicitação de outra área* (formulário oficial, triagem, aceite) e *Atividade do Marketing* (cadastro interno, sem briefing nem aceite do solicitante; nasce Planejada com responsável e prazo). Direito novo **Cadastro interno** (bit 4096).
- **Estrutura:** pilar/frente → projeto → plano de ação → atividade → subtarefa. Pilares em Configurações → Pilares; projetos (com marcos) e planos no menu **Projetos e planos**. Tudo é opcional: demanda avulsa continua valendo.
- **Plano a partir da demanda:** botão "Criar plano de ação" na ficha (depois da triagem). A demanda-mãe só conclui quando as atividades estiverem concluídas ou canceladas, mais evidência e aceite se for externa. "Depende de" impede iniciar uma atividade antes da anterior.
- **Painel:** filtros por origem, pilar e projeto; cartões *Solicitações recebidas*, *Atividades internas* e *Entregas de projetos*; gráficos de origem, pilar e projetos.
- **SLOs:** grupo, processo, aplicabilidade (externa/interna/todas), vigência, situações que pausam a contagem e **homologação**: só ligam se estiverem Homologados com a evidência do aceite.

## Instalação e atualização

```bash
# no servidor, como root ou com sudo; troque o caminho pelo do GLPI
cd /var/www/glpi
sudo -u www-data php bin/console plugin:install marketing
sudo -u www-data php bin/console plugin:activate marketing
```

Atualizar: copie a pasta para `plugins/marketing`, ajuste o dono (`www-data`) e rode `plugin:install marketing` de novo (a instalação é idempotente: só cria o que falta) e `plugin:activate marketing`.

## Testes

```bash
sudo -u www-data env GLPI_ROOT=/var/www/glpi php plugins/marketing/tests/gestao.php   # pop-up, notificações, automações, SLOs, painel, PDF
sudo -u www-data env GLPI_ROOT=/var/www/glpi php plugins/marketing/tests/estrutura.php   # origens, pilar/projeto/plano, dependências, homologação e pausa de SLO
# também: poc.php, fase1.php, fase1b.php
```

## Limitações conhecidas

- O som do pop-up depende do navegador (ver acima) e não foi ouvido na verificação automática.
- A aba do plugin em Perfis pede reautenticação do GLPI e não foi aberta no navegador; o nome, os bits e os direitos padrão foram verificados pelas classes do GLPI.
- No GLPI 11, os formulários dependem do token CSRF que o próprio GLPI valida; foi verificado em navegador apenas no GLPI 12 (no 11 só pelos testes de linha de comando).
- A lista e o painel mostram todas as demandas da entidade para quem tem leitura (CA-07 pede restrição por atribuição para aprovadores).
- `exception_note` fica gravada: demanda reaberta depois de concluída por exceção não reaplica a regra de evidência e aceite (RN-07).

## Tema diurno e noturno

Todas as páginas do plugin têm o seletor **Automático / Diurno / Noturno** no canto superior direito. Diurno clareia as páginas do plugin (tira os atributos do tema escuro do GLPI no `<html>` e esconde a marca d'água); a escolha é salva por usuário (`Config` contexto `plugin:marketing:users`) e vale em qualquer computador. Arquivos: `public/js/theme.js`, `front/theme.php`, regras `html.mkt-light`/`html.mkt-dark` em `public/css/marketing.css`.
