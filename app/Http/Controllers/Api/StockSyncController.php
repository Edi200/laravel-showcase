<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Store;
use App\Support\NormalizeSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

class StockSyncController extends Controller
{
    public function sync(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $store = Store::query()
                ->where('user_id', $user?->id)
                ->first();

            if ($store === null || $store->type !== 'shop') {
                return response()->json([
                    'message' => 'Only shop stores can use this endpoint.',
                ], 403);
            }

            /** @var array{items: array<int, array<string, mixed>>} $validated */
            $validated = $request->validate([
                'items' => ['required', 'array', 'max:1000'],
                'items.*.erp_id' => ['required', 'string', 'max:20'],
                'items.*.quantity' => ['required', 'numeric', 'min:0'],
                'items.*.price' => ['nullable', 'numeric', 'min:0'],
                'items.*.name_sr' => ['nullable', 'string', 'max:255'],
                'items.*.gtin' => ['nullable', 'string', 'max:30'],
                'items.*.category_name' => ['nullable', 'string', 'max:100'],
                'items.*.unit' => ['nullable', 'string', 'max:20'],
            ]);

            $updated = 0;
            $created = 0;
            $notFound = 0;
            $errors = [];

            foreach ($validated['items'] as $item) {
                $product = Product::query()
                    ->where('store_id', $store->id)
                    ->where('erp_id', $item['erp_id'])
                    ->first();

                if ($product === null) {
                    if (filled($item['name_sr'] ?? null)) {
                        $categoryName = filled($item['category_name'] ?? null)
                            ? $item['category_name']
                            : 'Ostalo';
                        $category = ProductCategory::query()->firstOrCreate(
                            [
                                'store_id' => $store->id,
                                'name_sr' => $categoryName,
                            ],
                            [
                                'sort_order' => ((int) ProductCategory::query()->where('store_id', $store->id)->max('sort_order')) + 1,
                            ],
                        );

                        $stock = round((float) $item['quantity'], 3);
                        $price = isset($item['price']) && (float) $item['price'] > 0
                            ? round((float) $item['price'], 2)
                            : 0.00;
                        $unit = filled($item['unit'] ?? null) ? $item['unit'] : 'KOM';

                        $newProduct = new Product;
                        $newProduct->store_id = $store->id;
                        $newProduct->product_category_id = $category->id;
                        $newProduct->erp_id = $item['erp_id'];
                        $newProduct->name_sr = $item['name_sr'];
                        $newProduct->gtin = $item['gtin'] ?? null;
                        $newProduct->unit = $unit;
                        $newProduct->price = $price;
                        $newProduct->stock = $stock;
                        $newProduct->is_available = $stock > 0;
                        $newProduct->sort_order = ((int) Product::query()->where('store_id', $store->id)->max('sort_order')) + 1;
                        $newProduct->name_sr_search = NormalizeSearch::normalize($item['name_sr']);
                        $newProduct->name_hu_search = null;
                        $newProduct->saveQuietly();

                        $created++;
                    } else {
                        $notFound++;
                        $errors[] = [
                            'erp_id' => $item['erp_id'],
                            'reason' => 'Product not found',
                        ];
                    }

                    continue;
                }

                $product->stock = round((float) $item['quantity'], 3);
                $product->is_available = (float) $product->stock > 0;

                if (array_key_exists('price', $item) && $item['price'] !== null && $item['price'] > 0) {
                    $product->price = (float) $item['price'];
                }

                if (filled($item['name_sr'] ?? null)) {
                    $product->name_sr = $item['name_sr'];
                    $product->name_sr_search = NormalizeSearch::normalize($item['name_sr']);
                }

                if (array_key_exists('gtin', $item)) {
                    $product->gtin = $item['gtin'] ?? null;
                }

                if (filled($item['unit'] ?? null)) {
                    $product->unit = $item['unit'];
                }

                if (filled($item['category_name'] ?? null)) {
                    $categoryName = $item['category_name'];
                    $category = ProductCategory::query()->firstOrCreate(
                        [
                            'store_id' => $store->id,
                            'name_sr' => $categoryName,
                        ],
                        [
                            'sort_order' => ((int) ProductCategory::query()->where('store_id', $store->id)->max('sort_order')) + 1,
                        ],
                    );
                    $product->product_category_id = $category->id;
                }

                $product->saveQuietly();
                $updated++;
            }

            return response()->json([
                'updated' => $updated,
                'created' => $created,
                'not_found' => $notFound,
                'errors' => $errors,
            ]);
        } catch (Throwable $exception) {
            if ($exception instanceof ValidationException) {
                throw $exception;
            }

            return response()->json([
                'message' => 'Unexpected error during stock sync.',
            ], 500);
        }
    }
}
