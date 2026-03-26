<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\Review\StoreReviewRequest;
use App\Http\Requests\Review\UpdateReviewRequest;
use App\Models\Review;
use App\Services\ReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;

/**
 * ReviewController
 *
 * Customer review submission, editing, and voting.
 * Admin review moderation (approve / reject / delete).
 */
class ReviewController extends Controller
{
    public function __construct(
        private readonly ReviewService $reviewService,
    ) {}

    // -------------------------------------------------------------------------
    // Public endpoints
    // -------------------------------------------------------------------------

    /**
     * List approved reviews for a specific product.
     *
     * GET /api/v1/products/{id}/reviews
     *
     * @param  Request  $request
     * @param  int      $id  Product ID
     * @return JsonResponse  Paginated reviews with rating breakdown
     */
    public function forProduct(Request $request, int $id): JsonResponse
    {
        $data = $this->reviewService->getForProduct(
            $id,
            $request->integer('per_page', 10),
            $request->get('sort', 'newest')
        );

        return response()->json($data);
    }

    // -------------------------------------------------------------------------
    // Authenticated customer endpoints
    // -------------------------------------------------------------------------

    /**
     * Submit a new review for a purchased product.
     *
     * POST /api/v1/reviews
     *
     * @param  StoreReviewRequest  $request
     * @return JsonResponse  201
     */
    public function store(StoreReviewRequest $request): JsonResponse
    {
        $review = $this->reviewService->create(Auth::user(), $request->validated());
        return response()->json(['message' => 'Review submitted and pending approval.', 'review' => $review], 201);
    }

    /**
     * Edit the authenticated user's own review.
     *
     * PUT /api/v1/reviews/{id}
     *
     * @param  UpdateReviewRequest  $request
     * @param  int                  $id
     * @return JsonResponse
     */
    public function update(UpdateReviewRequest $request, int $id): JsonResponse
    {
        $review = Review::where('user_id', Auth::id())->findOrFail($id);
        $review = $this->reviewService->update($review, $request->validated());

        return response()->json(['message' => 'Review updated.', 'review' => $review]);
    }

    /**
     * Delete the authenticated user's own review.
     *
     * DELETE /api/v1/reviews/{id}
     *
     * @param  int  $id
     * @return JsonResponse  204
     */
    public function destroy(int $id): JsonResponse
    {
        $review = Review::where('user_id', Auth::id())->findOrFail($id);
        $this->reviewService->delete($review);

        return response()->json(null, 204);
    }

    /**
     * Mark a review as helpful (thumbs-up vote).
     *
     * POST /api/v1/reviews/{id}/helpful
     *
     * @param  int  $id
     * @return JsonResponse  Updated helpful count
     */
    public function markHelpful(int $id): JsonResponse
    {
        $count = $this->reviewService->toggleHelpful(Auth::user(), $id);
        return response()->json(['helpful_count' => $count]);
    }

    /**
     * Report a review for inappropriate content.
     *
     * POST /api/v1/reviews/{id}/report
     *
     * @param  Request  $request
     * @param  int      $id
     * @return JsonResponse
     */
    public function report(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'reason'  => 'required|string|in:spam,offensive,misleading,other',
            'details' => 'nullable|string|max:500',
        ]);

        $this->reviewService->report(Auth::user(), $id, $request->reason, $request->details);
        return response()->json(['message' => 'Review reported. Our team will investigate.']);
    }

    /**
     * List all reviews submitted by the authenticated user.
     *
     * GET /api/v1/reviews/my-reviews
     *
     * @param  Request  $request
     * @return JsonResponse  Paginated
     */
    public function myReviews(Request $request): JsonResponse
    {
        $reviews = Review::where('user_id', Auth::id())
            ->with(['product.primaryImage'])
            ->latest()
            ->paginate($request->integer('per_page', 10));

        return response()->json($reviews);
    }

    // -------------------------------------------------------------------------
    // Admin endpoints
    // -------------------------------------------------------------------------

    /**
     * List all reviews with filtering for moderation (admin).
     *
     * GET /api/v1/admin/reviews
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function adminIndex(Request $request): JsonResponse
    {
        $reviews = QueryBuilder::for(Review::class)
            ->allowedFilters([
                AllowedFilter::exact('status'),
                AllowedFilter::exact('product_id'),
                AllowedFilter::exact('user_id'),
                AllowedFilter::scope('reported'),
                AllowedFilter::scope('rating_min'),
                AllowedFilter::scope('rating_max'),
            ])
            ->allowedSorts(['created_at', 'rating', 'helpful_count', 'reports_count'])
            ->with(['user', 'product'])
            ->paginate($request->integer('per_page', 25));

        return response()->json($reviews);
    }

    /**
     * Approve a pending or reported review (admin).
     *
     * PUT /api/v1/admin/reviews/{id}/approve
     *
     * @param  int  $id
     * @return JsonResponse
     */
    public function approve(int $id): JsonResponse
    {
        $review = $this->reviewService->setStatus($id, 'approved');
        return response()->json(['message' => 'Review approved.', 'review' => $review]);
    }

    /**
     * Reject a review and hide it from public display (admin).
     *
     * PUT /api/v1/admin/reviews/{id}/reject
     *
     * @param  Request  $request
     * @param  int      $id
     * @return JsonResponse
     */
    public function reject(Request $request, int $id): JsonResponse
    {
        $request->validate(['reason' => 'nullable|string|max:255']);
        $review = $this->reviewService->setStatus($id, 'rejected', $request->reason);

        return response()->json(['message' => 'Review rejected.', 'review' => $review]);
    }

    /**
     * Permanently delete a review (admin).
     *
     * DELETE /api/v1/admin/reviews/{id}
     *
     * @param  int  $id
     * @return JsonResponse  204
     */
    public function adminDestroy(int $id): JsonResponse
    {
        Review::findOrFail($id)->delete();
        return response()->json(null, 204);
    }
}
