# Investigação Pública — pacote de requisitos e prompts para Cline + DeepSeek

Este pacote contém a especificação de produto, arquitetura, regras `.mdc` e prompts incrementais para construir uma aplicação web Laravel de investigação de presença digital com base em fontes públicas.

## Objetivo

Construir uma plataforma leve, executada em Docker, na qual o usuário compra créditos e utiliza esses créditos para executar módulos de pesquisa. O sistema deve apresentar resultados, fontes, evidências e possíveis correlações sem transformar uma correspondência em certeza de identidade.

## Stack-base definida

- Laravel 13.x
- PHP 8.5 no ambiente Docker, sempre validando compatibilidade no momento da execução
- Laravel Breeze 2.x para autenticação simples com Blade/Tailwind, conforme compatibilidade resolvida pelo Composer
- Livewire para componentes interativos onde houver benefício real
- Blade + Tailwind + Vite
- MySQL 8.4+
- Redis para cache e filas
- Workers Docker independentes para tarefas demoradas
- HTML para relatórios no MVP
- Pagamento PIX por adapter; nenhuma chave real deve ser gravada no repositório
- Primeira fase dos provedores OSINT com mocks
- Sem integração de IA no produto no MVP

> Observação sobre autenticação: o Laravel 13 oferece os starter kits oficiais atuais em Livewire/React/Vue/Svelte. O pacote `laravel/breeze` continua sendo um pacote separado e sua versão 2.x declara compatibilidade com Laravel 13. O projeto deverá usar Breeze para preservar a simplicidade pedida, mas o Cline deve confirmar a resolução real do Composer antes de instalar e, em caso de incompatibilidade, implementar a mesma experiência de autenticação com componentes oficiais sem alterar o domínio do sistema.

## Ordem de execução

1. Leia `docs/00-visao-geral.md` e `docs/01-requisitos.md`.
2. Leia as regras `.cursor/rules/*.mdc` relevantes para a etapa.
3. Execute somente um arquivo de `prompts/` por vez, em ordem.
4. O agente deve inspecionar o estado atual do projeto antes de alterar arquivos.
5. O agente deve manter alterações pequenas, testáveis e reversíveis.
6. Ao final de cada etapa, executar os testes e verificações de qualidade definidos pelo prompt.
7. Não avançar uma etapa com testes quebrados sem registrar o bloqueio e tentar corrigi-lo.

## Segurança e privacidade

O produto trabalha apenas com informação pública ou com dados que o próprio usuário forneceu para pesquisa. É proibido projetar ou implementar mecanismos para contornar autenticação, CAPTCHA, paywall, perfis privados, controles de acesso ou obter credenciais/sessões privadas. O sistema não deve oferecer rastreamento em tempo real, interceptação de mensagens ou acesso não autorizado.

## Configuração futura do PIX

Os dados reais de cobrança devem entrar apenas em `.env`/secret manager. O arquivo de documentação usa placeholders como:

`PIX_KEY_TYPE=cnpj`
`PIX_KEY=CONFIGURE_ME`
`PIX_RECEIVER_NAME=CONFIGURE_ME`
`PIX_RECEIVER_CITY=CONFIGURE_ME`

Nunca colocar a chave PIX real em código, migrations, testes públicos ou Git.

## Fontes de referência

- Laravel release notes: https://laravel.com/docs/releases
- Laravel starter kits: https://laravel.com/docs/starter-kits
- Laravel queues: https://laravel.com/docs/queues
- Laravel Redis: https://laravel.com/docs/redis
- Laravel Horizon: https://laravel.com/docs/horizon
- Laravel Breeze: https://github.com/laravel/breeze
- Maigret: https://github.com/soxoj/maigret
- Sherlock: https://github.com/sherlock-project/sherlock
- WhatsMyName: https://github.com/WebBreacher/WhatsMyName
- Holehe: https://github.com/megadose/holehe

Antes de incorporar qualquer código de terceiro ao produto comercial, confirmar licença, versão, dependências, modo de execução e termos das fontes consultadas.
