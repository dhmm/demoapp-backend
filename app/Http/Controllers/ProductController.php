<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\Product\StoreProductRequest;
use App\Http\Requests\Product\UpdateProductRequest;
use App\Models\Product;
use App\Services\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;

/**
 * ProductController
 *
 * Public browsing, search, and admin CRUD for the product catalogue.
 */
class ProductController extends Controller
{
    public function __construct(
        private readonly ProductService $productService,
    ) {}

    // -------------------------------------------------------------------------
    // Public endpoints
    // -------------------------------------------------------------------------

    /**
     * List all published products with filtering, sorting, and pagination.
     *
     * GET /api/v1/products
     *
     * Query params:
     *   filter[category_id], filter[brand], filter[min_price], filter[max_price],
     *   filter[in_stock], filter[rating_min], sort (price, -price, rating, newest),
     *   per_page, page
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $products = QueryBuilder::for(Product::published())
            ->allowedFilters([
                AllowedFilter::exact('category_id'),
                AllowedFilter::exact('brand'),
                AllowedFilter::scope('min_price'),
                AllowedFilter::scope('max_price'),
                AllowedFilter::scope('in_stock'),
                AllowedFilter::scope('rating_min'),
                AllowedFilter::partial('name'),
            ])
            ->allowedSorts([
                AllowedSort::field('price'),
                AllowedSort::field('created_at', 'newest'),
                AllowedSort::field('average_rating', 'rating'),
                AllowedSort::field('sold_count'),
            ])
            ->with(['category', 'primaryImage', 'brand'])
            ->withAvg('reviews', 'rating')
            ->withCount('reviews')
            ->paginate($request->integer('per_page', 24));

        return response()->json($products);
    }

    /**
     * Retrieve a single product by ID with full details.
     *
     * GET /api/v1/products/{id}
     *
     * @param  int  $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        $product = Product::published()
            ->with(['category', 'images', 'brand', 'attributes', 'variants'])
            ->withAvg('reviews', 'rating')
            ->withCount('reviews')
            ->findOrFail($id);

        return response()->json(['product' => $product]);
    }

    /**
     * Full-text search across product names, descriptions, and tags.
     *
     * GET /api/v1/products/search?q=keyword
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function search(Request $request): JsonResponse
    {
        $request->validate(['q' => 'required|string|min:2|max:100']);

        $results = $this->productService->fullTextSearch(
            $request->q,
            $request->integer('per_page', 24)
        );

        return response()->json($results);
    }

    /**
     * Get a curated list of featured products.
     *
     * GET /api/v1/products/featured
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function featured(Request $request): JsonResponse
    {
        $products = $this->productService->getFeatured($request->integer('limit', 12));
        return response()->json(['products' => $products]);
    }

    /**
     * Get the top-selling products.
     *
     * GET /api/v1/products/bestsellers
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function bestsellers(Request $request): JsonResponse
    {
        $products = $this->productService->getBestsellers($request->integer('limit', 12));
        return response()->json(['products' => $products]);
    }

    /**
     * Get newly arrived products (last 30 days).
     *
     * GET /api/v1/products/new-arrivals
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function newArrivals(Request $request): JsonResponse
    {
        $products = $this->productService->getNewArrivals($request->integer('limit', 12));
        return response()->json(['products' => $products]);
    }

    /**
     * Get products currently on sale.
     *
     * GET /api/v1/products/on-sale
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function onSale(Request $request): JsonResponse
    {
        $products = $this->productService->getOnSale($request->integer('limit', 24));
        return response()->json(['products' => $products]);
    }

    /**
     * Get related products based on category and tags.
     *
     * GET /api/v1/products/{id}/related
     *
     * @param  int  $id
     * @return JsonResponse
     */
    public function related(int $id): JsonResponse
    {
        $product  = Product::findOrFail($id);
        $related  = $this->productService->getRelated($product, 8);
        return response()->json(['products' => $related]);
    }

    // -------------------------------------------------------------------------
    // Admin endpoints
    // -------------------------------------------------------------------------

    /**
     * Create a new product (admin).
     *
     * POST /api/v1/admin/products
     *
     * @param  StoreProductRequest  $request
     * @return JsonResponse  201
     */
    public function store(StoreProductRequest $request): JsonResponse
    {
        $product = $this->productService->create($request->validated());
        return response()->json(['message' => 'Product created.', 'product' => $product], 201);
    }

    /**
     * Update an existing product (admin).
     *
     * PUT /api/v1/admin/products/{id}
     *
     * @param  UpdateProductRequest  $request
     * @param  int                   $id
     * @return JsonResponse
     */
    public function update(UpdateProductRequest $request, int $id): JsonResponse
    {
        $product = $this->productService->update($id, $request->validated());
        return response()->json(['message' => 'Product updated.', 'product' => $product]);
    }

    /**
     * Permanently delete a product (admin).
     *
     * DELETE /api/v1/admin/products/{id}
     *
     * @param  int  $id
     * @return JsonResponse  204
     */
    public function destroy(int $id): JsonResponse
    {
        $this->productService->delete($id);
        return response()->json(null, 204);
    }

    /**
     * Upload additional images for a product (admin).
     *
     * POST /api/v1/admin/products/{id}/images
     *
     * @param  Request  $request
     * @param  int      $id
     * @return JsonResponse
     */
    public function uploadImages(Request $request, int $id): JsonResponse
    {
        $request->validate(['images' => 'required|array|max:10', 'images.*' => 'image|max:5120']);
        $images = $this->productService->uploadImages($id, $request->file('images'));

        return response()->json(['message' => 'Images uploaded.', 'images' => $images]);
    }

    /**
     * Delete a specific image from a product (admin).
     *
     * DELETE /api/v1/admin/products/{id}/images/{imageId}
     *
     * @param  int  $id
     * @param  int  $imageId
     * @return JsonResponse  204
     */
    public function deleteImage(int $id, int $imageId): JsonResponse
    {
        $this->productService->deleteImage($id, $imageId);
        return response()->json(null, 204);
    }

    /**
     * Publish a draft product (admin).
     *
     * PUT /api/v1/admin/products/{id}/publish
     *
     * @param  int  $id
     * @return JsonResponse
     */
    public function publish(int $id): JsonResponse
    {
        $this->productService->setStatus($id, 'published');
        return response()->json(['message' => 'Product published.']);
    }

    /**
     * Unpublish a product, hiding it from public browsing (admin).
     *
     * PUT /api/v1/admin/products/{id}/unpublish
     *
     * @param  int  $id
     * @return JsonResponse
     */
    public function unpublish(int $id): JsonResponse
    {
        $this->productService->setStatus($id, 'draft');
        return response()->json(['message' => 'Product unpublished.']);
    }

    /**
     * Bulk-import products from a CSV or JSON file (admin).
     *
     * POST /api/v1/admin/products/bulk-import
     *
     * @param  Request  $request
     * @return JsonResponse  Import summary
     */
    public function bulkImport(Request $request): JsonResponse
    {
        $request->validate(['file' => 'required|file|mimes:csv,json|max:10240']);
        $summary = $this->productService->bulkImport($request->file('file'));

        return response()->json([
            'message'  => 'Import completed.',
            'imported' => $summary['imported'],
            'skipped'  => $summary['skipped'],
            'errors'   => $summary['errors'],
        ]);
    }

    /**
     * Bulk-delete multiple products (admin).
     *
     * POST /api/v1/admin/products/bulk-delete
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function bulkDelete(Request $request): JsonResponse
    {
        $request->validate(['ids' => 'required|array|min:1', 'ids.*' => 'integer']);
        $count = $this->productService->bulkDelete($request->ids);

        return response()->json(['message' => "{$count} products deleted."]);
    }

    /**
     * Export the full product catalogue as CSV (admin).
     *
     * GET /api/v1/admin/products/export
     *
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function export()
    {
        return $this->productService->exportCsv();
    }
}
