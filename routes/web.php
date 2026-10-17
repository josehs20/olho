<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Admin\AuditController as AdminAuditController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\IntegrationSettingsController as AdminIntegrationSettingsController;
use App\Http\Controllers\Admin\InvestigationController as AdminInvestigationController;
use App\Http\Controllers\Admin\ModuleTestController as AdminModuleTestController;
use App\Http\Controllers\Admin\PackageController as AdminPackageController;
use App\Http\Controllers\Admin\PaymentController as AdminPaymentController;
use App\Http\Controllers\Admin\PaymentSettingsController as AdminPaymentSettingsController;
use App\Http\Controllers\Admin\SourceController as AdminSourceController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Guest\InvestigationController as GuestInvestigationController;
use App\Http\Controllers\Guest\PaymentController as GuestPaymentController;
use App\Http\Controllers\InvestigationController;
use App\Http\Controllers\PagBankSyncController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReportController;
use App\Http\Middleware\GuestAccessHeaders;
use App\Models\CreditPackage;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome', [
        // Preço real da consulta avulsa + pacotes de créditos (nenhum valor fixo no HTML).
        'guestPackage' => CreditPackage::query()->guestKind()->where('active', true)->orderBy('display_order')->first(),
        'packages' => CreditPackage::query()->ofKind(CreditPackage::KIND_CREDITS)->where('active', true)->orderBy('display_order')->get(),
        'platforms' => config('platforms.platforms', []),
        'scope' => config('platforms.scope', []),
    ]);
})->name('home');

Route::view('/privacy', 'legal.privacy')->name('legal.privacy');
Route::view('/terms', 'legal.terms')->name('legal.terms');

Route::get('/dashboard', [DashboardController::class, 'index'])->middleware('auth')->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [AdminDashboardController::class, 'index'])->name('dashboard');

    Route::get('/users', [AdminUserController::class, 'index'])->name('users.index');
    Route::post('/users/{user}/credits', [AdminUserController::class, 'adjustCredits'])->name('users.adjust-credits');

    Route::resource('packages', AdminPackageController::class)->except(['show']);

    Route::get('/payments', [AdminPaymentController::class, 'index'])->name('payments.index');

    Route::post('/payments/{payment}/sync', [PagBankSyncController::class, 'sync'])
        ->middleware('throttle:admin-sync')
        ->name('payments.sync');

    // Varredura em lote: confere **todos** os pendentes PagBank de uma vez
    // (mesmo caminho quando o webhook não chega). Caminho com menos segmentos
    // que `/payments/{payment}/sync`, então não há colisão de rota.
    Route::post('/payments/sync-pending', [PagBankSyncController::class, 'syncPending'])
        ->middleware('throttle:admin-sync')
        ->name('payments.sync-pending');

    // Verificação pelo **worker** (fila): o botão do painel não espera a API do
    // provedor — o job reconsulta o PagBank e libera o que já estiver pago. É o
    // par assíncrono dos dois endpoints acima (mesmo teto de 25 por clique).
    // `/payments/revalidate-pending` tem 2 segmentos, contra 3 de
    // `/payments/{payment}/revalidate`: não há colisão de rota.
    Route::post('/payments/revalidate-pending', [PagBankSyncController::class, 'revalidatePending'])
        ->middleware('throttle:admin-sync')
        ->name('payments.revalidate-pending');

    Route::post('/payments/{payment}/revalidate', [PagBankSyncController::class, 'revalidate'])
        ->middleware('throttle:admin-sync')
        ->name('payments.revalidate');

    Route::get('/settings/payments', [AdminPaymentSettingsController::class, 'edit'])->name('settings.payments.edit');
    Route::put('/settings/payments', [AdminPaymentSettingsController::class, 'update'])->name('settings.payments.update');
    Route::post('/settings/payments/test', [AdminPaymentSettingsController::class, 'testConnection'])->name('settings.payments.test');

    // Integrações e cookies de serviço (prompt 48 §2): sessão do Instagram
    // (instagrapi) com indicador de status e ação "Testar conexão".
    Route::get('/settings/integrations', [AdminIntegrationSettingsController::class, 'edit'])->name('settings.integrations.edit');
    Route::put('/settings/integrations', [AdminIntegrationSettingsController::class, 'update'])->name('settings.integrations.update');
    Route::post('/settings/integrations/test', [AdminIntegrationSettingsController::class, 'testConnection'])
        ->middleware('throttle:10,1')->name('settings.integrations.test');

    Route::get('/investigations', [AdminInvestigationController::class, 'index'])->name('investigations.index');
    Route::get('/investigations/{investigation}', [AdminInvestigationController::class, 'show'])->name('investigations.show');

    Route::get('/sources', [AdminSourceController::class, 'index'])->name('sources.index');
    Route::patch('/sources/{source}/cost', [AdminSourceController::class, 'updateCost'])->name('sources.update-cost');
    Route::post('/sources/{source}/toggle', [AdminSourceController::class, 'toggle'])->name('sources.toggle');

    Route::get('/audit', [AdminAuditController::class, 'index'])->name('audit.index');

    /*
    |----------------------------------------------------------------------
    | Testes individuais de módulo (prompt 47)
    |----------------------------------------------------------------------
    | Área de diagnóstico: dispara **um** módulo por vez no worker real e
    | acompanha status, tempo, resultados, erros e consumo de créditos. O
    | throttle evita rajadas de consultas externas a partir da interface.
    */
    Route::get('/testes', [AdminModuleTestController::class, 'index'])->name('tests.index');
    Route::post('/testes', [AdminModuleTestController::class, 'store'])
        ->middleware('throttle:module-test')->name('tests.store');
    Route::get('/testes/{test}', [AdminModuleTestController::class, 'show'])->name('tests.show');
    Route::get('/testes/{test}/status', [AdminModuleTestController::class, 'status'])->name('tests.status');
    Route::post('/testes/{test}/rerun', [AdminModuleTestController::class, 'rerun'])
        ->middleware('throttle:module-test')->name('tests.rerun');
});

Route::middleware('auth')->group(function () {
    Route::get('/credits', [PaymentController::class, 'index'])->name('payments.index');
    Route::post('/credits/{package}/checkout', [PaymentController::class, 'store'])->middleware('throttle:10,1')->name('payments.store');
    Route::get('/payments/{payment}', [PaymentController::class, 'show'])->name('payments.show');
    // Acompanhamento do PIX no modal (JSON): reconsulta o provedor e devolve o
    // estado atual do pedido. Nada é liberado aqui — a confirmação continua
    // sendo idempotente e baseada apenas na resposta do provedor.
    Route::get('/payments/{payment}/status', [PaymentController::class, 'status'])->middleware('throttle:60,1')->name('payments.status');
    // "Já paguei": o comprador pede a verificação agora e o servidor consulta o
    // provedor antes de liberar qualquer crédito (idempotente; também cobre o
    // webhook que não chegou). Cada clique gasta uma consulta à API: limitado.
    Route::post('/payments/{payment}/verify', [PaymentController::class, 'verify'])
        ->middleware('throttle:payment-verify')->name('payments.verify');
    Route::post('/payments/{payment}/simulate', [PaymentController::class, 'simulate'])->name('payments.simulate');

    Route::get('/investigations', [InvestigationController::class, 'index'])->name('investigations.index');
    Route::get('/investigations/create', [InvestigationController::class, 'create'])->name('investigations.create');
    Route::post('/investigations', [InvestigationController::class, 'store'])->middleware('throttle:10,1')->name('investigations.store');
    Route::get('/investigations/{investigation}', [InvestigationController::class, 'show'])->name('investigations.show');
    // Estado observável (JSON) para o polling da página de resultados: o navegador
    // acompanha o processamento sem `F5`. Nada é liberado aqui — só leitura.
    Route::get('/investigations/{investigation}/status', [InvestigationController::class, 'status'])->middleware('throttle:60,1')->name('investigations.status');
    Route::post('/investigations/{investigation}/runs', [InvestigationController::class, 'rerun'])->middleware('throttle:10,1')->name('investigations.rerun');

    Route::get('/investigations/{investigation}/runs/{run}/report', [ReportController::class, 'show'])->name('investigations.report');

    Route::get('/account/privacy', [AccountController::class, 'privacy'])->name('account.privacy');
    Route::get('/account/export', [AccountController::class, 'export'])->name('account.export');
});

Route::post('/payments/webhook', [PaymentController::class, 'webhook'])->name('payments.webhook');
Route::post('/webhooks/payments/pagbank', [PaymentController::class, 'webhook'])->name('payments.webhook.pagbank');

// Retorno do checkout (URL assinada e temporária): consulta o provedor e, se o
// pagamento já estiver aprovado, confirma o pedido na hora (sem depender do
// webhook). Sempre idempotente e nunca baseado em parâmetro de URL.
Route::get('/payments/return/{payment}', [PaymentController::class, 'returnStatus'])
    ->middleware('signed')
    ->name('payments.return');

/*
|--------------------------------------------------------------------------
| Investigação avulsa (prompt 41) — jornada pública, sem cadastro
|--------------------------------------------------------------------------
| O token é a única credencial de acesso ao resultado: nenhuma rota aceita o
| id da investigação. As páginas com token respondem `no-store` + `noindex`
| (GuestAccessHeaders) e são limitadas por IP e por hash do token.
*/
Route::prefix('investigacao')->name('guest.')->group(function () {
    Route::get('/', [GuestInvestigationController::class, 'create'])
        ->middleware('throttle:guest-create')->name('investigations.create');

    Route::post('/', [GuestInvestigationController::class, 'store'])
        ->middleware('throttle:guest-create')->name('investigations.store');

    Route::prefix('acesso/{token}')
        ->middleware(['throttle:guest-access', GuestAccessHeaders::class])
        ->group(function () {
            Route::get('/', [GuestInvestigationController::class, 'show'])->name('investigations.show');
            Route::get('/status', [GuestInvestigationController::class, 'status'])->name('investigations.status');
            Route::get('/relatorio', [GuestInvestigationController::class, 'report'])->name('investigations.report');
            Route::post('/pix/confirmar', [GuestPaymentController::class, 'simulate'])->name('payments.simulate');
            // "Já paguei": consulta o PagBank sob demanda (token = credencial) e
            // libera a investigação só se o provedor confirmar o pagamento.
            Route::post('/pagamento/verificar', [GuestPaymentController::class, 'verify'])
                ->middleware('throttle:guest-verify')->name('payments.verify');
        });
});

require __DIR__.'/auth.php';
