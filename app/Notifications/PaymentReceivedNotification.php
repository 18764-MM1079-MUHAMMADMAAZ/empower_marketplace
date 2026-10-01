<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Notifications\Notification;

/** Database-only — the matching email (AdminPaymentReceivedMail) is still sent separately at each
 *  call site (checkout and trial-conversion billing); this just also surfaces the event in the
 *  admin notification bell. */
class PaymentReceivedNotification extends Notification
{
    public function __construct(public Order $order) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        $order = $this->order;

        return [
            'title' => 'Payment received',
            'message' => sprintf(
                '%s paid $%s for %s.',
                $order->user?->name ?? 'A client',
                number_format((float) $order->amount_paid, 2),
                $order->package?->name ?? 'a package',
            ),
            'url' => route('admin.orders.edit', $order),
            'icon' => 'payment',
        ];
    }
}
