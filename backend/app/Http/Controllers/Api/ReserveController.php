<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BookCopy;
use App\Models\CourseReserve;
use App\Services\ReserveLifecycleService;
use Illuminate\Support\Facades\DB;
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

    public function updateStatus(Request $request, $id, ReserveLifecycleService $lifecycle)
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

        // The state machine, and the atomic clean-up of holds and copies when
        // a reserve ends, live in ReserveLifecycleService.
        try {
            $reserve = $lifecycle->transition(
                $reserve->reserve_id,
                $validated['status'],
                $validated['admin_to_teacher_note'] ?? null
            );
        } catch (\DomainException $e) {
            return response()->json([
                'message' => 'Could not update this course reserve.',
                'error' => $e->getMessage(),
            ], 422);
        }

        return response()->json($reserve);
    }

    /**
     * D-3: an enrolled student asks to borrow from THIS course reserve.
     *
     * Only copies allocated to this reserve are ever considered — a general
     * circulation copy of the same title is never used. If every allocated copy
     * is out, the student joins this reserve's own queue, not the title's.
     *
     * The librarian still performs the physical checkout; this only sets a copy
     * aside or takes a place in line.
     */
    public function requestCopy(Request $request, $id)
    {
        try {
            $hold = app(\App\Services\HoldService::class)->requestReserveCopy(
                (int) $request->attributes->get('user_id'),
                (int) $id
            );

            $ready = $hold->status === 'fulfilled';

            return response()->json([
                'message' => $ready
                    ? 'A copy has been set aside for you. Collect it at the circulation desk.'
                    : 'All allocated copies are in use. You have been added to the queue for this course reserve.',
                'request' => $hold,
                'status' => $ready ? 'ready_for_pickup' : 'queued',
                'queue_position' => $ready ? null : $hold->queue_position,
            ], 201);
        } catch (\Illuminate\Database\QueryException $e) {
            report($e);

            return response()->json([
                'message' => 'Could not request this course reserve.',
                'error' => 'Something went wrong on our side. Please try again.',
            ], 500);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Could not request this course reserve.',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    public function allocateCopies(Request $request, $id, ReserveLifecycleService $lifecycle)
    {
        $reserve = CourseReserve::findOrFail($id);

        $validated = $request->validate([
            'copy_ids' => 'sometimes|array',
            'copy_ids.*' => 'integer|exists:book_copies,copy_id',
        ]);

        try {
            // One transaction, with the reserve and the candidate copies
            // locked, so two librarians cannot allocate the same copy twice or
            // race the general-queue check below.
            $copies = DB::transaction(function () use ($reserve, $validated, $lifecycle) {
                $reserve = CourseReserve::where('reserve_id', $reserve->reserve_id)->lockForUpdate()->firstOrFail();

                if ($reserve->status !== 'approved') {
                    throw new \DomainException('Only an approved reserve can receive copies.');
                }

                if (empty($validated['copy_ids'])) {
                    // No explicit selection: take free copies of this reserve's
                    // book, up to however many are still outstanding.
                    $alreadyAllocated = BookCopy::active()->where('reserve_id', $reserve->reserve_id)->count();
                    $remaining = max(0, (int) $reserve->copies_requested - $alreadyAllocated);

                    if ($remaining === 0) {
                        throw new \DomainException('This reserve already has all the copies it requested.');
                    }

                    $copies = BookCopy::active()
                        ->where('book_id', $reserve->book_id)
                        ->whereNull('reserve_id')
                        ->where('availability_status', 'available')
                        ->where('condition', '!=', 'damaged')
                        ->limit($remaining)
                        ->lockForUpdate()
                        ->get();

                    if ($copies->isEmpty()) {
                        throw new \DomainException('No available copies of this title to allocate.');
                    }
                } else {
                    // Explicit selection. Every copy must be this title's, in
                    // the collection, on the shelf, and not already serving
                    // another live reserve. A copy already allocated to THIS
                    // reserve is a no-op, so re-submitting is harmless.
                    $copies = BookCopy::whereIn('copy_id', $validated['copy_ids'])->lockForUpdate()->get();

                    $liveReserveIds = CourseReserve::whereIn('status', ReserveLifecycleService::LIVE_STATUSES)
                        ->pluck('reserve_id')
                        ->flip();

                    $problems = [];

                    foreach ($copies as $copy) {
                        if ((int) $copy->reserve_id === (int) $reserve->reserve_id) {
                            continue;
                        }

                        if ((int) $copy->book_id !== (int) $reserve->book_id) {
                            $problems[] = "{$copy->label} is a copy of a different title.";
                        } elseif ($copy->isArchived()) {
                            $problems[] = "{$copy->label} is archived.";
                        } elseif ($copy->availability_status !== 'available' || $copy->condition === 'damaged') {
                            $state = $copy->condition === 'damaged' ? 'damaged' : str_replace('_', ' ', $copy->availability_status);
                            $problems[] = "{$copy->label} is {$state}.";
                        } elseif ($copy->reserve_id !== null && $liveReserveIds->has($copy->reserve_id)) {
                            $problems[] = "{$copy->label} is already allocated to another course reserve.";
                        }
                    }

                    if (! empty($problems)) {
                        throw new \DomainException('Only available copies of this title can be allocated. '.implode(' ', $problems));
                    }
                }

                // M-2: never take the last copy the general waitlist could get.
                if ($lifecycle->wouldStarveGeneralQueue((int) $reserve->book_id, $copies)) {
                    throw new \DomainException(
                        'Cannot allocate the last usable general copy while borrowers are waiting in the general queue.'
                    );
                }

                BookCopy::whereIn('copy_id', $copies->pluck('copy_id'))
                    ->update(['reserve_id' => $reserve->reserve_id]);

                return $copies;
            });
        } catch (\DomainException $e) {
            return response()->json([
                'message' => 'Could not allocate copies.',
                'error' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => 'Allocated '.$copies->count().' cop'.($copies->count() === 1 ? 'y' : 'ies').' to this reserve.',
            'allocated' => $copies->pluck('copy_id'),
        ]);
    }
}
