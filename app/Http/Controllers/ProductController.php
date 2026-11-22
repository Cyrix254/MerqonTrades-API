<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $products = Product::with('category')->orderBy('name')->get();

        return $products->map(fn ($product) => $this->present($product, $request));
    }

    public function show(Request $request, Product $product)
    {
        return $this->present($product->load('category'), $request);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'category_id' => 'nullable|exists:categories,id',
            'name' => 'required|string|max:255',
            'sku' => 'nullable|string|max:100|unique:products,sku',
            'cost_price' => 'required|numeric|min:0',
            'selling_price' => 'required|numeric|min:0',
            'quantity' => 'nullable|integer|min:0',
            'low_stock_threshold' => 'nullable|integer|min:0',
        ]);

        $data['quantity'] = $data['quantity'] ?? 0;
        $data['low_stock_threshold'] = $data['low_stock_threshold'] ?? 5;

        $product = DB::transaction(function () use ($data, $request) {
            $product = Product::create($data);

            // Record the opening stock so the stock history is complete
            if ($product->quantity > 0) {
                StockMovement::create([
                    'product_id' => $product->id,
                    'user_id' => $request->user()->id,
                    'type' => 'stock_in',
                    'quantity_change' => $product->quantity,
                    'reason' => 'Opening stock',
                ]);
            }

            return $product;
        });

        return response()->json($this->present($product->load('category'), $request), 201);
    }

    public function update(Request $request, Product $product)
    {
        // Quantity is not editable here: it changes only through stock-in, adjustments and sales
        $data = $request->validate([
            'category_id' => 'nullable|exists:categories,id',
            'name' => 'required|string|max:255',
            'sku' => ['nullable', 'string', 'max:100', Rule::unique('products', 'sku')->ignore($product->id)],
            'cost_price' => 'required|numeric|min:0',
            'selling_price' => 'required|numeric|min:0',
            'low_stock_threshold' => 'required|integer|min:0',
        ]);

        $product->update($data);

        return $this->present($product->load('category'), $request);
    }

    public function destroy(Product $product)
    {
        if ($product->saleItems()->exists()) {
            return response()->json([
                'message' => 'This product has sales records and cannot be deleted.',
            ], 422);
        }

        $product->delete();

        return response()->json(['message' => 'Product deleted.']);
    }

    // Adds the low-stock flag and hides cost price from attendants
    private function present(Product $product, Request $request): Product
    {
        $product->setAttribute('is_low_stock', $product->isLowStock());

        if ($request->user()->role !== 'admin') {
            $product->makeHidden('cost_price');
        }

        return $product;
    }
}