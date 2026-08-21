<?php

namespace App\Http\Controllers\StudentInfo;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class StudentController extends Controller
{
    // Group 5: Add student info logic here
    public function index() {
        return response()->json(['message' => 'Student info endpoint placeholder']);
    }
}
