<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * ProductService
 *
 * Business logic for product CRUD, media management, search,
 * and catalogue operations.
 */
class ProductService
{
    // -------------------------------------------------------------------------
    // CRUD
    // -------------------------------------------------------------------------

    /**
     * Create a new product and store associated images.
     *
     * @param  array  $data  Validated fields from StoreProductRequest
     * @return Product
     */
    public function create(array $data): Product
    {
        return DB::transaction(function () use ($data) {
            $data['slug'] = $this->generateUniqueSlug($data['name']);

            $images = $data['images'] ?? [];
            unset($data['images'], $data['tags'], $data['attributes']);

            $product = Product::create($data);

            if (! empty($images)) {
                $this->storeImages($product, $images);
            }

            return $product->load(['category', 'images']);
        });
    }

    /**
     * Update an existing product.
     *
     * @param  int    $id
     * @param  array  $data  Validated fields from UpdateProductRequest
     * @return Product
     */
    public function update(int $id, array $data): Product
    {
        $product = Product::findOrFail($id);

        if (isset($data['name']) && $data['name'] !== $product->name) {
            $data['slug'] = $this->generateUniqueSlug($data['name']);
        }

        $product->update($data);
        return $product->fresh(['category', 'images']);
    }

    /**
     * Soft-delete a product.
     *
     * @param  int  $id
     * @return void
     */
    public function delete(int $id): void
    {
        Product::findOrFail($id)->delete();
    }

    /**
     * Set the published/draft status of a product.
     *
     * @param  int     $id
     * @param  string  $status  published|draft|archived
     * @return void
     */
    public function setStatus(int $id, string $status): void
    {
        Product::findOrFail($id)->update(['status' => $status]);
    }

    // -------------------------------------------------------------------------
    // Curated lists
    // -------------------------------------------------------------------------

    /**
     * Get featured products.
     *
     * @param  int  $limit
     * @return Collection
     */
    public function getFeatured(int $limit = 12): Collection
    {
        return Product::published()
            ->featured()
            ->with(['primaryImage', 'category'])
            ->withAvg('reviews', 'rating')
            ->orderByDesc('sold_count')
            ->limit($limit)
            ->get();
    }

    /**
     * Get best-selling products ordered by sold_count.
     *
     * @param  int  $limit
     * @return Collection
     */
    public function getBestsellers(int $limit = 12): Collection
    {
        return Product::published()
            ->with(['primaryImage', 'category'])
            ->withAvg('reviews', 'rating')
            ->orderByDesc('sold_count')
            ->limit($limit)
            ->get();
    }

    /**
     * Get new arrivals from the last 30 days.
     *
     * @param  int  $limit
     * @return Collection
     */
    public function getNewArrivals(int $limit = 12): Collection
    {
        return Product::published()
            ->with(['primaryImage', 'category'])
            ->where('created_at', '>=', now()->subDays(30))
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Get products currently on sale.
     *
     * @param  int  $limit
     * @return Collection
     */
    public function getOnSale(int $limit = 24): Collection
    {
        return Product::published()
            ->onSale()
            ->with(['primaryImage', 'category'])
            ->orderByDesc('discount_percent')
            ->limit($limit)
            ->get();
    }

    /**
     * Get products related to the given product (same category, exclude self).
     *
     * @param  Product  $product
     * @param  int      $limit
     * @return Collection
     */
    public function getRelated(Product $product, int $limit = 8): Collection
    {
        return Product::published()
            ->where('id', '!=', $product->id)
            ->where('category_id', $product->category_id)
            ->with(['primaryImage'])
            ->withAvg('reviews', 'rating')
            ->inRandomOrder()
            ->limit($limit)
            ->get();
    }

    // -------------------------------------------------------------------------
    // Search
    // -------------------------------------------------------------------------

    /**
     * Full-text search across product names, descriptions, and tags.
     *
     * Falls back to LIKE if the database does not support FULLTEXT.
     *
     * @param  string  $query
     * @param  int     $perPage
     * @return LengthAwarePaginator
     */
    public function fullTextSearch(string $query, int $perPage = 24): LengthAwarePaginator
    {
        $term = '%' . $query . '%';

        return Product::published()
            ->where(function ($q) use ($term, $query) {
                $q->where('name', 'like', $term)
                  ->orWhere('description', 'like', $term)
                  ->orWhere('brand', 'like', $term)
                  ->orWhere('sku', 'like', $term)
                  ->orWhereHas('tags', fn ($tq) => $tq->where('name', 'like', $term));
            })
            ->with(['primaryImage', 'category'])
            ->withAvg('reviews', 'rating')
            ->paginate($perPage);
    }

    // -------------------------------------------------------------------------
    // Images
    // -------------------------------------------------------------------------

    /**
     * Upload and attach images to a product.
     *
     * @param  int    $productId
     * @param  array  $files    Array of UploadedFile instances
     * @return array  Created ProductImage records
     */
    public function uploadImages(int $productId, array $files): array
    {
        $product = Product::findOrFail($productId);
        $images  = [];

        foreach ($files as $index => $file) {
            $path = $file->store("products/{$productId}", 'public');

            $images[] = ProductImage::create([
                'product_id'  => $productId,
                'path'        => $path,
                'url'         => Storage::url($path),
                'is_primary'  => $index === 0 && $product->images()->count() === 0,
                'sort_order'  => $product->images()->max('sort_order') + $index + 1,
            ]);
        }

        return $images;
    }

    /**
     * Delete a specific product image.
     *
     * @param  int  $productId
     * @param  int  $imageId
     * @return void
     */
    public function deleteImage(int $productId, int $imageId): void
    {
        $image = ProductImage::where('product_id', $productId)->findOrFail($imageId);
        Storage::disk('public')->delete($image->path);
        $image->delete();
    }

    // -------------------------------------------------------------------------
    // Bulk operations
    // -------------------------------------------------------------------------

    /**
     * Bulk-import products from an uploaded CSV/JSON file.
     *
     * @param  \Illuminate\Http\UploadedFile  $file
     * @return array  {imported, skipped, errors}
     */
    public function bulkImport($file): array
    {
        $imported = 0;
        $skipped  = 0;
        $errors   = [];
        $records  = [];

        if ($file->getMimeType() === 'application/json') {
            $records = json_decode(file_get_contents($file->getRealPath()), true) ?? [];
        } else {
            $handle = fopen($file->getRealPath(), 'r');
            $headers = fgetcsv($handle);
            while (($row = fgetcsv($handle)) !== false) {
                $records[] = array_combine($headers, $row);
            }
            fclose($handle);
        }

        foreach ($records as $i => $row) {
            try {
                Product::updateOrCreate(['sku' => $row['sku']], [
                    'name'           => $row['name'],
                    'price'          => (float) $row['price'],
                    'stock_quantity' => (int) ($row['stock'] ?? 0),
                    'status'         => $row['status'] ?? 'draft',
                    'slug'           => $this->generateUniqueSlug($row['name']),
                ]);
                $imported++;
            } catch (\Throwable $e) {
                $skipped++;
                $errors[] = "Row {$i}: " . $e->getMessage();
            }
        }

        return compact('imported', 'skipped', 'errors');
    }

    /**
     * Bulk-delete products by IDs.
     *
     * @param  array  $ids
     * @return int  Number of deleted records
     */
    public function bulkDelete(array $ids): int
    {
        return Product::whereIn('id', $ids)->delete();
    }

    /**
     * Export all products as a CSV streamed response.
     *
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function exportCsv()
    {
        return response()->stream(function () {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['ID', 'SKU', 'Name', 'Price', 'Sale Price', 'Stock', 'Status', 'Category', 'Brand', 'Created']);

            Product::with('category')->chunk(500, function ($products) use ($handle) {
                foreach ($products as $p) {
                    fputcsv($handle, [
                        $p->id, $p->sku, $p->name, $p->price, $p->sale_price,
                        $p->stock_quantity, $p->status, $p->category?->name,
                        $p->brand, $p->created_at->toDateString(),
                    ]);
                }
            });

            fclose($handle);
        }, 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename=products-export.csv',
        ]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function generateUniqueSlug(string $name): string
    {
        $base  = Str::slug($name);
        $slug  = $base;
        $count = 1;

        while (Product::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$count}";
            $count++;
        }

        return $slug;
    }

    private function storeImages(Product $product, array $images): void
    {
        foreach ($images as $index => $file) {
            $path = $file->store("products/{$product->id}", 'public');
            ProductImage::create([
                'product_id' => $product->id,
                'path'       => $path,
                'url'        => Storage::url($path),
                'is_primary' => $index === 0,
                'sort_order' => $index,
            ]);
        }
    }
}
