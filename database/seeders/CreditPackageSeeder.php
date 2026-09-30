<?php

namespace Database\Seeders;

use App\Models\CreditPackage;
use Illuminate\Database\Seeder;

/**
 * Pacotes de créditos iniciais para a tela `/credits` e o preço fixo da
 * investigação avulsa (prompt 41).
 *
 * Determinístico e idempotente. Preserva a coluna `active`, operada pelo admin
 * em `/admin/packages`, e também o preço da avulsa — que é editado pelo admin e
 * nunca deve ser sobrescrito por um re-seed.
 */
class CreditPackageSeeder extends Seeder
{
    /**
     * @var array<int, array{name: string, credits: int, price_cents: int, display_order: int, active: bool}>
     */
    private const PACKAGES = [
        ['name' => 'Básico — 100 créditos', 'credits' => 100, 'price_cents' => 1990, 'display_order' => 1, 'active' => true],
        ['name' => 'Profissional — 500 créditos', 'credits' => 500, 'price_cents' => 7990, 'display_order' => 2, 'active' => true],
        ['name' => 'Escala — 2.000 créditos', 'credits' => 2000, 'price_cents' => 24900, 'display_order' => 3, 'active' => true],
    ];

    private const GUEST_PACKAGE_NAME = 'Investigação avulsa';

    private const GUEST_PACKAGE_PRICE_CENTS = 2990;

    public function run(): void
    {
        foreach (self::PACKAGES as $package) {
            $model = CreditPackage::query()->firstOrNew(['name' => $package['name']]);

            $model->fill([
                'kind' => CreditPackage::KIND_CREDITS,
                'credits' => $package['credits'],
                'price_cents' => $package['price_cents'],
                'display_order' => $package['display_order'],
            ]);

            if (! $model->exists) {
                $model->active = $package['active'];
            }

            $model->save();
        }

        // Preço fixo da investigação avulsa: criado uma única vez (sem créditos).
        $guest = CreditPackage::query()->guestKind()->first();

        if ($guest === null) {
            CreditPackage::query()->create([
                'name' => self::GUEST_PACKAGE_NAME,
                'kind' => CreditPackage::KIND_GUEST_INVESTIGATION,
                'credits' => 0,
                'price_cents' => self::GUEST_PACKAGE_PRICE_CENTS,
                'display_order' => 0,
                'active' => true,
            ]);
        }
    }
}
