# Prompt 02 — Estrutura base

Leia a documentação de arquitetura e as regras do projeto.

Objetivo: criar ou ajustar a base do projeto para Laravel 13.x, mantendo a aplicação leve.

Tarefas:
1. se não houver projeto, criar Laravel 13.x no diretório atual sem aninhar desnecessariamente a pasta;
2. usar PHP compatível com Laravel 13 e configurar Docker;
3. configurar MySQL e Redis;
4. configurar Breeze 2.x com Blade/Tailwind quando o Composer confirmar compatibilidade; caso a instalação do Breeze atual não seja apropriada para Laravel 13, usar o caminho oficial compatível com autenticação Blade/Livewire e documentar a decisão;
5. instalar Livewire apenas se necessário para os componentes previstos;
6. manter Vite/Tailwind;
7. criar `.env.example` completo;
8. configurar fila Redis;
9. configurar testes;
10. não implementar domínio de investigação ainda.

Verificações:
- app inicia;
- migrations executam;
- login funciona;
- Redis responde;
- queue está configurada;
- testes base passam.
