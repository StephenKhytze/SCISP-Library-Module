<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Home\DashboardController;
use App\Http\Controllers\Schedule\ScheduleController;
use App\Http\Controllers\Announcements\AnnouncementController;
use App\Http\Controllers\Library\LibraryController;
use App\Http\Controllers\StudentInfo\StudentController;
use App\Http\Controllers\Faculty\FacultyController;

Route::get('/test', function () {
    return response()->json([
        'status' => 'success',
        'message' => 'API is working properly!'
    ]);
});

/*
|--------------------------------------------------------------------------
| Group 1: Auth & Home
|--------------------------------------------------------------------------
*/
Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    // Group 1: Add more auth routes here
});

Route::middleware('auth.jwt')->group(function () {
    Route::prefix('home')->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index']);
        // Group 1: Add more home routes here
    });

    /*
    |--------------------------------------------------------------------------
    | Group 2: Schedule
    |--------------------------------------------------------------------------
    */
    Route::prefix('schedule')->group(function () {
        Route::get('/', [ScheduleController::class, 'index']);
        // Group 2: Add more schedule routes here
    });

    /*
    |--------------------------------------------------------------------------
    | Group 3: Announcements
    |--------------------------------------------------------------------------
    */
    Route::prefix('announcements')->group(function () {
        Route::get('/', [AnnouncementController::class, 'index']);
        // Group 3: Add more announcement routes here
    });

    /*
    |--------------------------------------------------------------------------
    | Group 5: Student Info & Faculty Directory
    |--------------------------------------------------------------------------
    */
    Route::prefix('student-info')->group(function () {
        Route::get('/', [StudentController::class, 'index']);
        // Group 5: Add more student info routes here
    });

    Route::prefix('faculty')->group(function () {
        Route::get('/', [FacultyController::class, 'index']);
        // Group 5: Add more faculty routes here
    });
});

/*
|--------------------------------------------------------------------------
| Group 4: Library
|--------------------------------------------------------------------------
*/
Route::prefix('library')->middleware(\App\Http\Middleware\ExternalAuthMiddleware::class)->group(function () {
    Route::get('/', [LibraryController::class, 'index']);
    
    // Public Catalog Search (Authenticated users)
    Route::get('/books', [\App\Http\Controllers\Api\BookController::class, 'index']);
    Route::get('/books/{id}', [\App\Http\Controllers\Api\BookController::class, 'show']);
    
    // Automated Hold Queue (Authenticated users)
    Route::post('/books/{id}/holds', [\App\Http\Controllers\Api\HoldController::class, 'store']);
    Route::delete('/holds/{id}', [\App\Http\Controllers\Api\HoldController::class, 'destroy']);
    
    // Admin Inventory Management & Circulation
    Route::middleware(\App\Http\Middleware\RequireAdminRole::class)->group(function () {
        Route::post('/books', [\App\Http\Controllers\Api\BookController::class, 'store']);
        Route::put('/books/{id}', [\App\Http\Controllers\Api\BookController::class, 'update']);
        Route::post('/books/{id}/copies', [\App\Http\Controllers\Api\BookCopyController::class, 'store']);
        Route::put('/copies/{id}', [\App\Http\Controllers\Api\BookCopyController::class, 'update']);
        
        // Circulation
        Route::post('/checkout', [\App\Http\Controllers\Api\CirculationController::class, 'checkout']);
        Route::post('/checkin', [\App\Http\Controllers\Api\CirculationController::class, 'checkin']);
    });
});
