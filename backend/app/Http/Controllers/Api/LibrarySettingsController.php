<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\LibrarySettingsService;
use Illuminate\Http\Request;

/**
 * Library operational settings.
 *
 * Admin and Super Admin only — these rules decide what every borrower can do,
 * so they are gated at the route as well as here.
 */
class LibrarySettingsController extends Controller
{
    protected LibrarySettingsService $settings;

    public function __construct(LibrarySettingsService $settings)
    {
        $this->settings = $settings;
    }

    public function index()
    {
        return response()->json([
            'settings' => $this->settings->all(),
            // The frontend renders inputs from this, so it never has to hold
            // its own copy of the schema.
            'schema' => collect(LibrarySettingsService::DEFAULTS)
                ->map(fn ($meta, $key) => [
                    'key' => $key,
                    'type' => $meta['type'],
                    'default' => $meta['value'],
                ])
                ->values(),
        ]);
    }

    public function update(Request $request)
    {
        // Every key is validated explicitly. Nothing is taken from
        // $request->all(), so an unknown field can never reach the store.
        $validated = $request->validate([
            'settings' => 'required|array',

            'settings.student_borrowing_enabled' => 'sometimes|boolean',
            'settings.student_max_books' => 'sometimes|nullable|integer|min:1|max:100',
            'settings.student_loan_days' => 'sometimes|integer|min:1|max:365',
            'settings.student_fine_per_day' => 'sometimes|numeric|min:0|max:10000',
            'settings.student_grace_days' => 'sometimes|integer|min:0|max:60',
            'settings.student_max_fine' => 'sometimes|nullable|numeric|min:0|max:1000000',

            'settings.faculty_borrowing_enabled' => 'sometimes|boolean',
            'settings.faculty_max_books' => 'sometimes|nullable|integer|min:1|max:100',
            'settings.faculty_loan_days' => 'sometimes|integer|min:1|max:365',
            'settings.faculty_fine_per_day' => 'sometimes|numeric|min:0|max:10000',
            'settings.faculty_grace_days' => 'sometimes|integer|min:0|max:60',
            'settings.faculty_max_fine' => 'sometimes|nullable|numeric|min:0|max:1000000',

            'settings.student_renewal_enabled' => 'sometimes|boolean',
            'settings.faculty_renewal_enabled' => 'sometimes|boolean',
            'settings.max_renewals_per_loan' => 'sometimes|nullable|integer|min:1|max:50',

            'settings.reserve_loan_days' => 'sometimes|integer|min:1|max:365',

            'note' => 'nullable|string|max:255',
        ]);

        $changes = $this->settings->update(
            $validated['settings'],
            (int) $request->attributes->get('user_id'),
            $validated['note'] ?? null
        );

        return response()->json([
            'message' => count($changes) === 0
                ? 'No changes to save.'
                : (count($changes) === 1 ? '1 setting updated.' : count($changes).' settings updated.'),
            'settings' => $this->settings->all(),
            'changed' => array_values($changes),
        ]);
    }

}
