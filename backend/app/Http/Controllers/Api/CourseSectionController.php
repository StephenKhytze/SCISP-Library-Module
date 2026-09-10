<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CourseSection;
use App\Models\CourseSectionStudent;
use Illuminate\Http\Request;

class CourseSectionController extends Controller
{
    // For Teacher: Get their sections
    public function index(Request $request)
    {
        $teacherId = $request->attributes->get('user_id');
        $sections = CourseSection::with(['students.student', 'reserves.book', 'reserves.copies.activeTransaction'])
            ->where('teacher_id', $teacherId)
            ->get();
            
        return response()->json($sections);
    }

    // Get all students for dropdown
    public function getStudents()
    {
        // Student identities are provisioned by MockPersonaSeeder, never during a request.
        $students = \App\Models\User::whereIn('role', ['Student', 'student'])->get(['user_id', 'username']);
        return response()->json($students);
    }

    // For Teacher: Create a section
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $section = CourseSection::create([
            'teacher_id' => $request->attributes->get('user_id'),
            'name' => $validated['name'],
        ]);

        return response()->json($section, 201);
    }

    // For Teacher: Add student to section
    public function addStudent(Request $request, $sectionId)
    {
        $validated = $request->validate([
            'student_id' => 'required|exists:users,user_id',
            'group_name' => 'nullable|string'
        ]);

        $section = CourseSection::where('section_id', $sectionId)
            ->where('teacher_id', $request->attributes->get('user_id'))
            ->firstOrFail();

        $student = CourseSectionStudent::firstOrCreate([
            'section_id' => $section->section_id,
            'student_id' => $validated['student_id'],
        ], [
            'group_name' => $validated['group_name'] ?? null
        ]);

        return response()->json($student, 201);
    }

    // For Teacher: Remove student
    public function removeStudent(Request $request, $sectionId, $studentId)
    {
        $section = CourseSection::where('section_id', $sectionId)
            ->where('teacher_id', $request->attributes->get('user_id'))
            ->firstOrFail();

        CourseSectionStudent::where('section_id', $section->section_id)
            ->where('student_id', $studentId)
            ->delete();

        return response()->json(['message' => 'Student removed']);
    }

    // For Student: Get their sections
    public function mySections(Request $request)
    {
        $studentId = $request->attributes->get('user_id');
        
        $sections = CourseSection::whereHas('students', function($q) use ($studentId) {
            $q->where('student_id', $studentId);
        })->with(['teacher', 'reserves' => function($q) {
            $q->where('status', 'approved')->with('book', 'copies.activeTransaction');
        }])->get();

        return response()->json($sections);
    }
}
