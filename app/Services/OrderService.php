<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ReturnRequest;
use App\Models\User;
use App\Notifications\OrderCancelledNotification;
use App\Notifications\OrderConfirmedNotification;
use App\Notifications\OrderStatusChangedNotification;
use App\Notifications\RefundProcessedNotification;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * OrderService
 *
 * Handles business logic for order placement, lifecycle management,
 * refunds, fulfilment, and webhook processing.
 */
class OrderService
{
    public function __construct(
        private readonly CartService    $cartService,
        private readonly PaymentService $paymentService,
        private readonly InventoryService $inventoryService,
    ) {}

    // -------------------------------------------------------------------------
    // Order placement
    // -------------------------------------------------------------------------

    /**
     * Create a new order from the user's current cart.
     *
     * Validates stock, calculates totals (including coupon discounts),
     * decrements inventory, clears the cart, and creates a payment intent.
     *
     * @param  User   $user
     * @param  array  $data  {shipping_address_id, billing_address_id, coupon_code?, notes?}
     * @return Order
     * @throws \Exception on insufficient stock or payment failure
     */
    public function placeOrder(User $user, array $data): Order
    {
        return DB::transaction(function () use ($user, $data) {
            $cart = $this->cartService->getWithItems($user);

            if ($cart->items->isEmpty()) {
                throw new \RuntimeException('Cannot place an order with an empty cart.');
            }

            // Validate stock for all items
            foreach ($cart->items as $item) {
                $this->inventoryService->assertSufficientStock($item->product, $item->quantity);
            }

            $totals = $this->cartService->calculateTotals($cart, $data['coupon_code'] ?? null);

            $order = Order::create([
                'order_number'       => $this->generateOrderNumber(),
                'user_id'            => $user->id,
                'shipping_address_id' => $data['shipping_address_id'],
                'billing_address_id'  => $data['billing_address_id'] ?? $data['shipping_address_id'],
                'subtotal'           => $totals['subtotal'],
                'shipping_cost'      => $totals['shipping_cost'],
                'discount_amount'    => $totals['discount_amount'],
                'tax_amount'         => $totals['tax_amount'],
                'total_amount'       => $totals['total_amount'],
                'coupon_code'        => $data['coupon_code'] ?? null,
                'notes'              => $data['notes'] ?? null,
                'status'             => 'pending',
                'payment_status'     => 'pending',
            ]);

            // Create order items and decrement stock
            foreach ($cart->items as $item) {
                OrderItem::create([
                    'order_id'    => $order->id,
                    'product_id'  => $item->product_id,
                    'quantity'    => $item->quantity,
                    'unit_price'  => $item->product->effective_price,
                    'line_total'  => $item->quantity * $item->product->effective_price,
                    'product_snapshot' => $item->product->only(['name', 'sku', 'brand']),
                ]);

                $this->inventoryService->decrement($item->product, $item->quantity);
            }

            // Create Stripe payment intent
            $intent = $this->paymentService->createIntent($order);
            $order->update([
                'payment_intent_id'            => $intent['id'],
                'payment_intent_client_secret' => $intent['client_secret'],
            ]);

            // Clear cart
            $this->cartService->clear($user);

            // Notify customer
            $user->notify(new OrderConfirmedNotification($order));

            return $order;
        });
    }

    // -------------------------------------------------------------------------
    // Order lifecycle management
    // -------------------------------------------------------------------------

    /**
     * Cancel an order and restore inventory.
     *
     * @param  Order   $order
     * @param  string  $reason
     * @return void
     */
    public function cancelOrder(Order $order, string $reason): void
    {
        if (! $order->is_cancellable) {
            throw new \RuntimeException("Order #{$order->order_number} cannot be cancelled in its current state.");
        }

        DB::transaction(function () use ($order, $reason) {
            $order->update(['status' => 'cancelled']);
            $order->notes()->create(['note' => "Cancellation reason: {$reason}", 'type' => 'cancellation']);

            // Restore stock
            foreach ($order->items as $item) {
                $this->inventoryService->restore($item->product, $item->quantity);
            }

            // Refund if already paid
            if ($order->payment_status === 'paid') {
                $this->paymentService->refundFull($order);
            }

            $order->user->notify(new OrderCancelledNotification($order));
        });
    }

    /**
     * Update an order's status (admin action).
     *
     * @param  int    $orderId
     * @param  string $status
     * @param  array  $meta   {tracking_number?, note?}
     * @return Order
     */
    public function updateStatus(int $orderId, string $status, array $meta = []): Order
    {
        $order = Order::findOrFail($orderId);

        $update = ['status' => $status];
        if ($status === 'shipped' && ! empty($meta['tracking_number'])) {
            $update['tracking_number'] = $meta['tracking_number'];
        }

        $order->update($update);

        if (! empty($meta['note'])) {
            $order->notes()->create(['note' => $meta['note'], 'type' => 'admin']);
        }

        $order->user->notify(new OrderStatusChangedNotification($order));
        return $order->fresh();
    }

    /**
     * Create a return request for an order.
     *
     * @param  Order   $order
     * @param  array   $items   [{order_item_id, quantity}]
     * @param  string  $reason
     * @return ReturnRequest
     */
    public function requestReturn(Order $order, array $items, string $reason): ReturnRequest
    {
        if (! $order->is_returnable) {
            throw new \RuntimeException("Order #{$order->order_number} is not eligible for return.");
        }

        return DB::transaction(function () use ($order, $items, $reason) {
            $return = ReturnRequest::create([
                'order_id'   => $order->id,
                'user_id'    => $order->user_id,
                'reason'     => $reason,
                'status'     => 'pending',
                'items_json' => json_encode($items),
            ]);

            Log::info("Return request {$return->id} created for order {$order->order_number}.");
            return $return;
        });
    }

    /**
     * Process a full or partial refund for an order (admin).
     *
     * @param  int    $orderId
     * @param  float  $amount
     * @param  string $reason
     * @return \App\Models\Refund
     */
    public function processRefund(int $orderId, float $amount, string $reason)
    {
        $order = Order::findOrFail($orderId);

        $refund = DB::transaction(function () use ($order, $amount, $reason) {
            $refund = $this->paymentService->refundPartial($order, $amount, $reason);
            $isFullRefund = abs($amount - $order->total_amount) < 0.01;

            $order->update([
                'payment_status' => $isFullRefund ? 'refunded' : 'partial_refund',
                'status'         => $isFullRefund ? 'refunded' : $order->status,
            ]);

            $order->user->notify(new RefundProcessedNotification($order, $refund));
            return $refund;
        });

        return $refund;
    }

    /**
     * Assign fulfilment warehouse or partner to an order (admin).
     *
     * @param  int    $orderId
     * @param  array  $data  {warehouse_id?, partner_id?}
     * @return void
     */
    public function assignFulfillment(int $orderId, array $data): void
    {
        $order = Order::findOrFail($orderId);
        $order->update(array_filter($data));
        $order->update(['status' => 'processing']);
    }

    // -------------------------------------------------------------------------
    // Tracking & Invoices
    // -------------------------------------------------------------------------

    /**
     * Retrieve live tracking data for an order's shipment.
     *
     * @param  Order  $order
     * @return array
     */
    public function getTracking(Order $order): array
    {
        if (! $order->shipment) {
            return ['status' => 'not_shipped', 'events' => []];
        }

        return $this->paymentService->getCarrierTracking($order->shipment);
    }

    /**
     * Generate and stream an invoice PDF for download.
     *
     * @param  Order  $order
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function generateInvoicePdf(Order $order)
    {
        // In a real implementation this would use dompdf/barryvdh/laravel-dompdf
        $html = view('invoices.order', compact('order'))->render();
        return response($html, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => "attachment; filename=invoice-{$order->order_number}.pdf",
        ]);
    }

    // -------------------------------------------------------------------------
    // Export
    // -------------------------------------------------------------------------

    /**
     * Export orders matching the given filters as a CSV download.
     *
     * @param  array  $filters  {status?, date_from?, date_to?}
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function exportCsv(array $filters = [])
    {
        $query = Order::with(['user', 'items'])
            ->when($filters['status']    ?? null, fn($q, $s) => $q->where('status', $s))
            ->when($filters['date_from'] ?? null, fn($q, $d) => $q->dateFrom($d))
            ->when($filters['date_to']   ?? null, fn($q, $d) => $q->dateTo($d));

        $headers = [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename=orders-export.csv',
        ];

        return response()->stream(function () use ($query) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Order #', 'Customer', 'Email', 'Total', 'Status', 'Date']);

            $query->chunk(500, function ($orders) use ($handle) {
                foreach ($orders as $order) {
                    fputcsv($handle, [
                        $order->order_number,
                        $order->user->name,
                        $order->user->email,
                        number_format($order->total_amount, 2),
                        $order->status,
                        $order->created_at->toDateString(),
                    ]);
                }
            });

            fclose($handle);
        }, 200, $headers);
    }

    // -------------------------------------------------------------------------
    // Webhook
    // -------------------------------------------------------------------------

    /**
     * Process an inbound shipment update webhook.
     *
     * @param  array  $payload
     * @return void
     */
    public function handleShipmentWebhook(array $payload): void
    {
        $order = Order::where('order_number', $payload['reference'] ?? '')->first();
        if (! $order) {
            Log::warning('Shipment webhook: order not found', $payload);
            return;
        }

        $order->shipment?->update([
            'carrier_status' => $payload['status'],
            'location'       => $payload['location'] ?? null,
            'updated_at'     => now(),
        ]);

        if ($payload['status'] === 'delivered') {
            $order->update(['status' => 'delivered']);
            $order->user->notify(new OrderStatusChangedNotification($order));
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function generateOrderNumber(): string
    {
        return 'ORD-' . strtoupper(Str::random(3)) . '-' . now()->format('YmdHis');
    }
}
