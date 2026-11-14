# Gestão de Demandas (plugin `marketing`) para GLPI 11 e 12

Plugin de gestão de demandas, projetos e governança de Marketing. Cria a seção **Marketing** no menu lateral do GLPI com:

- **Painel de Demandas**: cartões, gráficos executivos, filtros de data, gaveta lateral ao clicar e PDF executivo.
- **Demandas**: ficha com briefing, fluxo de situações, evidências, aprovações e histórico.
- **Quadro Kanban**: visão Kanban e visão **Tabela principal** (agrupada por mês, estilo monday).
- **Projetos e planos**: pilar → projeto → plano de ação → atividade, com marcos e progresso. Atividades do próprio Marketing sem formulário de solicitação (direito *Cadastro interno*), e botão "Criar plano de ação" a partir de uma demanda.
- **Configuração**: tipos de demanda, SLOs (em dias úteis), notificações, automações e pop-up de atribuição com som.
- **Prazos**: reprogramação com justificativa, aviso à Gerência e marca permanente de demanda adiada.
- **Entregáveis**: envio e download de arquivos na ficha, com histórico.
- **Visibilidade por perfil** (direito *Ver todas as demandas*) e **importação de planilhas** Excel/CSV.
- **KPI's** (exclusivo do gerente de Marketing, direito *Book executivo*): 37 indicadores em 7 grupos com metas e semáforo, OKRs, ofensores com plano e prazo de recuperação e o book executivo "KPIs Marketing" em PDF, com envio automático todo dia 1º e anual.
- **Execução efetiva** (percentual informado) e edição em lote das atividades do plano de ação.
- Aba do plugin em *Administração → Perfis* (direitos de leitura, atualização, aprovação, configuração, cadastro interno e ver todas as demandas).

Requisitos: GLPI 11.0 a 12.0 e PHP 8.1 ou superior. Não há dados de exemplo: a instalação cria apenas os tipos, SLOs e automações padrão (SLOs e automações vêm desligados).

## Instalação

```bash
cd /var/www/glpi/plugins
git clone https://github.com/<usuario>/marketing.git marketing
chown -R www-data:www-data marketing
cd /var/www/glpi
sudo -u www-data php bin/console plugin:install marketing
sudo -u www-data php bin/console plugin:activate marketing
```

A pasta precisa se chamar `marketing`. Para atualizar: `git pull` e `plugin:install marketing` de novo (idempotente).
A desinstalação preserva os dados, a menos que se use `MARKETING_PURGE=1`.

## Testes

Rodam no servidor do GLPI e removem o que criam:

```bash
sudo -u www-data env GLPI_ROOT=/var/www/glpi php plugins/marketing/tests/gestao.php
# também: estrutura.php, poc.php, fase1.php, fase1b.php
```

## Documentação

`docs/GESTAO-DEMANDAS.md` (funcionalidades, regras, limitações), `docs/MATRIZ-DEVOLUTIVA-2.md` (classificação do que está entregue e do que falta), `docs/POC-Fase0.md` e `docs/Fase1-status.md`.

## Licença

GPLv3 ou posterior (ver `LICENSE`).
