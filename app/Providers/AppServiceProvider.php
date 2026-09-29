<?php

namespace App\Providers;

use App\Models\User;
use App\Payments\FakePixProvider;
use App\Payments\PaymentProvider;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(PaymentProvider::class, function () {
            return match (config('payments.provider')) {
                'fake' => new FakePixProvider,
                default => throw new \RuntimeException('Payment provider não suportado: '.config('payments.provider')),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('access-admin', fn (User $user) => $user->is_admin);
    }
}
