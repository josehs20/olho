<?php

namespace Tests\Feature;

use App\Models\CreditPackage;
use App\Models\CreditReservation;
use App\Models\CreditTransaction;
use App\Models\Investigation;
use App\Models\InvestigationRun;
use App\Models\SourceRun;
use App\Models\User;
use App\Models\Wallet;
use App\Payments\PaymentStatus;
use App\Payments\VerifiedPaymentEvent;
use App\Services\GuestInvestigationService;
use App\Services\PaymentService;
use Database\Seeders\CreditPackageSeeder;
use Database\Seeders\SourceDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Jornada avulsa (prompt 41): visitante sem conta, preço fixo, sem créditos.
 */
class GuestInvestigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SourceDefinitionSeeder::class);
        $this->seed(CreditPackageSeeder::class);

        config(['queue.default' => 'sync', 'cache.default' => 'array']);
    }

    private function pricePackage(): CreditPackage
    {
        return CreditPackage::query()->guestKind()->firstOrFail();
    }

    /**
     * @return array{0: Investigation, 1: string} investigação e token em claro
     */
    private function startGuest(string $username = 'joao.silva'): array
    {
        $response = $this->post(route('guest.investigations.store'), ['username' => $username]);
        $response->assertRedirect();

        $investigation = Investigation::query()->latest('id')->firstOrFail();
        $token = basename((string) parse_url((string) $response->headers->get('Location'), PHP_URL_PATH));

        return [$investigation, $token];
    }

    public function test_public_form_shows_the_fixed_price_without_login(): void
    {
        $response = $this->get(route('guest.investigations.create'));

        $response->assertOk();
        $response->assertSee('Investigação avulsa');
        $response->assertSee(number_format($this->pricePackage()->price_cents / 100, 2, ',', '.'));
    }

    public function test_guest_starts_investigation_without_account_and_never_touches_credits(): void
    {
        Queue::fake();

        [$investigation, $token] = $this->startGuest();

        $this->assertTrue($investigation->isGuest());
        $this->assertNull($investigation->user_id);
        $this->assertSame(Investigation::STATUS_DRAFT, $investigation->status);
        $this->assertSame($this->pricePackage()->price_cents, $investigation->guest_price_cents);
        $this->assertSame(64, strlen($token));
        $this->assertSame(Investigation::hashAccessToken($token), $investigation->access_token_hash);

        // Nada executa antes do pagamento: nenhum job, nenhum run iniciado.
        Queue::assertNothingPushed();
        $this->assertSame(InvestigationRun::STATUS_PENDING, $investigation->currentRun->status);
        $this->assertSame(0, SourceRun::query()->count());

        // Billing: pagamento fixo com PIX, sem qualquer movimento de créditos.
        $payment = $investigation->guestPayment;
        $this->assertNotNull($payment);
        $this->assertTrue($payment->isGuest());
        $this->assertNull($payment->user_id);
        $this->assertSame($this->pricePackage()->price_cents, $payment->amount_cents);
        $this->assertSame($this->pricePackage()->id, $payment->credit_package_id);
        $this->assertSame(PaymentStatus::AwaitingPayment->value, $payment->status);
        $this->assertNotEmpty($payment->metadata['pix_payload'] ?? null);

        $this->assertSame(0, CreditReservation::query()->count());
        $this->assertSame(0, CreditTransaction::query()->count());
        $this->assertSame(0, Wallet::query()->count());

        // Acompanhamento acessível sem login.
        $this->get(route('guest.investigations.show', ['token' => $token]))->assertOk();
    }

    public function test_payment_confirmation_releases_execution_and_exposes_report_through_token(): void
    {
        [$investigation, $token] = $this->startGuest('maria.souza');

        $this->post(route('guest.payments.simulate', ['token' => $token]))->assertRedirect();

        $investigation->refresh();
        $run = $investigation->currentRun;

        $this->assertContains($investigation->status, [Investigation::STATUS_COMPLETED, Investigation::STATUS_PARTIAL]);
        $this->assertSame(0, (int) $run->credits_reserved);
        $this->assertSame(0, (int) $run->credits_consumed);
        $this->assertSame('guest_prepaid', $run->metadata['funding'] ?? null);
        $this->assertGreaterThan(0, SourceRun::query()->count());
        $this->assertSame(0, CreditReservation::query()->count());
        $this->assertSame(0, CreditTransaction::query()->count());

        $this->get(route('guest.investigations.show', ['token' => $token]))->assertOk();
        $this->get(route('guest.investigations.report', ['token' => $token]))
            ->assertOk()
            ->assertSee('maria.souza');
    }

    public function test_status_endpoint_reports_progress_for_the_token_owner(): void
    {
        [, $token] = $this->startGuest();

        $this->getJson(route('guest.investigations.status', ['token' => $token]))
            ->assertOk()
            ->assertJsonPath('progress.status', Investigation::STATUS_DRAFT)
            ->assertJsonPath('payment.status', PaymentStatus::AwaitingPayment->value);
    }

    public function test_guest_page_never_trusts_client_side_payment_flags(): void
    {
        [$investigation, $token] = $this->startGuest();

        // Não existe rota que aceite "pago" vindo do front: POST no GET é 405.
        $this->post(route('guest.investigations.show', ['token' => $token]), ['paid' => true])
            ->assertStatus(405);

        $investigation->refresh();
        $this->assertSame(Investigation::STATUS_DRAFT, $investigation->status);
        $this->assertSame(PaymentStatus::AwaitingPayment->value, $investigation->guestPayment->status);
        $this->assertSame(0, SourceRun::query()->count());
    }

    public function test_guest_investigations_stay_out_of_account_history(): void
    {
        $user = User::factory()->create();

        [, $token] = $this->startGuest('visitante.avulso.x');

        Investigation::query()->create([
            'user_id' => $user->id,
            'origin' => Investigation::ORIGIN_ACCOUNT,
            'title' => 'Investigação da conta',
            'status' => Investigation::STATUS_QUEUED,
            'selected_modules' => ['mock-username'],
        ]);

        $this->actingAs($user)
            ->get(route('investigations.index'))
            ->assertOk()
            ->assertSee('Investigação da conta')
            ->assertDontSee('visitante.avulso.x');

        $guest = Investigation::query()->where('origin', Investigation::ORIGIN_GUEST)->firstOrFail();

        $this->actingAs($user)
            ->get(route('investigations.show', $guest))
            ->assertNotFound();

        $this->assertSame(1, $user->investigations()->count());
        $this->assertSame(0, $user->investigations()->where('origin', Investigation::ORIGIN_GUEST)->count());

        // O token continua funcionando (é a credencial do visitante).
        $this->get(route('guest.investigations.show', ['token' => $token]))->assertOk();
    }

    public function test_unpaid_guest_draft_expires_without_any_execution(): void
    {
        [$investigation, $token] = $this->startGuest();

        config(['guest.unpaid_expiration_minutes' => 60]);
        $this->travel(61)->minutes();

        $this->assertSame(1, app(GuestInvestigationService::class)->expireUnpaid());

        $investigation->refresh();

        $this->assertSame(Investigation::STATUS_EXPIRED, $investigation->status);
        $this->assertSame(PaymentStatus::Expired->value, $investigation->guestPayment->status);
        $this->assertSame(InvestigationRun::STATUS_PENDING, $investigation->currentRun->status);
        $this->assertSame(0, SourceRun::query()->count());

        $this->get(route('guest.investigations.show', ['token' => $token]))
            ->assertOk()
            ->assertSee('expirou');
    }

    public function test_late_payment_confirmation_after_expiry_still_executes(): void
    {
        [$investigation] = $this->startGuest();

        config(['guest.unpaid_expiration_minutes' => 1]);
        $this->travel(10)->minutes();
        app(GuestInvestigationService::class)->expireUnpaid();

        $payment = $investigation->refresh()->guestPayment;
        $this->assertSame(PaymentStatus::Expired->value, $payment->status);

        // Webhook atrasado: o visitante pagou, então executa (só a execução sem
        // pagamento é barrada pela expiração).
        app(PaymentService::class)->confirmPayment(new VerifiedPaymentEvent(
            externalEventId: 'late-'.$payment->id,
            externalId: (string) $payment->external_id,
            status: PaymentStatus::Paid->value,
        ));

        $investigation->refresh();

        $this->assertContains($investigation->status, [Investigation::STATUS_COMPLETED, Investigation::STATUS_PARTIAL]);
        $this->assertGreaterThan(0, SourceRun::query()->count());
        $this->assertSame(0, CreditReservation::query()->count());
    }
}
