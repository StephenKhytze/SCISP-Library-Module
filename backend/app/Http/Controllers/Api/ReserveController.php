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
            'copy_ids' => 'required|array',
            'copy_ids.*' => 'exists:book_copies,copy_id'
        ]);

        \App\Models\BookCopy::whereIn('copy_id', $validated['copy_ids'])
                ->update(['reserve_id' => $reserve->reserve_id]);

        return response()->json(['message' => 'Copies allocated to reserve']);
    }
}
