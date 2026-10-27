# 🚀 Sistema de Inscrições, Loja & Gestão do Avanço UNEC

Sistema completo em PHP e MySQL para gestão de eventos, inscrições de voluntários, controle financeiro, cotação com IA, inventário de patrimônio e ponto de venda da Loja & Cantina.

## 📁 Arquivos do Projeto

- **index.php**: Formulário público de inscrição com data de nascimento obrigatória, observações/restrições de saúde, envio de comprovante PIX e escolha de camisetas.
- **admin.php**: Painel administrativo completo:
  - Inscrições ordenadas por mais recentes com selo 🕒 Recente e cálculo automático de idade.
  - Alimentos da Cozinha com cotação inteligente de preços por cidade atendida.
  - Gestão de Inventário e Patrimônio da UNEC.
  - Gestão de Ônibus, Poltronas, Equipes e Líderes.
  - Exportação em planilha CSV e Ficha individual completa.
- **loja.php**: Loja & Cantina da UNEC com PDV, pedidos e estoque.
- **admin_ciclo.php**: Gestão de ciclos/edições do Avanço Missionário.
- **config.php**: Conexão com o banco MySQLi.

## ⚙️ Instalação no Servidor
1. Envie todos os arquivos para a raiz do seu servidor ou hospedagem cPanel/Hostinger.
2. Configure seus dados de banco em `config.php`.
3. Acesse `index.php` ou `admin.php` — as tabelas e colunas são criadas automaticamente (Auto-Patch)!
