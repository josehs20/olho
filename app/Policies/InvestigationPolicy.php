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
        return $investigation->user_id === $user->id || $user->is_admin;
    }
}
