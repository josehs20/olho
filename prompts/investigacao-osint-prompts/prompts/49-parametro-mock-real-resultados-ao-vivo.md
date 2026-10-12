# Prompt 49 — Parâmetro Mock vs. Integração Real, Unificação de Rotas e Resultados ao Vivo

## CONTEXTO

O sistema já executa o pipeline real (Maigret, Sherlock, WhatsMyName, Holehe e o
enriquecimento do Instagram) **e** mantém adapters determinísticos (mock). A
escolha era feita **fonte por fonte** (`config/sources.php` → `MAIGRET_DRIVER`,
etc.), sem um interruptor único. Faltava também: (1) um parâmetro central para
alternar Mock ↔ Integração Real de forma transparente; (2) atualização automática
da tela de resultados do fluxo autenticado (a avulsa já fazia polling); e (3) um
layout de resultados corporativo e profissional.

Objetivo: **não remover os mocks**, mas encapsulá-los atrás de um parâmetro de
controle, unificar o disparo das investigações e modernizar a apresentação.

---

## 1. PARÂMETRO DE CONTROLE (Mock vs. Real)

- `config/osint.php` (novo) → `OSINT_MODE` com três valores:
  - `real` → ignora qualquer `mock` e força os adapters **reais** de todas as
    fontes (nada de `example.com`). É a execução definitiva.
  - `mock` → força os adapters **determinísticos** de todas as fontes (sem rede).
  - `auto` (padrão) → cada fonte segue o seu próprio driver
    (`MAIGRET_DRIVER`, …), exatamente o comportamento anterior.
- `App\Sources\OsintMode` (novo): resolve o modo (`mode()`, `forced()`,
  `forcesReal()`, `forcesMock()`, `isAuto()`, `label()`, `tone()`).
- `AppServiceProvider`: cada fonte passa por `useReal($perSource)`, que aplica o
  override global **sem** mudar o modo `auto` — por isso a suíte de testes e os
  ambientes existentes não se alteram.
- O modo **nunca** vem de entrada do usuário: só do ambiente.
- `StartInvestigationRunJob` congela o modo no run (`metadata.execution_mode`),
  para a UI/relatório exibirem o que valia naquela execução.

`.env.example` documenta `OSINT_MODE=real`; `phpunit.xml` fixa `OSINT_MODE=auto`
(mantém a suíte hermética — os drivers por fonte seguem `mock`).

## 2. UNIFICAÇÃO DAS ROTAS DE DISPARO

- `/investigacao` (avulsa, `Guest\InvestigationController` → `GuestInvestigationService`)
  e `/investigations` (conta, `InvestigationController` → `InvestigationService`)
  **já convergiam** no mesmo `InvestigationService`, no mesmo pipeline de jobs e
  nas mesmas tabelas. O parâmetro `OSINT_MODE` passa a valer para as duas,
  porque a decisão real/mock acontece no `SourceRegistry` (compartilhado).
- `InvestigationStatusTest::test_both_entry_points_share_the_same_processing_and_mode`
  prova a convergência: mesmo snapshot de modo e mesmo pipeline nas duas jornadas.

## 3. ATUALIZAÇÃO DINÂMICA (Polling)

- Nova rota `investigations.status` (`GET /investigations/{investigation}/status`)
  + `InvestigationController::status()` (JSON leve, mesma autorização do `show`).
- `InvestigationService::progress()` (novo) espelha o `progress()` da avulsa.
- `investigations/partials/polling.blade.php`: `fetch` a cada ~4s enquanto o
  status é de processamento; recarrega a página só quando o estado observável
  muda. Sem framework novo (igual ao acompanhamento da avulsa).

## 4. LAYOUT DE RESULTADOS (corporativo)

- `investigations/show.blade.php` redesenhado com seções bem definidas:
  **Status Geral** (indicadores), **Modo de Execução (Mock/Real)**,
  **Parâmetros de Entrada**, **Resultados por Módulo** e
  **Evidências / Enriquecimento**.
- Badges de status coloridas (`x-status-badge`, `x-badge`), cards (`x-card`,
  `surface-2`), indicadores (`x-stat`) e estados vazios (`x-empty-state`).
- `investigations/partials/result-card.blade.php`: cartão de resultado com
  tratamento elegante para **perfis enriquecidos** (foto/avatar, nome, bio) e
  indicação clara de dados não encontrados (sem preencher com informação fictícia).

## Diretrizes atendidas

- Lógica dos mocks preservada, apenas encapsulada atrás do parâmetro.
- Alternância Mock ↔ Real transparente para o operador (mesmo contrato/slug).
- Nenhum segredo introduzido; nenhum dado simulado exibido como real.
