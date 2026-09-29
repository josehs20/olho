# 05 — Autenticação

## Objetivo

Usar autenticação simples baseada em e-mail e senha, preferencialmente com Laravel Breeze + Blade/Tailwind. Livewire pode ser adicionado separadamente para componentes do produto.

## Funcionalidades

- registro;
- login;
- logout;
- confirmação de e-mail;
- recuperação de senha;
- throttling;
- sessão segura;
- autorização admin.

## Admin

Adicionar papel simples `admin` no usuário ou mecanismo equivalente. O MVP não precisa de RBAC complexo.

## Regras

- password hash via Laravel;
- nunca logar senha ou token;
- sessão regenerada após autenticação;
- ações administrativas protegidas por middleware/policy;
- endpoints sensíveis com rate limit;
- não permitir criação de admin via formulário público.

## Seed inicial

Criar admin somente por seed/command local documentado e variáveis de ambiente, evitando credenciais padrão permanentes no código.
