<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaleController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);

        $query = Sale::with('user:id,name')->withCount('items')->latest('id');

        // Attendants only see their own sales
        if ($request->user()->role !== 'admin') {
            $query->where('user_id', $request->user()->id);
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->to);
        }

        $sales = $query->limit(200)->get();

        if ($request->user()->role !== 'admin') {
            $sales->each->makeHidden('total_profit');
        }

        return $sales;
    }

    public function show(Request $request, Sale $sale)
    {
        $user = $request->user();

        if ($user->role !== 'admin' && $sale->user_id !== $user->id) {
            return response()->json(['message' => 'You can only view your own sales.'], 403);
        }

        return $this->present($sale->load(['items.product:id,name', 'user:id,name']), $request);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
        ]);

        // Combine lines that repeat the same product
        $lines = collect($data['items'])
            ->groupBy('product_id')
            ->map(fn ($group) => (int) $group->sum('quantity'));

        $sale = DB::transaction(function () use ($lines, $request) {
            // Lock the products so two sales at the same moment cannot sell the same last unit
            $products = Product::whereIn('id', $lines->keys())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $total = 0;
            $profit = 0;

            foreach ($lines as $productId => $quantity) {
                $product = $products[$productId];

                if ($product->quantity < $quantity) {
                    throw ValidationException::withMessages([
                        'items' => ["Not enough {$product->name} in stock. Only {$product->quantity} left."],
                    ]);
                }

                $total += $product->selling_price * $quantity;
                $profit += ($product->selling_price - $product->cost_price) * $quantity;
            }

            $sale = Sale::create([
                'user_id' => $request->user()->id,
                'total_amount' => round($total, 2),
                'total_profit' => round($profit, 2),
            ]);

            foreach ($lines as $productId => $quantity) {
                $product = $products[$productId];

                // Prices are saved on the item so old sales never change
                SaleItem::create([
                    'sale_id' => $sale->id,
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                    'selling_price' => $product->selling_price,
                    'cost_price' => $product->cost_price,
                ]);

                $product->decrement('quantity', $quantity);

                StockMovement::create([
                    'product_id' => $product->id,
                    'user_id' => $request->user()->id,
                    'type' => 'sale',
                    'quantity_change' => -$quantity,
                    'reason' => 'Sale #' . $sale->id,
                ]);
            }

            return $sale;
        });

        return response()->json(
            $this->present($sale->load(['items.product:id,name', 'user:id,name']), $request),
            201
        );
    }

    // Hides profit and cost price from attendants
    private function present(Sale $sale, Request $request): Sale
    {
        if ($request->user()->role !== 'admin') {
            $sale->makeHidden('total_profit');
            $sale->items->each->makeHidden('cost_price');
        }

        return $sale;
    }
}