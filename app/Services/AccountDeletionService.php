<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Exclusão de conta conforme regras de privacidade: remove dados operacionais
 * (investigações/results/relatórios) e anonimiza dados pessoais, preservando
 * registros financeiros/auditoria (que exigem retenção) sob o usuário anonimizado.
 */
class AccountDeletionService
{
    public function deleteAccount(User $user): void
    {
        DB::transaction(function () use ($user) {
            // Remove dados operacionais (cascata até results/evidence/reports).
            $user->investigations()->delete();

            // Anonimiza os dados pessoais, mantendo a linha para rastros financeiros/auditoria.
            $user->name = 'Conta excluída';
            $user->email = 'deleted-'.$user->id.'@invalid.local';
            $user->password = Hash::make(Str::random(40));
            $user->remember_token = null;
            $user->save();
        });
    }
}
