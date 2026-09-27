<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Library\LibraryController;

Route::prefix('library')->middleware(\App\Http\Middleware\MockAuthMiddleware::class)->group(function () {
    Route::get('/', [LibraryController::class, 'index']);
    
    // Public Catalog Search (Authenticated users)
    Route::get('/books', [\App\Http\Controllers\Api\Library\BookController::class, 'index']);
    Route::get('/categories', [\App\Http\Controllers\Api\Library\LibraryCategoryController::class, 'index']);
    Route::get('/books/{id}', [\App\Http\Controllers\Api\Library\BookController::class, 'show']);
    
    // My Loans & Holds
    Route::get('/loans/me', [\App\Http\Controllers\Api\Library\CirculationController::class, 'myLoans']);
    Route::get('/loans/me/history', [\App\Http\Controllers\Api\Library\CirculationController::class, 'myHistory']);
    // Renewal now needs a librarian's decision. Borrowers may only ask;
    // Super Admin accounts cannot borrow, so they cannot ask either.
    Route::post('/renewals', [\App\Http\Controllers\Api\Library\RenewalController::class, 'store'])
        ->middleware(\App\Http\Middleware\MockAuthMiddleware::class . ':Student,Faculty,Teacher,Admin');
    Route::get('/renewals/me', [\App\Http\Controllers\Api\Library\RenewalController::class, 'myRequests']);
    Route::get('/holds/me', [\App\Http\Controllers\Api\Library\HoldController::class, 'myHolds']);

    // Own summary (balance, active loans, borrow limit) and own fine balance.
    Route::get('/me/summary', [\App\Http\Controllers\Api\Library\CirculationController::class, 'mySummary']);
    Route::get('/fines/me', [\App\Http\Controllers\Api\Library\FinesController::class, 'myFines']);
    
    // Automated Hold Queue (Authenticated users)
    Route::post('/books/{id}/holds', [\App\Http\Controllers\Api\Library\HoldController::class, 'store']);
    Route::delete('/holds/{id}', [\App\Http\Controllers\Api\Library\HoldController::class, 'destroy']);
    
    // Course Sections (Faculty & Students)
    Route::get('/sections/me', [\App\Http\Controllers\Api\Library\CourseSectionController::class, 'mySections']);
    // D-1: roster of ONE section, authorised per-section inside the controller.
    // Deliberately not the unrestricted directory that SEC-05 locked down.
    Route::get('/sections/{sectionId}/classmates', [\App\Http\Controllers\Api\Library\CourseSectionController::class, 'classmates']);
    // Section management (H-3): faculty for their own sections, Admin and
    // Super Admin for all. Students use /sections/me and /classmates instead.
    Route::get('/sections', [\App\Http\Controllers\Api\Library\CourseSectionController::class, 'index'])
        ->middleware(\App\Http\Middleware\MockAuthMiddleware::class . ':Super Admin,Admin,Teacher,Faculty');
    Route::post('/sections', [\App\Http\Controllers\Api\Library\CourseSectionController::class, 'store'])
        ->middleware(\App\Http\Middleware\MockAuthMiddleware::class . ':Super Admin,Admin,Teacher,Faculty');
    // SEC-05: the student directory is roster tooling, not borrower-facing.
    // Faculty need it for the course-section roster UI; librarians may also use it.
    // Ordinary students must not be able to enumerate every other student.
    Route::get('/students', [\App\Http\Controllers\Api\Library\CourseSectionController::class, 'getStudents'])
        ->middleware(\App\Http\Middleware\MockAuthMiddleware::class . ':Super Admin,Admin,Teacher,Faculty');
    Route::post('/sections/{sectionId}/students', [\App\Http\Controllers\Api\Library\CourseSectionController::class, 'addStudent'])
        ->middleware(\App\Http\Middleware\MockAuthMiddleware::class . ':Super Admin,Admin,Teacher,Faculty');
    Route::delete('/sections/{sectionId}/students/{studentId}', [\App\Http\Controllers\Api\Library\CourseSectionController::class, 'removeStudent'])
        ->middleware(\App\Http\Middleware\MockAuthMiddleware::class . ':Super Admin,Admin,Teacher,Faculty');

    // Course Reserves
    // Faculty request a reserve for a section they own; that ownership check lives in
    // ReserveController::store. Admin/Super Admin may request for any section.
    // The admin-only listing (GET /reserves) is registered in the admin group below.
    Route::post('/reserves', [\App\Http\Controllers\Api\Library\ReserveController::class, 'store'])
        ->middleware(\App\Http\Middleware\MockAuthMiddleware::class . ':Super Admin,Admin,Teacher,Faculty');

    // D-3: an enrolled student asks to borrow from one course reserve. Only
    // that reserve's allocated copies are eligible; the librarian still does
    // the physical checkout.
    Route::post('/reserves/{id}/request', [\App\Http\Controllers\Api\Library\ReserveController::class, 'requestCopy'])
        ->middleware(\App\Http\Middleware\MockAuthMiddleware::class . ':Student');

    // Admin/Super Admin may approve, deny or release. Faculty may release ONLY their own
    // reserve — that ownership check lives in ReserveController::updateStatus.
    Route::put('/reserves/{id}/status', [\App\Http\Controllers\Api\Library\ReserveController::class, 'updateStatus'])
        ->middleware(\App\Http\Middleware\MockAuthMiddleware::class . ':Super Admin,Admin,Teacher,Faculty');
    
    // Admin Inventory Management & Circulation
    Route::middleware(\App\Http\Middleware\MockAuthMiddleware::class . ':Super Admin,Admin')->group(function () {
        Route::post('/books', [\App\Http\Controllers\Api\Library\BookController::class, 'store']);
        Route::put('/books/{id}', [\App\Http\Controllers\Api\Library\BookController::class, 'update']);
        Route::post('/books/{id}/copies', [\App\Http\Controllers\Api\Library\BookCopyController::class, 'store']);
        Route::put('/copies/{id}', [\App\Http\Controllers\Api\Library\BookCopyController::class, 'update']);

        // Archive one physical copy (not the whole title). The copy keeps its
        // accession number, condition, status and history; restore is exact.
        Route::post('/copies/{id}/archive', [\App\Http\Controllers\Api\Library\BookCopyController::class, 'archive']);
        Route::post('/copies/{id}/restore', [\App\Http\Controllers\Api\Library\BookCopyController::class, 'restore']);

        // Category vocabulary. Reading is open to everyone (see above);
        // only a librarian may add to it or rename one.
        Route::post('/categories', [\App\Http\Controllers\Api\Library\LibraryCategoryController::class, 'store']);
        Route::put('/categories/{id}', [\App\Http\Controllers\Api\Library\LibraryCategoryController::class, 'update']);

        // Cover images live on the TITLE, not on individual copies.
        Route::post('/books/{id}/cover', [\App\Http\Controllers\Api\Library\BookController::class, 'uploadCover']);
        Route::delete('/books/{id}/cover', [\App\Http\Controllers\Api\Library\BookController::class, 'removeCover']);

        // Archive replaces delete: history stays readable, borrowing stops.
        Route::post('/books/{id}/archive', [\App\Http\Controllers\Api\Library\BookController::class, 'archive']);
        Route::post('/books/{id}/restore', [\App\Http\Controllers\Api\Library\BookController::class, 'restore']);

        // Operational rules. These decide what every borrower may do.
        Route::get('/settings', [\App\Http\Controllers\Api\Library\LibrarySettingsController::class, 'index']);
        Route::put('/settings', [\App\Http\Controllers\Api\Library\LibrarySettingsController::class, 'update']);

        // Course Reserves (Admin only)
        Route::get('/reserves', [\App\Http\Controllers\Api\Library\ReserveController::class, 'index']);
        Route::post('/reserves/{id}/allocate', [\App\Http\Controllers\Api\Library\ReserveController::class, 'allocateCopies']);

        // Circulation
        Route::get('/circulation', [\App\Http\Controllers\Api\Library\CirculationController::class, 'index']);
        Route::get('/holds', [\App\Http\Controllers\Api\Library\CirculationController::class, 'activeHolds']);
        Route::put('/holds/{id}/accept', [\App\Http\Controllers\Api\Library\HoldController::class, 'acceptHold']);
        Route::post('/checkout', [\App\Http\Controllers\Api\Library\CirculationController::class, 'checkout']);
        Route::post('/checkin', [\App\Http\Controllers\Api\Library\CirculationController::class, 'checkin']);

        // Renewal approvals. Both administrator personas decide; neither can
        // borrow on a Super Admin account, but both run the desk.
        Route::get('/renewals', [\App\Http\Controllers\Api\Library\RenewalController::class, 'index']);
        Route::put('/renewals/{id}/approve', [\App\Http\Controllers\Api\Library\RenewalController::class, 'approve']);
        Route::put('/renewals/{id}/deny', [\App\Http\Controllers\Api\Library\RenewalController::class, 'deny']);

        // Fines — balance lives on users.total_fines; settlement records Paid or Waived.
        Route::get('/fines', [\App\Http\Controllers\Api\Library\FinesController::class, 'index']);
        Route::post('/fines/settle', [\App\Http\Controllers\Api\Library\FinesController::class, 'settle']);
    });
});
