<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUsageRequest;
use App\Services\UsageRecorder;

class UsageController extends Controller
{
    public function store(StoreUsageRequest $request, UsageRecorder $recorder)
    {
        $result = $recorder->record((int) $request->attributes->get('merchant_id'), $request->validated());

        return response()->json($result['body'], $result['status']);
    }
}
