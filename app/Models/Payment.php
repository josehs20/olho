<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * Pedido avulso (prompt 41): sem usuário, vinculado a uma investigação.
     */
    public function isGuest(): bool
    {
        return $this->guest_investigation_id !== null;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creditPackage(): BelongsTo
    {
        return $this->belongsTo(CreditPackage::class);
    }

    public function guestInvestigation(): BelongsTo
    {
        return $this->belongsTo(Investigation::class, 'guest_investigation_id');
    }

    public function paymentEvents(): HasMany
    {
        return $this->hasMany(PaymentEvent::class);
    }
}
