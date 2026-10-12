<?php

namespace App\Sources\Runners;

/**
 * Desfecho de uma execução de script auxiliar (ex.: script Python/instagrapi)
 * com **exit code e saída JSON separados**.
 *
 * O `ToolResult` devolve o código de saída como sinal de sucesso da ferramenta,
 * mas algumas integrações precisam distinguir dois planos: o código de saída do
 * processo e o estado de negócio que o script reporta no JSON (ex.: sessão
 * inválida, desafio do provedor). Este DTO carrega os dois — sem inferir nada a
 * partir de stdout nem tratar saída como erro de ferramenta.
 *
 * Os campos de sessão alimentam os indicadores do painel admin
 * (`HEALTHY` / `CHALLENGE_REQUIRED` / `EXPIRED`) sem que o adapter precise
 * conhecer o model de configuração.
 */
final class ToolProcessOutcome
{
    /**
     * @param  array<string, mixed>  $payload  JSON devolvido pelo script (vazio quando ausente)
     * @param  array<int, string>  $warnings  avisos públicos informados pelo script
     */
    public function __construct(
        public readonly bool $successful,
        public readonly array $payload = [],
        public readonly array $warnings = [],
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
        public readonly ?string $sessionStatus = null,
        public readonly ?string $accountUsername = null,
        public readonly ?string $userId = null,
    ) {}
}
