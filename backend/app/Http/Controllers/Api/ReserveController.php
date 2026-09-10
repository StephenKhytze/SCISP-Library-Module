<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CourseReserve;
use Illuminate\Http\Request;

class ReserveController extends Controller
{
    public function index()
    {
        return response()->json(CourseReserve::with(['user', 'section', 'book', 'copies'])->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'section_id' => 'required|exists:course_sections,section_id',
            'book_id' => 'required|exists:books,book_id',
            'copies_requested' => 'required|integer|min:1',
            'target_group' => 'nullable|string',
            'teacher_to_admin_note' => 'nullable|string',
            'teacher_to_student_note' => 'nullable|string',
        ]);

        // Faculty may only file a request against a section they own. Admin/Super Admin may
        // file for any section. Mirrors the ownership idiom in CourseSectionController.
        $role = strtolower($request->attributes->get('role', ''));
        $isAdmin = in_array($role, ['admin', 'super admin']);

        if (! $isAdmin) {
            $ownsSection = \App\Models\CourseSection::where('section_id', $validated['section_id'])
                ->where('teacher_id', $request->attributes->get('user_id'))
                ->exists();

            if (! $ownsSection) {
                return response()->json([
                    'message' => 'Forbidden. You can only request a course reserve for your own section.',
                ], 403);
            }
        }

        $reserve = CourseReserve::create([
            'section_id' => $validated['section_id'],
            'book_id' => $validated['book_id'],
            'copies_requested' => $validated['copies_requested'],
            'target_group' => $validated['target_group'] ?? null,
            'teacher_to_admin_note' => $validated['teacher_to_admin_note'] ?? null,
            'teacher_to_student_note' => $validated['teacher_to_student_note'] ?? null,
            'user_id' => $request->attributes->get('user_id'),
            'status' => 'pending',
        ]);

        return response()->json($reserve, 201);
    }

    public function updateStatus(Request $request, $id)
    {
        $reserve = CourseReserve::findOrFail($id);
        $validated = $request->validate([
            'status' => 'required|in:approved,denied,released',
            'admin_to_teacher_note' => 'nullable|string',
        ]);

        // Approve/deny is administrative. Release is administrative OR the owning faculty member.
        $role = strtolower($request->attributes->get('role', ''));
        $isAdmin = in_array($role, ['admin', 'super admin']);

        if (! $isAdmin) {
            if ($validated['status'] !== 'released') {
                return response()->json([
                    'message' => 'Forbidden. Only an administrator can approve or deny a course reserve.',
                ], 403);
            }

            if ((int) $reserve->user_id !== (int) $request->attributes->get('user_id')) {
                return response()->json([
                    'message' => 'Forbidden. You are not the owner of this course reserve.',
                ], 403);
            }
        }

        $reserve->status = $validated['status'];
        if (isset($validated['admin_to_teacher_note'])) {
            $reserve->admin_to_teacher_note = $validated['admin_to_teacher_note'];
        }
        
        if ($validated['status'] === 'released') {
            \App\Models\BookCopy::where('reserve_id', $reserve->reserve_id)->update(['reserve_id' => null]);
        }

        $reserve->save();

        return response()->json($reserve);
    }

    public function allocateCopies(Request $request, $id)
    {
        $reserve = CourseReserve::findOrFail($id);

        $validated = $request->validate([
            'copy_ids' => 'sometimes|array',
            'copy_ids.*' => 'integer|exists:book_copies,copy_id',
        ]);

        if ($reserve->status !== 'approved') {
            return response()->json([
                'message' => 'Could not allocate copies.',
                'error' => 'Only an approved reserve can receive copies.',
            ], 422);
        }

        // No explicit selection: take free copies of this reserve's book, up to
        // however many are still outstanding against copies_requested.
        if (empty($validated['copy_ids'])) {
            $alreadyAllocated = \App\Models\BookCopy::where('reserve_id', $reserve->reserve_id)->count();
            $remaining = max(0, (int) $reserve->copies_requested - $alreadyAllocated);

            if ($remaining === 0) {
                return response()->json([
                    'message' => 'Could not allocate copies.',
                    'error' => 'This reserve already has all the copies it requested.',
                ], 422);
            }

            $copies = \App\Models\BookCopy::where('book_id', $reserve->book_id)
                ->whereNull('reserve_id')
                ->where('availability_status', 'available')
                ->limit($remaining)
                ->get();

            if ($copies->isEmpty()) {
                return response()->json([
                    'message' => 'Could not allocate copies.',
                    'error' => 'No available copies of this title to allocate.',
                ], 422);
            }
        } else {
            // Explicit selection must belong to this reserve's book and be free.
            $copies = \App\Models\BookCopy::whereIn('copy_id', $validated['copy_ids'])
                ->where('book_id', $reserve->book_id)
                ->where(function ($q) use ($reserve) {
                    $q->whereNull('reserve_id')->orWhere('reserve_id', $reserve->reserve_id);
                })
                ->get();

            if ($copies->count() !== count($validated['copy_ids'])) {
                return response()->json([
                    'message' => 'Could not allocate copies.',
                    'error' => 'One or more copies do not belong to this title or are already reserved elsewhere.',
                ], 422);
            }
        }

        \App\Models\BookCopy::whereIn('copy_id', $copies->pluck('copy_id'))
            ->update(['reserve_id' => $reserve->reserve_id]);

        return response()->json([
            'message' => 'Allocated '.$copies->count().' cop'.($copies->count() === 1 ? 'y' : 'ies').' to this reserve.',
            'allocated' => $copies->pluck('copy_id'),
        ]);
    }
}
