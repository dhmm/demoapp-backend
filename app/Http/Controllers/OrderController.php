<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\Order\StoreOrderRequest;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;

/**
 * OrderController
 *
 * Customer order placement and history, plus admin order management,
 * fulfilment, refunds, and export.
 */
class OrderController extends Controller
{
    public function __construct(
        private readonly OrderService $orderService,
    ) {}

    // -------------------------------------------------------------------------
    // Customer endpoints
    // -------------------------------------------------------------------------

    /**
     * List the authenticated user's orders.
     *
     * GET /api/v1/orders
     *
     * @param  Request  $request
     * @return JsonResponse  Paginated orders
     */
    public function index(Request $request): JsonResponse
    {
        $orders = Order::where('user_id', Auth::id())
            ->with(['items.product', 'shippingAddress', 'payment'])
            ->latest()
            ->paginate($request->integer('per_page', 10));

        return response()->json($orders);
    }

    /**
     * Place a new order from the current cart.
     *
     * POST /api/v1/orders
     *
     * @param  StoreOrderRequest  $request
     * @return JsonResponse  201 with order details and payment intent
     */
    public function store(StoreOrderRequest $request): JsonResponse
    {
        $order = $this->orderService->placeOrder(Auth::user(), $request->validated());

        return response()->json([
            'message'        => 'Order placed successfully.',
            'order'          => $order,
            'payment_intent' => $order->payment_intent_client_secret,
        ], 201);
    }

    /**
     * Get full details of a specific order belonging to the authenticated user.
     *
     * GET /api/v1/orders/{id}
     *
     * @param  int  $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        $order = Order::where('user_id', Auth::id())
            ->with(['items.product.primaryImage', 'shippingAddress', 'billingAddress', 'payment', 'shipment'])
            ->findOrFail($id);

        return response()->json(['order' => $order]);
    }

    /**
     * Request cancellation of an order.
     *
     * POST /api/v1/orders/{id}/cancel
     *
     * @param  Request  $request
     * @param  int      $id
     * @return JsonResponse
     */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $request->validate(['reason' => 'nullable|string|max:500']);

        $order = Order::where('user_id', Auth::id())->findOrFail($id);
        $this->orderService->cancelOrder($order, $request->reason ?? '');

        return response()->json(['message' => 'Cancellation request submitted.', 'order' => $order->fresh()]);
    }

    /**
     * Request a return or refund for a delivered order.
     *
     * POST /api/v1/orders/{id}/return
     *
     * @param  Request  $request
     * @param  int      $id
     * @return JsonResponse
     */
    public function requestReturn(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'items'  => 'required|array|min:1',
            'items.*.order_item_id' => 'required|integer',
            'items.*.quantity'      => 'required|integer|min:1',
            'reason' => 'required|string|max:1000',
        ]);

        $order  = Order::where('user_id', Auth::id())->findOrFail($id);
        $return = $this->orderService->requestReturn($order, $request->items, $request->reason);

        return response()->json([
            'message'   => 'Return request submitted.',
            'return_id' => $return->id,
        ], 201);
    }

    /**
     * Download the invoice PDF for an order.
     *
     * GET /api/v1/orders/{id}/invoice
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response  PDF download
     */
    public function downloadInvoice(int $id)
    {
        $order = Order::where('user_id', Auth::id())->findOrFail($id);
        return $this->orderService->generateInvoicePdf($order);
    }

    /**
     * Get live shipment tracking information for an order.
     *
     * GET /api/v1/orders/{id}/tracking
     *
     * @param  int  $id
     * @return JsonResponse
     */
    public function trackShipment(int $id): JsonResponse
    {
        $order    = Order::where('user_id', Auth::id())->findOrFail($id);
        $tracking = $this->orderService->getTracking($order);

        return response()->json(['tracking' => $tracking]);
    }

    // -------------------------------------------------------------------------
    // Admin endpoints
    // -------------------------------------------------------------------------

    /**
     * List all orders with advanced filtering (admin).
     *
     * GET /api/v1/admin/orders
     *
     * Query params: filter[status], filter[user_id], filter[date_from],
     *               filter[date_to], filter[payment_status], sort, per_page
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function adminIndex(Request $request): JsonResponse
    {
        $orders = QueryBuilder::for(Order::class)
            ->allowedFilters([
                AllowedFilter::exact('status'),
                AllowedFilter::exact('user_id'),
                AllowedFilter::exact('payment_status'),
                AllowedFilter::scope('date_from'),
                AllowedFilter::scope('date_to'),
                AllowedFilter::scope('search'),
            ])
            ->allowedSorts(['created_at', 'total_amount', 'status'])
            ->with(['user', 'items', 'payment'])
            ->paginate($request->integer('per_page', 25));

        return response()->json($orders);
    }

    /**
     * Get full admin view of a single order.
     *
     * GET /api/v1/admin/orders/{id}
     *
     * @param  int  $id
     * @return JsonResponse
     */
    public function adminShow(int $id): JsonResponse
    {
        $order = Order::with([
            'user', 'items.product', 'shippingAddress',
            'billingAddress', 'payment', 'shipment', 'notes',
        ])->findOrFail($id);

        return response()->json(['order' => $order]);
    }

    /**
     * Update the status of an order (admin).
     *
     * PUT /api/v1/admin/orders/{id}/status
     *
     * @param  Request  $request
     * @param  int      $id
     * @return JsonResponse
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'status'          => 'required|string|in:pending,confirmed,processing,shipped,delivered,cancelled,refunded',
            'tracking_number' => 'nullable|string|max:100',
            'note'            => 'nullable|string|max:500',
        ]);

        $order = $this->orderService->updateStatus($id, $request->status, [
            'tracking_number' => $request->tracking_number,
            'note'            => $request->note,
        ]);

        return response()->json(['message' => 'Order status updated.', 'order' => $order]);
    }

    /**
     * Assign fulfilment warehouse or partner to an order (admin).
     *
     * PUT /api/v1/admin/orders/{id}/assign
     *
     * @param  Request  $request
     * @param  int      $id
     * @return JsonResponse
     */
    public function assignFulfillment(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'warehouse_id' => 'required_without:partner_id|integer|exists:warehouses,id',
            'partner_id'   => 'required_without:warehouse_id|integer|exists:fulfillment_partners,id',
        ]);

        $this->orderService->assignFulfillment($id, $request->validated());
        return response()->json(['message' => 'Fulfilment assigned.']);
    }

    /**
     * Process a full or partial refund for an order (admin).
     *
     * POST /api/v1/admin/orders/{id}/refund
     *
     * @param  Request  $request
     * @param  int      $id
     * @return JsonResponse
     */
    public function processRefund(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'reason' => 'required|string|max:500',
        ]);

        $refund = $this->orderService->processRefund($id, $request->amount, $request->reason);

        return response()->json([
            'message'   => 'Refund processed.',
            'refund_id' => $refund->id,
            'amount'    => $refund->amount,
        ]);
    }

    /**
     * Bulk-export orders as CSV (admin).
     *
     * POST /api/v1/admin/orders/bulk-export
     *
     * @param  Request  $request
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function bulkExport(Request $request)
    {
        $request->validate([
            'status'    => 'nullable|string',
            'date_from' => 'nullable|date',
            'date_to'   => 'nullable|date|after_or_equal:date_from',
        ]);

        return $this->orderService->exportCsv($request->validated());
    }

    // -------------------------------------------------------------------------
    // Webhook handler
    // -------------------------------------------------------------------------

    /**
     * Handle inbound shipment status webhooks from logistics providers.
     *
     * POST /webhooks/shipment
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function shipmentWebhook(Request $request): JsonResponse
    {
        $this->orderService->handleShipmentWebhook($request->all());
        return response()->json(['received' => true]);
    }
}
