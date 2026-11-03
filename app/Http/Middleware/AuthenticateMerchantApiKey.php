<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuthenticateMerchantApiKey
{
    public function handle(Request $request, Closure $next)
    {
        $key = $request->header('X-Merchant-Key');

        if (! is_string($key) || ! preg_match('/^[a-f0-9]{64}$/', $key)) {
            return response()->json(['message' => 'Invalid merchant API key.'], 401);
        }

        $record = DB::table('merchant_api_keys')
            ->join('merchants', 'merchants.id', '=', 'merchant_api_keys.merchant_id')
            ->where('merchant_api_keys.key_hash', hash('sha256', $key))
            ->where('merchant_api_keys.is_active', true)
            ->where('merchants.status', 'active')
            ->select('merchant_api_keys.id', 'merchant_api_keys.merchant_id')
            ->first();

        if (! $record) {
            return response()->json(['message' => 'Invalid merchant API key.'], 401);
        }

        $request->attributes->set('merchant_id', (int) $record->merchant_id);
        $request->attributes->set('merchant_api_key_id', (int) $record->id);

        return $next($request);
    }
}
