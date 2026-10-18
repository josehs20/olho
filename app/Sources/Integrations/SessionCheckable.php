<?php

namespace App\Sources\Integrations;

/**
 * Capacidade de diagnóstico da credencial de serviço (prompt 48 §2.1).
 *
 * A ação "Testar conexão" do painel admin resolve o adapter registrado no
 * `SourceRegistry` sob o mesmo slug (`instagram`) e chama `checkSession()`.
 * Tanto o adapter real (script Python/instagrapi, que resolve
 * `cl.user_info_by_username('instagram')`) quanto o mock determinístico
 * implementam este contrato, então o controller nunca depende da classe
 * concreta — e o teste hermético da suíte exercita o mesmo caminho.
 */
interface SessionCheckable
{
    /**
     * @return array{healthy: bool, challenge: bool, message: ?string, account: ?string}
     */
    public function checkSession(): array;
}
