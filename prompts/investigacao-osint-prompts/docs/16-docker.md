# 16 — Docker

## Serviços

```text
app       Laravel/PHP-FPM
web       Nginx ou servidor HTTP apropriado
mysql     Banco
redis     Cache + queue
worker    Laravel queue worker
scheduler Scheduler Laravel
```

Opcional posteriormente:

`horizon` para observabilidade de Redis queues.

## Princípios

- imagens pequenas;
- multi-stage build quando fizer sentido;
- usuário não-root quando possível;
- volumes somente no necessário;
- healthchecks;
- `.env` fora do Git;
- secrets nunca em Dockerfile.

## Desenvolvimento

Comandos devem estar documentados no README gerado pelo próprio projeto.

## Produção

Não usar `php artisan serve` como servidor público.
Separar web e worker.
Configurar cache/config/rotas e migrations com processo de deploy controlado.
