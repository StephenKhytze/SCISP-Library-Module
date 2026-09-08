<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Fine;
use Illuminate\Http\Request;

class FinesController extends Controller
{
    public function index()
    {
        return response()->json(Fine::with('user')->where('status', 'Unpaid')->get());
    }

    public function clear(Request $request)
    {
        Fine::where('status', 'Unpaid')->update(['status' => 'Paid']);
        return response()->json(['message' => 'All fines cleared successfully']);
    }
}
