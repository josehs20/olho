<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Investigation extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    /** Investigação de usuário autenticado. */
    public const ORIGIN_ACCOUNT = 'account';

    /** Investigação avulsa, sem cadastro (prompt 41). */
    public const ORIGIN_GUEST = 'guest';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'selected_modules' => 'array',
            'guest_price_cents' => 'integer',
            'access_token_created_at' => 'datetime',
            'access_token_expires_at' => 'datetime',
        ];
    }

    /**
     * Investigação avulsa (visitante sem conta): sem dono, autorizada apenas
     * pelo token de acesso.
     */
    public function isGuest(): bool
    {
        return $this->origin === self::ORIGIN_GUEST;
    }

    /**
     * Hash do token de acesso. O token em claro nunca é persistido.
     */
    public static function hashAccessToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(InvestigationRun::class);
    }

    public function currentRun(): BelongsTo
    {
        return $this->belongsTo(InvestigationRun::class, 'current_run_id');
    }

    /**
     * Pedido PIX da investigação avulsa (um por investigação).
     */
    public function guestPayment(): HasOne
    {
        return $this->hasOne(Payment::class, 'guest_investigation_id');
    }
}
