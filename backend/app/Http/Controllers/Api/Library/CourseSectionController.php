<?php

namespace App\Http\Controllers\Api\Library;

use App\Http\Controllers\Controller;
use App\Models\BookCopy;
use App\Models\CourseReserve;
use App\Models\CourseSection;
use App\Models\CourseSectionStudent;
use App\Models\Hold;
use App\Models\Transaction;
use App\Services\HoldService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CourseSectionController extends Controller
{
    /**
     * Sections this caller manages: a faculty member's own; every section for
     * Admin / Super Admin, who manage sections globally.
     *
     * People inside the payload are serialised as user_id + username only —
     * the roster and the borrower label are all the UI renders, and nothing
     * here needs another user's fine balance, account status or role flags.
     */
    public function index(Request $request)
    {
        $sections = CourseSection::with([
            'students.student:user_id,username',
            'reserves.book',
            'reserves.copies.activeTransaction' => fn ($q) => $q->with('user:user_id,username'),
        ])
            ->when(! $this->isLibrarian($request), fn ($q) => $q->where('teacher_id', $request->attributes->get('user_id')))
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

        $section = $this->managedSection($request, $sectionId);

        // Only student accounts belong on a roster. Enrolling a faculty member,
        // an Admin or a Super Admin would make them eligible for the section's
        // course reserves and put them in front of other students' classmates.
        $targetRole = \App\Models\User::where('user_id', $validated['student_id'])->value('role');

        if ($targetRole !== 'student') {
            return response()->json([
                'message' => 'Could not add this user to the section.',
                'error' => 'Only student accounts can be enrolled in a course section.',
            ], 422);
        }

        $student = CourseSectionStudent::firstOrCreate([
            'section_id' => $section->section_id,
            'student_id' => $validated['student_id'],
        ], [
            'group_name' => $validated['group_name'] ?? null
        ]);

        return response()->json($student, 201);
    }

    // For Teacher: Remove student
    public function removeStudent(Request $request, $sectionId, $studentId, HoldService $holds)
    {
        $section = $this->managedSection($request, $sectionId);

        DB::transaction(function () use ($section, $studentId, $holds) {
            CourseSectionStudent::where('section_id', $section->section_id)
                ->where('student_id', $studentId)
                ->delete();

            // A student who leaves the section is no longer eligible for its
            // course reserves. Close their place in those queues, and release
            // any copy set aside for them: it goes to the next enrolled student
            // waiting on that reserve, or back on the shelf. A damaged, lost or
            // archived copy is never made available by this.
            $reserveIds = CourseReserve::where('section_id', $section->section_id)->pluck('reserve_id');

            $liveHolds = Hold::whereIn('reserve_id', $reserveIds)
                ->where('user_id', $studentId)
                ->whereIn('status', ['pending', 'pending_approval', 'fulfilled'])
                ->lockForUpdate()
                ->get();

            foreach ($liveHolds as $hold) {
                $holds->cancelAndFree($hold);
            }
        });

        return response()->json(['message' => 'Student removed']);
    }

    // For Student: Get their sections
    public function mySections(Request $request)
    {
        $studentId = (int) $request->attributes->get('user_id');

        $sections = CourseSection::whereHas('students', function($q) use ($studentId) {
            $q->where('student_id', $studentId);
        })->with([
            // The card shows the teacher's name and nothing else about them.
            'teacher:user_id,username',
            'reserves' => function ($q) {
                // A student has no reason to learn who else is borrowing a
                // reserve copy: the loan's own fields are kept for the status
                // logic, but the borrower's account is not attached.
                $q->where('status', 'approved')->with([
                    'book',
                    'copies.activeTransaction' => fn ($t) => $t->without('user'),
                ]);
            },
        ])->get();

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
                // Archived copies have left the collection; they neither count
                // as allocated stock nor as available to the section.
                $copies = ($reserve->copies ?? collect())->filter(fn ($c) => $c->archived_at === null);

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

    /** Admin and Super Admin manage every section; faculty only their own. */
    protected function isLibrarian(Request $request): bool
    {
        return str_contains(strtolower((string) $request->attributes->get('role', '')), 'admin');
    }

    /**
     * The section this caller may manage, or 404.
     *
     * A faculty member asking for someone else's section gets the same 404 as
     * for a section that does not exist, so the response never reveals which
     * section ids are in use.
     */
    protected function managedSection(Request $request, $sectionId): CourseSection
    {
        return CourseSection::where('section_id', $sectionId)
            ->when(! $this->isLibrarian($request), fn ($q) => $q->where('teacher_id', $request->attributes->get('user_id')))
            ->firstOrFail();
    }
}
