<?php

namespace App\Http\Controllers;

use App\Services\MerchantDashboard;
use Illuminate\Http\Request;

class MerchantDashboardController extends Controller
{
    public function show(Request $request, int $id, MerchantDashboard $dashboard)
    {
        if ((int) $request->attributes->get('merchant_id') !== $id) {
            return response()->json(['message' => 'Merchant access denied.'], 403);
        }

        return response()->json($dashboard->forMerchant($id));
    }
}
