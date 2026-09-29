# 14 — Privacidade, governança e LGPD

## Princípios de produto

- finalidade definida;
- coleta mínima;
- transparência;
- segurança;
- controle de acesso;
- retenção definida;
- trilha de auditoria.

## Dados do cliente

O sistema pode armazenar conta, histórico de compras e investigações, mas deve minimizar dados desnecessários.

## Dados pesquisados

Guardar apenas o necessário para executar e apresentar a investigação, conforme política de retenção definida pelo operador.

## Direitos e processos

Planejar endpoints/processos para:

- acesso aos dados da conta;
- correção;
- exclusão conforme requisitos aplicáveis;
- contato para dúvidas sobre tratamento.

## Retenção

Configurar TTL por categoria:

- logs operacionais;
- resultados de investigação;
- imagens enviadas;
- relatórios;
- registros financeiros;
- auditoria.

Registros financeiros e de auditoria podem exigir retenção diferente de resultados operacionais.

## Proibições de implementação

Não criar mecanismos para:

- obter dados privados de contas;
- burlar autenticação;
- coletar cookies/tokens de sessão;
- acessar grupos/perfis privados;
- rastrear localização em tempo real;
- interceptar mensagens;
- vender credenciais ou dados obtidos por acesso não autorizado.

## Produção

Antes do lançamento, validar com assessoria jurídica/ponto focal de privacidade:

- bases legais por fluxo;
- aviso de privacidade;
- retenção;
- atendimento a titulares;
- contratos com operadores/provedores;
- cookies e analytics;
- transferências internacionais, quando aplicáveis.
