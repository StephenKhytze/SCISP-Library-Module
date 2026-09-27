<?php

namespace App\Http\Controllers\Api\Library;

use App\Http\Controllers\Controller;
use App\Models\LibraryCategory;
use App\Services\CategoryService;
use App\Services\DuplicateCategoryException;
use Illuminate\Http\Request;

/**
 * Book category management.
 *
 * Reading the list is open to every signed-in role — a student needs it to
 * filter the catalog. Creating and renaming are gated to librarians at the
 * route, so the restriction does not depend on the UI hiding a button.
 */
class LibraryCategoryController extends Controller
{
    protected CategoryService $categories;

    public function __construct(CategoryService $categories)
    {
        $this->categories = $categories;
    }

    /**
     * Category names, for filters and dropdowns.
     *
     * Returns a bare array of strings, which is the shape the catalog filter
     * has always received. `?detailed=1` returns the records instead, for
     * screens that need ids.
     */
    public function index(Request $request)
    {
        if ($request->boolean('detailed')) {
            return response()->json($this->categories->listAll());
        }

        return response()->json($this->categories->listNames());
    }

    /** Create a category. Admin / Super Admin only. */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        try {
            $category = $this->categories->create(
                $validated['name'],
                (int) $request->attributes->get('user_id')
            );
        } catch (DuplicateCategoryException $e) {
            return response()->json([
                'error' => 'This category already exists.',
                'existing_category' => $e->category,
            ], 409);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => "Category \"{$category->name}\" added.",
            'category' => $category,
        ], 201);
    }

    /** Rename a category, carrying the change across to its books. */
    public function update(Request $request, int $id)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $category = LibraryCategory::findOrFail($id);

        try {
            $category = $this->categories->rename(
                $category,
                $validated['name'],
                (int) $request->attributes->get('user_id')
            );
        } catch (DuplicateCategoryException $e) {
            return response()->json([
                'error' => 'This category already exists.',
                'existing_category' => $e->category,
            ], 409);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => "Category renamed to \"{$category->name}\".",
            'category' => $category,
        ]);
    }
}
