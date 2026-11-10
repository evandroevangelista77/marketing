# Gestão de Demandas (plugin `marketing`) para GLPI 11 e 12

Plugin de gestão de demandas, projetos e governança de Marketing. Cria a seção **Marketing** no menu lateral do GLPI com:

- **Painel de Demandas**: cartões, gráficos executivos, filtros de data, gaveta lateral ao clicar e PDF executivo.
- **Demandas**: ficha com briefing, fluxo de situações, evidências, aprovações e histórico.
- **Quadro Kanban**: visão Kanban e visão **Tabela principal** (agrupada por mês, estilo monday).
- **Configuração**: tipos de demanda, SLOs (em dias úteis), notificações, automações e pop-up de atribuição com som.
- **Tema** diurno, noturno ou automático por usuário.
- Aba do plugin em *Administração → Perfis* (direitos de leitura, atualização, aprovação e configuração).

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
# também: poc.php, fase1.php, fase1b.php
```

## Documentação

`docs/GESTAO-DEMANDAS.md` (funcionalidades, regras, limitações), `docs/POC-Fase0.md` e `docs/Fase1-status.md`.

## Licença

GPLv3 ou posterior (ver `LICENSE`).
