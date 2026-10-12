<?php

/*
|--------------------------------------------------------------------------
| Parâmetro de controle da execução OSINT (mock vs. integração real)
|--------------------------------------------------------------------------
|
| `OSINT_MODE` é o interruptor central que decide, para todo o pipeline, se as
| investigações usam os adapters determinísticos (mock, sem rede) ou disparam
| as integrações reais. Valores:
|
|  - `real`: ignora qualquer `mock` e força os adapters reais de todas as
|     fontes (Maigret, Sherlock, WhatsMyName, Holehe e o enriquecimento do
|     Instagram). É a execução definitiva — nada de dados `example.com`.
|  - `mock`: força os adapters determinísticos de todas as fontes.
|  - `auto` (padrão): cada fonte segue o seu próprio driver
|     (`MAIGRET_DRIVER`, `SHERLOCK_DRIVER`, …), exatamente o comportamento
|     anterior. A suíte de testes usa `auto` + drivers `mock` (phpunit.xml).
|
| O valor vem apenas do ambiente — nunca de entrada do usuário. Trocar o modo
| não altera o restante do domínio: os adapters reais e os mocks compartilham o
| mesmo contrato (`InvestigationSource`) e o mesmo slug.
|
*/

return [

    'mode' => env('OSINT_MODE', 'auto'),

];
