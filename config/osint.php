<?php

/*
|--------------------------------------------------------------------------
| Parâmetro de controle da execução OSINT (mock vs. integração real)
|--------------------------------------------------------------------------
|
| A execução real exige **`APP_ENV=production`**. O `APP_ENV` é a validação
| primária, aplicada por `App\Sources\OsintMode::mode()` antes de qualquer
| investigação e reaplicada em cada adapter resolvido pelo `SourceRegistry`:
|
|  - `APP_ENV=production` → integrações reais (Maigret, Sherlock, WhatsMyName,
|     Holehe e o enriquecimento do Instagram). `OSINT_MODE` aceita:
|       - `real` (padrão, inclusive quando ausente/desconhecido): força real;
|       - `mock`: kill switch explícito para voltar aos mocks sem mudar o
|         `APP_ENV`.
|  - qualquer outro `APP_ENV` (`local`, `testing`, `staging`, …) → sempre mock
|     determinístico, sem rede, mesmo que `OSINT_MODE=real`.
|
| O valor vem apenas do ambiente — nunca de entrada do usuário. Trocar o modo
| não altera o restante do domínio: os adapters reais e os mocks compartilham o
| mesmo contrato (`InvestigationSource`) e o mesmo slug.
|
*/

return [

    'mode' => env('OSINT_MODE', 'real'),

];
