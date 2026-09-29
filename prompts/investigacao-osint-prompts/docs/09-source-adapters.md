# 09 — Source adapters

## Objetivo

Desacoplar o produto das ferramentas externas.

## Contrato

Cada adapter deve possuir:

```text
key()
name()
supports(input)
execute(context)
normalize(raw)
healthCheck() opcional
```

## Resultado padrão

```json
{
  "source": "example",
  "entity_type": "social_profile",
  "value": "@usuario",
  "url": "https://example.test/profile",
  "title": "Example profile",
  "description": null,
  "confidence": null,
  "evidence": [
    {"type": "username_match", "value": "@usuario"}
  ],
  "metadata": {}
}
```

## Mocks da Fase 1

Criar:

- MockUsernameSource;
- MockEmailSource;
- MockWebSource.

Os mocks devem produzir dados determinísticos para testes. Devem simular sucesso, vazios, timeout e erro de fonte sem realizar chamadas externas.

## Fontes planejadas

- Maigret;
- Sherlock;
- WhatsMyName;
- Holehe.

## Execução real futura

Usar wrapper/runner controlado. Não aceitar caminho de binário ou argumentos diretamente do usuário.

## Rate limiting

Respeitar limites das fontes. Configurar timeout e retry por adapter. Retry não deve multiplicar custo ao cliente sem regra explícita.

## Observabilidade

Registrar:

- source key;
- duração;
- status;
- attempt;
- tamanho aproximado do resultado;
- error code sanitizado.

Não registrar segredos, cookies ou tokens.
