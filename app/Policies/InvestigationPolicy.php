<?php

namespace App\Policies;

use App\Models\Investigation;
use App\Models\User;

class InvestigationPolicy
{
    /**
     * Somente o dono (ou admin) pode visualizar a investigação.
     */
    public function view(User $user, Investigation $investigation): bool
    {
        // Investigação avulsa (prompt 41) não pertence a conta: o acesso é pelo
        // token de alta entropia, nunca pela policy de usuário.
        if ($investigation->isGuest()) {
            return $user->is_admin;
        }

        return $investigation->user_id === $user->id || $user->is_admin;
    }

    /**
     * Reexecução controlada (prompt 39): apenas o dono (ou admin) inicia um novo
     * run, que nunca sobrescreve o anterior (docs/02-regras-negocio.md).
     */
    public function rerun(User $user, Investigation $investigation): bool
    {
        if ($investigation->isGuest()) {
            return false;
        }

        return $investigation->user_id === $user->id || $user->is_admin;
    }
}
