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
Route::prefix('library')->middleware(\App\Http\Middleware\MockAuthMiddleware::class)->group(function () {
    Route::get('/', [LibraryController::class, 'index']);
    
    // Public Catalog Search (Authenticated users)
    Route::get('/books', [\App\Http\Controllers\Api\BookController::class, 'index']);
    Route::get('/books/{id}', [\App\Http\Controllers\Api\BookController::class, 'show']);
    
    // My Loans & Holds
    Route::get('/loans/me', [\App\Http\Controllers\Api\CirculationController::class, 'myLoans']);
    Route::post('/loans/renew', [\App\Http\Controllers\Api\CirculationController::class, 'renew']);
    Route::get('/holds/me', [\App\Http\Controllers\Api\HoldController::class, 'myHolds']);
    
    // Automated Hold Queue (Authenticated users)
    Route::post('/books/{id}/holds', [\App\Http\Controllers\Api\HoldController::class, 'store']);
    Route::delete('/holds/{id}', [\App\Http\Controllers\Api\HoldController::class, 'destroy']);
    
    // Course Sections (Faculty & Students)
    Route::get('/sections/me', [\App\Http\Controllers\Api\CourseSectionController::class, 'mySections']);
    Route::get('/sections', [\App\Http\Controllers\Api\CourseSectionController::class, 'index']);
    Route::post('/sections', [\App\Http\Controllers\Api\CourseSectionController::class, 'store']);
    Route::get('/students', [\App\Http\Controllers\Api\CourseSectionController::class, 'getStudents']);
    Route::post('/sections/{sectionId}/students', [\App\Http\Controllers\Api\CourseSectionController::class, 'addStudent']);
    Route::delete('/sections/{sectionId}/students/{studentId}', [\App\Http\Controllers\Api\CourseSectionController::class, 'removeStudent']);

    // Course Reserves
    Route::get('/reserves', [\App\Http\Controllers\Api\ReserveController::class, 'index']);
    Route::post('/reserves', [\App\Http\Controllers\Api\ReserveController::class, 'store']);
    Route::put('/reserves/{id}/status', [\App\Http\Controllers\Api\ReserveController::class, 'updateStatus']); // Admin approve/deny & Teacher release
    Route::post('/reserves/{id}/allocate', [\App\Http\Controllers\Api\ReserveController::class, 'allocateCopies']); // Admin allocate
    
    // Admin Inventory Management & Circulation
    Route::middleware(\App\Http\Middleware\MockAuthMiddleware::class . ':Super Admin,Admin')->group(function () {
        Route::post('/books', [\App\Http\Controllers\Api\BookController::class, 'store']);
        Route::put('/books/{id}', [\App\Http\Controllers\Api\BookController::class, 'update']);
        Route::post('/books/{id}/copies', [\App\Http\Controllers\Api\BookCopyController::class, 'store']);
        Route::put('/copies/{id}', [\App\Http\Controllers\Api\BookCopyController::class, 'update']);
        
        // Circulation
        Route::get('/circulation', [\App\Http\Controllers\Api\CirculationController::class, 'index']);
        Route::get('/holds', [\App\Http\Controllers\Api\CirculationController::class, 'activeHolds']);
        Route::put('/holds/{id}/accept', [\App\Http\Controllers\Api\HoldController::class, 'acceptHold']);
        Route::post('/checkout', [\App\Http\Controllers\Api\CirculationController::class, 'checkout']);
        Route::post('/checkin', [\App\Http\Controllers\Api\CirculationController::class, 'checkin']);

        // Fines
        Route::get('/fines', [\App\Http\Controllers\Api\FinesController::class, 'index']);
        Route::post('/fines/clear', [\App\Http\Controllers\Api\FinesController::class, 'clear']);
    });
});
