# Devolutiva 2: da Gestão de Demandas para a Gestão Integrada da Operação de Marketing

Resposta técnica ao *Debriefing do protótipo* de 10/11/2026 (Desenvolvimento / TI). Versão do plugin: **0.5.0** (GLPI 11 e 12). A seção 0 responde ao alinhamento de 14/11/2026; as demais mantêm a devolutiva de 10/11.

Legenda da classificação: **Entregue** (já na 0.4.0) · **Disponível** (já existia e atende) · **Ajuste simples** · **Desenvolvimento adicional** · **Etapa futura**.

## 0. Resposta ao alinhamento de 14/11/2026 (v0.5.0)

| # | Ponto levantado | Situação | Como atende |
|---|---|---|---|
| 1a | Upload e download direto de entregáveis | **Entregue** | Ficha da demanda → *Entregáveis (arquivos)*: envio múltiplo (até 25 MB, limitado também pelo PHP do servidor), comentário, SHA-256, remoção, download sempre como anexo e registro de cada download no histórico. Arquivos ficam fora da pasta pública do GLPI. Executáveis e páginas (.php, .html, .js, .svg…) são recusados. Os arquivos contam como evidência para concluir (RN-05). |
| 1b | Reprogramação de prazo: justificativa, aviso à Gerência, identificação permanente de adiadas | **Entregue** | Trocar um prazo já confirmado exige justificativa (a primeira confirmação é livre). Cada troca guarda data anterior, nova data, autor e motivo. Selo **Adiada ×N** permanente (não some se o prazo for antecipado depois), prazo original na ficha, filtro "Só adiadas" e card "Adiadas" no painel. A Gerência (usuários definidos em Configurações → Demandas) recebe pop-up e, se ligado, e-mail (evento "Prazo reprogramado"); sem Gerência definida, o aviso vai ao aprovador e ao responsável pelo projeto. Também vale na edição em lote do plano. |
| 1c | Restrição de visibilidade por perfil | **Entregue** | Novo direito **Ver todas as demandas** em Administração → Perfis. Sem ele, o usuário vê só o que pediu, executa ou aprova, e o que pertence a projetos e planos de que é responsável (lista, Kanban, painel, projetos, planos, ficha, download e mudança de situação). Na atualização, quem já tinha acesso ganhou o direito para nada mudar; o administrador retira onde quiser. |
| 1d | Importação Excel/CSV | **Entregue** (antecipada) | Menu *Importar planilha*: aceita .xlsx e .csv, reconhece as colunas pelo nome (título, responsável, prazo, início, prioridade, tipo, pilar, projeto, plano, objetivo, entrega, situação, % execução), mostra a conferência linha a linha (ok, aviso, erro, duplicada) e só grava após a confirmação. Opção de criar pilares/projetos/planos que faltarem e de ignorar duplicadas. Modelo CSV para baixar. |
| 2 | Distribuição das atividades no plano de ação | **Entregue** | Ficha do plano: tabela editável com responsável, início, prazo, prioridade, dependência (recusa ciclos) e % de execução, salvando de uma vez. Alterar responsável avisa quem recebeu; alterar prazo segue a regra de reprogramação. |
| 3 | Cálculo do progresso e execução efetiva | **Entregue** | Projeto e plano mostram três medidas: **avanço por situação** (média de 5% recebida, 15% triagem, 30% planejada, 55% em andamento/bloqueada/risco/terceiro, 80% aprovação, 100% concluída; canceladas fora), **execução efetiva** (média do % informado pelo responsável em cada atividade; concluída = 100%; sem informação = 0%; mostra quantas abertas já têm % informado) e **cronograma decorrido** com o desvio em pontos percentuais. A demanda-mãe de um plano não entra na conta. Ponderação por esforço (horas) fica como evolução. |
| 4 | Atividades recorrentes | **Mantida no planejamento** | Próximo incremento. Proposta: modelo de recorrência (semanal, quinzenal, mensal, dia do mês/semana, vigência e responsável) que gera as atividades automaticamente pela rotina agendada do GLPI, com o mesmo fluxo e SLO das demais. A decidir com o Marketing: lista das rotinas atuais, janela de geração e o que fazer com ocorrências não concluídas. |
| 5 | SLOs: regras, prazos e gatilhos | **Depende de definição do Marketing** | A estrutura (homologação com evidência, pausas, aplicabilidade, vigência, grupos) está pronta. Faltam: (a) prazo e estados inicial/final de cada processo, em dias úteis ou corridos; (b) quem homologa e a evidência do aceite; (c) os gatilhos hoje indisponíveis ("briefing validado", "última atualização", "aceite registrado"), que exigem desenvolvimento depois que as regras forem definidas. Os SLOs semeados continuam desligados até a homologação. |

Fora do escopo deste incremento, e também mantidos no backlog: aprovação do novo prazo antes de valer, nível de escalonamento em várias etapas e status "Adiada" como situação própria (hoje a marca é permanente e independente da situação).

### Adendo (14/11/2026): KPI's e book executivo — **Entregue** (v0.6.0)

Todos os indicadores que o plugin consegue gerar (37, em 7 grupos) com metas e semáforo editáveis; OKRs; ofensores com plano de recuperação e prazos recomendados; book executivo "KPIs Marketing" em PDF (mensal, anual e sob demanda) com envio automático todo dia 1º e anual no fim do ano. Acesso exclusivo do gerente de Marketing, pelo direito `Book executivo (KPIs, OKRs)`.

## 1. Decisões pedidas (item 5)

**O GLPI suporta pilar → projeto → plano → atividade de forma nativa?** Não nesses quatro níveis. O GLPI tem `Project` e `ProjectTask`, que cobrem projeto e tarefa (com tarefa-filha), mas não têm pilar nem plano de ação, nem origem, nem os portões de triagem e aceite deste fluxo. A decisão foi **entidades próprias do plugin**: `pilar`, `projeto`, `plano de ação` (tabelas `glpi_plugin_marketing_*`), e a **atividade é a própria demanda** (coluna `origem`). Assim a hierarquia é opcional: uma demanda avulsa não precisa de projeto. A ligação com o `Project` nativo (RF-005) continua pendente e pode ser feita depois sem mudar o modelo.

**Telas e permissões distintas para abertura externa e cadastro interno, com relatório consolidado.** Entregue. A abertura externa continua em **Nova solicitação** (direito *Criar*, formulário oficial, triagem e aceite). O cadastro interno é **Nova atividade do Marketing** (direito novo **Cadastro interno**, bit 4096, na aba do plugin em Perfis). Painel, listas, Kanban e PDF mostram as duas origens juntas e permitem filtrar por origem.

**Escopo e esforço.** Plano de ação a partir da demanda: entregue. Importação Excel/CSV: próximo incremento (ver §2, item 3.4). Esforço em tamanho relativo: P = poucos dias, M = cerca de uma semana, G = mais de uma semana.

## 2. Classificação por funcionalidade

### 3.1 Pilar estratégico e identificação do projeto
| Requisito | Situação | Observação |
|---|---|---|
| Campos: origem, pilar, projeto, plano, título, responsável, prioridade, status, prazo, entregável | **Entregue** | Ficha, formulário interno e filtros |
| Visível em ficha, filtros, listas, Kanban, painel e relatórios | **Entregue** | Também no PDF executivo (filtros e ficha de cada demanda) |
| Pilares e projetos cadastráveis, editáveis e arquiváveis sem código | **Entregue** | Pilares: Configurações → Pilares. Projetos e planos: menu Projetos e planos |
| Projeto com atividades, responsáveis, cronograma, objetivo, marcos e progresso | **Entregue** | Cronograma = início, fim e marcos. Progresso = média do avanço das atividades. Sem Gantt (etapa futura, M) |
| Demanda avulsa sem projeto | **Entregue** | Nenhum vínculo é obrigatório |
| Subtarefa | **Entregue (1 nível)** | Subtarefa de subtarefa é ajuste simples (P) |

### 3.2 Duas origens
| Requisito | Situação | Observação |
|---|---|---|
| Solicitação de outra área pelo formulário oficial | **Disponível** | Sem mudança no fluxo |
| Marketing → Marketing por fluxo simplificado, podendo nascer planejada | **Entregue** | Com responsável e prazo nasce *Planejada*; senão fica *Em triagem* (rascunho). Não exige briefing, evidência nem aceite do solicitante |
| Painel diferencia volume de solicitações, atividades internas e entregas de projetos | **Entregue** | Três cartões e três gráficos (origem, pilar, projetos) |
| Rotina recorrente (modelo gera novas ocorrências) | **Desenvolvimento adicional (M)** | Modelo + ação automática do GLPI. Não faz parte do MVP |
| Comunicação de crise (acionamento emergencial com registro posterior) | **Ajuste simples (P)** | Origem interna com marca "emergencial" e prazo de registro; depende do procedimento específico |

### 3.3 Da demanda aprovada ao plano de ação
| Requisito | Situação | Observação |
|---|---|---|
| Botão "Criar plano de ação" mantendo o código da demanda | **Entregue** | Na ficha, depois da triagem. O plano guarda a demanda original; briefing e decisões continuam nela |
| Plano com objetivo, responsável, datas, tarefas, subtarefas, critérios de conclusão | **Entregue** | |
| Sequência e dependências | **Entregue** | "Depende de": a atividade não inicia enquanto a anterior estiver aberta |
| Progresso por atividade; bloqueada, em aprovação, aguardando terceiros | **Entregue** | Usa as situações já existentes |
| Conclusão da demanda-mãe considera aceite, evidências e atividades | **Entregue** | Não conclui com atividade em aberto; evidência e aceite continuam valendo para solicitação externa |
| Modelos de plano por tipo (campanha, evento, conteúdo, GTM) | **Desenvolvimento adicional (M)** | Próximo incremento. Hoje as atividades são digitadas na criação do plano |

### 3.4 Importação de planilhas (Excel/CSV)
**Etapa do próximo incremento, desenvolvimento adicional (G).** O GLPI já traz a biblioteca de leitura de .xlsx, o que reduz o risco. Escopo previsto: modelo de planilha, pré-visualização com mapeamento de colunas, validação linha a linha (datas, usuários, obrigatórios, referências a projeto), bloqueio de duplicidade por código externo, registro do lote (quem, quando, quantas linhas, relatório de erros) e criação só das linhas aprovadas, sempre como atividades internas (sem contornar os portões da solicitação externa).

### 3.5 SLOs
| Requisito | Situação | Observação |
|---|---|---|
| SLOs configuráveis entre situações | **Disponível** | |
| Nome, processo e etapa, calendário útil e feriados, quantidade em dias úteis | **Entregue** | Campo "Processo e etapa" e grupo novos |
| Pausas e retomada | **Entregue** | Situações que pausam: o tempo parado nelas empurra o vencimento; retoma ao sair delas |
| Aplicabilidade | **Entregue** | Solicitações externas, atividades internas ou todas |
| Homologação, vigência e evidência do aceite | **Entregue** | SLO só liga se estiver **Homologado e com a evidência do aceite**; grava quem homologou e quando |
| SLO não ativo até homologação | **Entregue** | A regra é imposta ao salvar; SLOs que já estavam ligados foram marcados como homologados na migração |
| Unidade em horas (além de dias úteis) | **Ajuste simples (P)** | Hoje só dias úteis |
| Escalonamento em mais de um nível | **Ajuste simples (P)** | Hoje escalona para uma pessoa |
| Anexo da evidência do aceite | **Ajuste simples (P)** | Hoje é texto ou link |
| Diferenciar SLO de atendimento, prazo confirmado da entrega e prazo da tarefa | **Entregue** | São três indicadores separados: SLOs (Configurações), "Entregas no prazo" (prazo confirmado) e prazo da atividade. O painel não soma SLOs de etapas diferentes |

### Matriz de SLOs: existentes × dependentes de novos gatilhos
| Marco (prazo de referência) | Situação | Como é medido |
|---|---|---|
| Confirmação de recebimento (até 1 dia útil) | **Disponível** | Recebida → Em triagem |
| Triagem inicial (até 2 dias úteis) | **Ajuste simples** | Definir se conta de *Recebida* ou de *Em triagem*; existe um SLO "Triagem" que mede a permanência em triagem |
| Pedido de complementação (até 1 dia útil após a triagem) | **Entregue (desligado)** | Em triagem → Aguardando solicitante |
| Validação do briefing completo (até 2 dias úteis após os insumos) | **Entregue (desligado)** | Aguardando solicitante → Em triagem. O "insumo recebido" ainda não é um gatilho próprio: ajuste simples |
| Definição de responsável e prazo (até 2 dias úteis após a validação do briefing) | **Desenvolvimento adicional (P)** | Existe com 4 dias úteis (valor do briefing original). Precisa do gatilho "briefing validado" para contar a partir dele |
| Atualização das demandas abertas (pelo menos semanal) | **Desenvolvimento adicional (P)** | Precisa do gatilho "última atualização" (a data da última atualização já fica registrada) |
| Fechamento documental (até 2 dias úteis após entrega e aceite) | **Desenvolvimento adicional (P)** | Precisa do gatilho "aceite registrado" |
| Prazos das frentes (Comunicação, Conteúdo, Eventos, Campanhas, CRM) | **Etapa futura** | Dependem da homologação dos prazos de cada frente; a estrutura (grupo, processo, vigência) já comporta |

## 3. Critérios de aceite do item 4

| Critério | Situação |
|---|---|
| Solicitação externa pelo formulário e atividade interna sem formulário, com origens distintas no painel | Atendido |
| Atividade ligada a pilar e projeto, disponível em filtros, ficha e exportações | Atendido (filtros, ficha e PDF executivo) |
| Demanda aprovada gera plano de ação com várias tarefas e vínculo com o pedido original | Atendido |
| Importador valida, informa erros e grava só o aprovado | Pendente (próximo incremento) |
| SLOs só ativam após homologação, com início/fim, dias úteis, pausas e responsáveis | Atendido |
| Painel diferencia solicitações, atividades internas e entregas de projeto | Atendido |

## 4. Backlog único com a devolutiva anterior
Ainda abertos: arquivos entregáveis (anexo na evidência), histórico e aprovação de mudança de prazo, marcação "Adiada" (ajuste simples), restrição de visibilidade por atribuição (CA-07), calendário editorial e marcos no calendário, integrações (Fase 3).
