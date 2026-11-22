<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StockMovementController extends Controller
{
    public function index(Request $request)
    {
        $query = StockMovement::with(['product:id,name', 'user:id,name'])->latest('id');

        if ($request->filled('product_id')) {
            $query->where('product_id', $request->product_id);
        }

        return $query->limit(200)->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'product_id' => 'required|exists:products,id',
            'type' => 'required|in:stock_in,adjustment',
            'quantity_change' => [
                'required', 'integer', 'not_in:0',
                Rule::when($request->input('type') === 'stock_in', ['min:1']),
            ],
            'reason' => [
                Rule::requiredIf($request->input('type') === 'adjustment'),
                'nullable', 'string', 'max:255',
            ],
        ]);

        $movement = DB::transaction(function () use ($data, $request) {
            // Lock the row so two people changing stock at once cannot clash
            $product = Product::lockForUpdate()->findOrFail($data['product_id']);
            $newQuantity = $product->quantity + $data['quantity_change'];

            if ($newQuantity < 0) {
                throw ValidationException::withMessages([
                    'quantity_change' => [
                        "Cannot remove " . abs($data['quantity_change']) . " units. Only {$product->quantity} in stock.",
                    ],
                ]);
            }

            $product->update(['quantity' => $newQuantity]);

            return StockMovement::create([
                'product_id' => $product->id,
                'user_id' => $request->user()->id,
                'type' => $data['type'],
                'quantity_change' => $data['quantity_change'],
                'reason' => $data['reason'] ?? null,
            ]);
        });

        return response()->json($movement->load(['product:id,name,quantity', 'user:id,name']), 201);
    }
}