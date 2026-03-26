<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\UpdateProfileRequest;
use App\Http\Requests\User\UpdateAvatarRequest;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;

/**
 * UserController
 *
 * Handles customer profile management and admin user administration.
 * Customer-facing routes are under /api/v1/users.
 * Admin routes are under /api/v1/admin/users.
 */
class UserController extends Controller
{
    public function __construct(
        private readonly UserService $userService,
    ) {}

    // -------------------------------------------------------------------------
    // Customer-facing endpoints
    // -------------------------------------------------------------------------

    /**
     * Get the authenticated user's profile.
     *
     * GET /api/v1/users/profile
     *
     * @return JsonResponse
     */
    public function profile(): JsonResponse
    {
        $user = Auth::user()->load(['addresses', 'roles']);
        return response()->json(['user' => $user]);
    }

    /**
     * Update the authenticated user's profile information.
     *
     * PUT /api/v1/users/profile
     *
     * @param  UpdateProfileRequest  $request
     * @return JsonResponse
     */
    public function updateProfile(UpdateProfileRequest $request): JsonResponse
    {
        $user = $this->userService->updateProfile(Auth::user(), $request->validated());
        return response()->json(['message' => 'Profile updated.', 'user' => $user]);
    }

    /**
     * Upload or replace the authenticated user's avatar image.
     *
     * PUT /api/v1/users/avatar
     *
     * @param  UpdateAvatarRequest  $request
     * @return JsonResponse
     */
    public function updateAvatar(UpdateAvatarRequest $request): JsonResponse
    {
        $url = $this->userService->uploadAvatar(Auth::user(), $request->file('avatar'));
        return response()->json(['message' => 'Avatar updated.', 'avatar_url' => $url]);
    }

    /**
     * Permanently delete the authenticated user's account.
     *
     * DELETE /api/v1/users/account
     *
     * @param  Request  $request
     * @return JsonResponse  204 No Content
     */
    public function deleteAccount(Request $request): JsonResponse
    {
        $request->validate(['password' => 'required|string']);
        $this->userService->deleteAccount(Auth::user(), $request->password);

        return response()->json(null, 204);
    }

    /**
     * Get the authenticated user's full order history.
     *
     * GET /api/v1/users/order-history
     *
     * @param  Request  $request
     * @return JsonResponse  Paginated order list
     */
    public function orderHistory(Request $request): JsonResponse
    {
        $orders = $this->userService->getOrderHistory(
            Auth::user(),
            $request->integer('per_page', 15)
        );

        return response()->json($orders);
    }

    /**
     * Get a summary of the user's purchase activity.
     *
     * GET /api/v1/users/purchase-summary
     *
     * @return JsonResponse  Totals, item counts, favourite categories
     */
    public function purchaseSummary(): JsonResponse
    {
        $summary = $this->userService->getPurchaseSummary(Auth::user());
        return response()->json(['summary' => $summary]);
    }

    /**
     * List all notifications for the authenticated user.
     *
     * GET /api/v1/users/notifications
     *
     * @param  Request  $request
     * @return JsonResponse  Paginated notifications
     */
    public function notifications(Request $request): JsonResponse
    {
        $notifications = Auth::user()
            ->notifications()
            ->paginate($request->integer('per_page', 20));

        return response()->json($notifications);
    }

    /**
     * Mark a single notification as read.
     *
     * PUT /api/v1/users/notifications/{id}/read
     *
     * @param  string  $id
     * @return JsonResponse
     */
    public function markNotificationRead(string $id): JsonResponse
    {
        Auth::user()->notifications()->findOrFail($id)->markAsRead();
        return response()->json(['message' => 'Notification marked as read.']);
    }

    /**
     * Mark all notifications for the user as read.
     *
     * PUT /api/v1/users/notifications/read-all
     *
     * @return JsonResponse
     */
    public function markAllNotificationsRead(): JsonResponse
    {
        Auth::user()->unreadNotifications->markAsRead();
        return response()->json(['message' => 'All notifications marked as read.']);
    }

    // -------------------------------------------------------------------------
    // Admin endpoints
    // -------------------------------------------------------------------------

    /**
     * List all users with filtering, sorting, and pagination (admin).
     *
     * GET /api/v1/admin/users
     *
     * Query params: filter[role], filter[status], filter[search], sort, per_page
     *
     * @param  Request  $request
     * @return JsonResponse  Paginated user list
     */
    public function adminIndex(Request $request): JsonResponse
    {
        $users = QueryBuilder::for(User::class)
            ->allowedFilters([
                AllowedFilter::exact('role'),
                AllowedFilter::exact('status'),
                AllowedFilter::scope('search'),
            ])
            ->allowedSorts(['name', 'email', 'created_at', 'orders_count'])
            ->withCount('orders')
            ->paginate($request->integer('per_page', 25));

        return response()->json($users);
    }

    /**
     * Get detailed information about a specific user (admin).
     *
     * GET /api/v1/admin/users/{id}
     *
     * @param  int  $id
     * @return JsonResponse
     */
    public function adminShow(int $id): JsonResponse
    {
        $user = User::with(['orders', 'addresses', 'reviews', 'roles'])->findOrFail($id);
        return response()->json(['user' => $user]);
    }

    /**
     * Update any user's information (admin).
     *
     * PUT /api/v1/admin/users/{id}
     *
     * @param  Request  $request
     * @param  int      $id
     * @return JsonResponse
     */
    public function adminUpdate(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'name'   => 'sometimes|string|max:255',
            'email'  => "sometimes|email|unique:users,email,{$id}",
            'role'   => 'sometimes|string|in:customer,admin,manager',
            'status' => 'sometimes|string|in:active,banned,pending',
        ]);

        $user = $this->userService->adminUpdate($id, $validated);
        return response()->json(['message' => 'User updated.', 'user' => $user]);
    }

    /**
     * Ban a user account, preventing login.
     *
     * PUT /api/v1/admin/users/{id}/ban
     *
     * @param  Request  $request
     * @param  int      $id
     * @return JsonResponse
     */
    public function ban(Request $request, int $id): JsonResponse
    {
        $request->validate(['reason' => 'required|string|max:500']);
        $this->userService->ban($id, $request->reason);

        return response()->json(['message' => "User {$id} has been banned."]);
    }

    /**
     * Lift the ban on a user account.
     *
     * PUT /api/v1/admin/users/{id}/unban
     *
     * @param  int  $id
     * @return JsonResponse
     */
    public function unban(int $id): JsonResponse
    {
        $this->userService->unban($id);
        return response()->json(['message' => "User {$id} has been unbanned."]);
    }

    /**
     * Permanently delete a user account (admin, hard delete).
     *
     * DELETE /api/v1/admin/users/{id}
     *
     * @param  int  $id
     * @return JsonResponse  204 No Content
     */
    public function adminDestroy(int $id): JsonResponse
    {
        $this->userService->adminDelete($id);
        return response()->json(null, 204);
    }
}
