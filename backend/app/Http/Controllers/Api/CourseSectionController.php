<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BookCopy;
use App\Models\CourseSection;
use App\Models\CourseSectionStudent;
use App\Models\Hold;
use App\Models\Transaction;
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
        $studentId = (int) $request->attributes->get('user_id');

        $sections = CourseSection::whereHas('students', function($q) use ($studentId) {
            $q->where('student_id', $studentId);
        })->with(['teacher', 'reserves' => function($q) {
            $q->where('status', 'approved')->with('book', 'copies.activeTransaction');
        }])->get();

        $this->attachCallerReserveState($sections, $studentId);

        return response()->json($sections);
    }

    /**
     * D-2: describe each reserve from the calling student's point of view.
     *
     * Everything here is computed from rows already loaded or fetched in two
     * batched queries — no per-reserve round trip — so the card can show the
     * borrower's own state without a second endpoint.
     */
    protected function attachCallerReserveState($sections, int $studentId): void
    {
        $reserveIds = [];

        foreach ($sections as $section) {
            foreach ($section->reserves ?? [] as $reserve) {
                $reserveIds[] = $reserve->reserve_id;
            }
        }

        if (empty($reserveIds)) {
            return;
        }

        // This student's own open requests, keyed by reserve.
        $myHolds = Hold::whereIn('reserve_id', $reserveIds)
            ->where('user_id', $studentId)
            ->whereIn('status', ['pending', 'pending_approval', 'fulfilled'])
            ->get()
            ->keyBy('reserve_id');

        // Which allocated copies this student currently has on loan.
        $myActiveCopyIds = Transaction::where('user_id', $studentId)
            ->where('status', 'active')
            ->pluck('copy_id')
            ->flip();

        foreach ($sections as $section) {
            foreach ($section->reserves ?? [] as $reserve) {
                $copies = $reserve->copies ?? collect();

                $allocated = $copies->count();
                $availableForSection = $copies
                    ->filter(fn ($c) => $c->availability_status === 'available')
                    ->count();

                $onLoanToMe = $copies->contains(fn ($c) => $myActiveCopyIds->has($c->copy_id));

                $hold = $myHolds->get($reserve->reserve_id);

                // On loan outranks everything: the student already has the book.
                if ($onLoanToMe) {
                    $status = 'on_loan';
                    $position = null;
                } elseif ($hold && in_array($hold->status, ['pending_approval', 'fulfilled'], true)) {
                    $status = 'ready_for_pickup';
                    $position = null;
                } elseif ($hold && $hold->status === 'pending') {
                    $status = 'queued';
                    $position = (int) $hold->queue_position;
                } elseif ($availableForSection > 0) {
                    $status = 'available';
                    $position = null;
                } else {
                    $status = 'unavailable';
                    $position = null;
                }

                $reserve->setAttribute('allocated_copies', $allocated);
                $reserve->setAttribute('available_for_section', $availableForSection);
                $reserve->setAttribute('student_request_status', $status);
                $reserve->setAttribute('queue_position', $position);
            }
        }
    }

    /**
     * D-1: the roster of one course section.
     *
     * Deliberately NOT the unrestricted student directory (SEC-05): a student
     * may only see the sections they are actually in, and a teacher only the
     * sections they own. Librarians may see any section.
     */
    public function classmates(Request $request, $sectionId)
    {
        $userId = (int) $request->attributes->get('user_id');
        $role = strtolower((string) $request->attributes->get('role', ''));

        $section = CourseSection::where('section_id', $sectionId)->first();

        if (! $section) {
            return response()->json(['message' => 'Course section not found.'], 404);
        }

        $isLibrarian = str_contains($role, 'admin');
        $isOwningTeacher = (int) $section->teacher_id === $userId;
        $isEnrolled = CourseSectionStudent::where('section_id', $section->section_id)
            ->where('student_id', $userId)
            ->exists();

        if (! $isLibrarian && ! $isOwningTeacher && ! $isEnrolled) {
            return response()->json([
                'message' => 'You are not enrolled in this course section.',
            ], 403);
        }

        // Only what the roster list renders. `users` stores no given/family
        // name, so username is the display value.
        $classmates = CourseSectionStudent::where('section_id', $section->section_id)
            ->with('student:user_id,username')
            ->get()
            ->map(fn ($entry) => $entry->student ? [
                'user_id' => $entry->student->user_id,
                'username' => $entry->student->username,
            ] : null)
            ->filter()
            ->values();

        return response()->json([
            'section_id' => $section->section_id,
            'section_name' => $section->name,
            'classmates' => $classmates,
        ]);
    }
}
