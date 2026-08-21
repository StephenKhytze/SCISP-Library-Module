<?php

namespace App\Http\Controllers\Schedule;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class ScheduleController extends Controller
{
    // Group 2: Add schedule logic here
    public function index() {
        return response()->json(['message' => 'Schedule endpoint placeholder']);
    }
}
