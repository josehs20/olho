<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CreditPackage extends Model
{
    use HasFactory;

    /** Pacote de créditos (fluxo autenticado). */
    public const KIND_CREDITS = 'credits';

    /**
     * Preço fixo da investigação avulsa (prompt 41).
     *
     * Não concede créditos: o visitante paga um valor fixo por uma execução
     * ("pagou, usou, acabou").
     */
    public const KIND_GUEST_INVESTIGATION = 'guest_investigation';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'credits' => 'integer',
            'price_cents' => 'integer',
            'active' => 'boolean',
            'display_order' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function isGuestKind(): bool
    {
        return $this->kind === self::KIND_GUEST_INVESTIGATION;
    }

    /**
     * O pacote pode ser vendido pelo link do banco?
     *
     * Sem link não há como cobrar neste provedor: a UI desabilita o botão e o
     * backend recusa a cobrança em vez de criar um pedido sem forma de pagamento.
     */
    public function hasPaymentLink(): bool
    {
        return is_string($this->payment_link) && trim($this->payment_link) !== '';
    }

    /**
     * @param  Builder<CreditPackage>  $query
     * @return Builder<CreditPackage>
     */
    public function scopeOfKind(Builder $query, string $kind): Builder
    {
        return $query->where('kind', $kind);
    }

    /**
     * @param  Builder<CreditPackage>  $query
     * @return Builder<CreditPackage>
     */
    public function scopeGuestKind(Builder $query): Builder
    {
        return $query->where('kind', self::KIND_GUEST_INVESTIGATION);
    }
}
