<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    public function summary()
    {
        // Totals for today, this week (from Monday) and this month
        $periods = [
            'today' => now()->startOfDay(),
            'week' => now()->startOfWeek(),
            'month' => now()->startOfMonth(),
        ];

        $totals = [];
        foreach ($periods as $key => $from) {
            $row = Sale::where('created_at', '>=', $from)
                ->selectRaw('COUNT(*) as sales_count, COALESCE(SUM(total_amount), 0) as revenue, COALESCE(SUM(total_profit), 0) as profit')
                ->first();

            $totals[$key] = [
                'sales_count' => (int) $row->sales_count,
                'revenue' => (float) $row->revenue,
                'profit' => (float) $row->profit,
            ];
        }

        // Last 7 days, with zeros on days that had no sales
        $rows = Sale::where('created_at', '>=', now()->subDays(6)->startOfDay())
            ->selectRaw('DATE(created_at) as day, SUM(total_amount) as revenue, SUM(total_profit) as profit')
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        $daily = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $row = $rows->get($date);
            $daily[] = [
                'date' => $date,
                'revenue' => (float) ($row?->revenue ?? 0),
                'profit' => (float) ($row?->profit ?? 0),
            ];
        }

        // Best sellers over the last 30 days, with the profit each one made
        $topProducts = DB::table('sale_items')
            ->join('products', 'products.id', '=', 'sale_items.product_id')
            ->where('sale_items.created_at', '>=', now()->subDays(29)->startOfDay())
            ->selectRaw('products.id, products.name, SUM(sale_items.quantity) as units_sold, SUM(sale_items.quantity * sale_items.selling_price) as revenue, SUM(sale_items.quantity * (sale_items.selling_price - sale_items.cost_price)) as profit')
            ->groupBy('products.id', 'products.name')
            ->orderByDesc('units_sold')
            ->limit(5)
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'units_sold' => (int) $p->units_sold,
                'revenue' => (float) $p->revenue,
                'profit' => (float) $p->profit,
            ]);

        $lowStock = Product::whereColumn('quantity', '<=', 'low_stock_threshold')
            ->orderBy('quantity')
            ->get(['id', 'name', 'quantity', 'low_stock_threshold']);

        return response()->json([
            'totals' => $totals,
            'daily' => $daily,
            'top_products' => $topProducts,
            'low_stock' => $lowStock,
        ]);
    }
}