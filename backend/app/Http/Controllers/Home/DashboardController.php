<?php

namespace App\Http\Controllers\Home;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    // Group 1: Add dashboard logic here
    public function index() {
        return response()->json(['message' => 'Dashboard endpoint placeholder']);
    }
}
