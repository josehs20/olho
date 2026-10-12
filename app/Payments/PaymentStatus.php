<?php

namespace App\Payments;

enum PaymentStatus: string
{
    case Pending = 'pending';
    case AwaitingPayment = 'awaiting_payment';
    case Paid = 'paid';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';
    case Failed = 'failed';

    /**
     * Traduz o status do PagBank (transacional ou de checkout) para o status
     * interno. Nunca inventamos estados: valores desconhecidos caem em
     * `AwaitingPayment` (pagamento ainda não confirmado), nunca em `Paid`.
     */
    public static function fromPagBank(?string $status): self
    {
        return match (mb_strtoupper((string) $status)) {
            'PAID' => self::Paid,
            'IN_ANALYSIS', 'WAITING', 'ACTIVE' => self::AwaitingPayment,
            'DECLINED' => self::Failed,
            'CANCELED', 'CANCELLED', 'INACTIVE' => self::Cancelled,
            'EXPIRED' => self::Expired,
            'REFUNDED' => self::Refunded,
            default => self::AwaitingPayment,
        };
    }
}
