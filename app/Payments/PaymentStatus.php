<?php

namespace App\Payments;

enum PaymentStatus: string
{
    case Pending = 'pending';
    case AwaitingPayment = 'awaiting_payment';

    /**
     * O comprador informou que já pagou o link do banco e o pedido está na fila
     * de conferência manual. Nunca é confundido com `Paid`: créditos e execução
     * só são liberados pela confirmação do administrador.
     */
    case AwaitingManualConfirmation = 'awaiting_manual_confirmation';

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
