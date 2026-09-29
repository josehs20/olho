<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CreateAdminCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:create-admin
        {--email= : E-mail do administrador (ou env ADMIN_EMAIL)}
        {--name= : Nome do administrador (ou env ADMIN_NAME)}
        {--password= : Senha do administrador (ou env ADMIN_PASSWORD)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Cria (ou promove) um usuário administrador a partir de opções ou variáveis de ambiente';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $email = $this->option('email') ?: env('ADMIN_EMAIL');
        $name = $this->option('name') ?: env('ADMIN_NAME', 'Administrador');
        $password = $this->option('password') ?: env('ADMIN_PASSWORD');

        if (! $email) {
            $this->error('Informe --email ou defina a variável ADMIN_EMAIL.');

            return self::FAILURE;
        }

        $generated = $password === null || $password === '';
        $password = $generated ? Str::password(24) : $password;

        $user = User::query()->firstOrNew(['email' => Str::lower($email)]);
        $user->name = $name;
        $user->password = Hash::make($password);
        $user->email_verified_at = $user->email_verified_at ?? now();
        $user->is_admin = true;
        $user->save();

        $this->info("Administrador pronto: {$user->email}");

        if ($generated) {
            $this->warn("Senha gerada (guarde-a): {$password}");
        }

        return self::SUCCESS;
    }
}
