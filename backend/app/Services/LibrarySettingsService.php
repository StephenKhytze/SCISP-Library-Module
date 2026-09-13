<?php

namespace App\Services;

use App\Models\LibrarySetting;
use Illuminate\Support\Facades\DB;

/**
 * The Library's operational rules.
 *
 * These were hardcoded across CirculationService, FinesCalculator and
 * RenewalService. They now live here, and those classes read from here, so a
 * librarian can change a rule without a deploy.
 *
 * Two things this deliberately does NOT do:
 *
 *  - It does not write defaults into the database. An untouched setting has no
 *    row, so a fresh install and a never-configured install behave identically,
 *    and DEFAULTS below stays the single source of truth.
 *  - It does not rewrite history. Changing the fine rate or the loan period
 *    affects future operations only; due dates and fines already recorded are
 *    left exactly as they were.
 */
class LibrarySettingsService
{
    /**
     * Current behaviour, captured from the source it replaces:
     *   limits and loan lengths  — CirculationService::getBorrowLimitForRole / getDueDateForRole
     *   fine rate                — FinesCalculator::$dailyRate
     *   reserve loan length      — CirculationService (fixed 14 days on a reserve copy)
     *
     * Grace period and maximum fine are new controls; their defaults are the
     * no-op values, so switching settings on changes nothing until someone
     * deliberately edits them.
     */
    public const DEFAULTS = [
        // Student policy
        'student_borrowing_enabled' => ['value' => true,  'type' => 'bool'],
        'student_max_books'         => ['value' => 3,     'type' => 'int'],
        'student_loan_days'         => ['value' => 7,     'type' => 'int'],
        'student_fine_per_day'      => ['value' => 10.00, 'type' => 'float'],
        'student_grace_days'        => ['value' => 0,     'type' => 'int'],
        'student_max_fine'          => ['value' => null,  'type' => 'float'],

        // Faculty policy
        'faculty_borrowing_enabled' => ['value' => true,  'type' => 'bool'],
        'faculty_max_books'         => ['value' => 10,    'type' => 'int'],
        'faculty_loan_days'         => ['value' => 14,    'type' => 'int'],
        'faculty_fine_per_day'      => ['value' => 10.00, 'type' => 'float'],
        'faculty_grace_days'        => ['value' => 0,     'type' => 'int'],
        'faculty_max_fine'          => ['value' => null,  'type' => 'float'],

        // Renewal policy
        'student_renewal_enabled'   => ['value' => true,  'type' => 'bool'],
        'faculty_renewal_enabled'   => ['value' => true,  'type' => 'bool'],
        'max_renewals_per_loan'     => ['value' => null,  'type' => 'int'],

        // Course reserve
        'reserve_loan_days'         => ['value' => 14,    'type' => 'int'],
    ];

    /** Cached for the life of the request; settings change rarely. */
    protected ?array $cache = null;

    /** Every setting, defaults merged with whatever has been overridden. */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $stored = LibrarySetting::all()->keyBy('key');
        $resolved = [];

        foreach (self::DEFAULTS as $key => $meta) {
            $resolved[$key] = isset($stored[$key])
                ? $this->cast($stored[$key]->value, $meta['type'])
                : $meta['value'];
        }

        return $this->cache = $resolved;
    }

    public function get(string $key)
    {
        return $this->all()[$key] ?? null;
    }

    /** Forget the request-local cache after a write. */
    public function flush(): void
    {
        $this->cache = null;
    }

    /**
     * Apply a batch of changes.
     *
     * Returns a plain list of the changes that actually differed, so the caller
     * can still report exactly what moved. Nothing is persisted beyond the
     * current value itself — the settings change log was removed deliberately.
     *
     * @return array<int, array{key: string, previous_value: ?string, new_value: ?string}>
     */
    public function update(array $changes, ?int $changedBy, ?string $note = null): array
    {
        $current = $this->all();
        $written = [];

        DB::transaction(function () use ($changes, $changedBy, $note, $current, &$written) {
            foreach ($changes as $key => $value) {
                if (! isset(self::DEFAULTS[$key])) {
                    continue; // Unknown keys are ignored, never stored.
                }

                $type = self::DEFAULTS[$key]['type'];
                $newValue = $value === null ? null : $this->cast($value, $type);
                $oldValue = $current[$key];

                // A no-op edit is not a change and is not reported.
                if ($this->serialise($oldValue) === $this->serialise($newValue)) {
                    continue;
                }

                LibrarySetting::updateOrCreate(
                    ['key' => $key],
                    [
                        'value' => $newValue === null ? null : $this->serialise($newValue),
                        'type' => $type,
                        'updated_by' => $changedBy,
                    ]
                );

                $written[] = [
                    'key' => $key,
                    'previous_value' => $oldValue === null ? null : $this->serialise($oldValue),
                    'new_value' => $newValue === null ? null : $this->serialise($newValue),
                ];
            }
        });

        $this->flush();

        return $written;
    }

    /* ------------------------------------------------------------------ *
     | Convenience readers, so callers do not repeat the role branching.
     * ------------------------------------------------------------------ */

    public function borrowingEnabledFor(string $dbRole): bool
    {
        return match ($dbRole) {
            'faculty' => (bool) $this->get('faculty_borrowing_enabled'),
            'administrator' => true, // Librarians are not governed by a borrower policy.
            default => (bool) $this->get('student_borrowing_enabled'),
        };
    }

    /** Maximum concurrent active loans, or null when unlimited. */
    public function maxBooksFor(string $dbRole): ?int
    {
        return match ($dbRole) {
            'faculty' => $this->nullableInt($this->get('faculty_max_books')),
            'administrator' => null,
            default => $this->nullableInt($this->get('student_max_books')),
        };
    }

    public function loanDaysFor(string $dbRole): int
    {
        return match ($dbRole) {
            'faculty' => (int) $this->get('faculty_loan_days'),
            // Librarians keep the historical 30-day allowance; it has never been
            // a configurable policy and inventing a setting for it would change
            // behaviour nobody asked to change.
            'administrator' => 30,
            default => (int) $this->get('student_loan_days'),
        };
    }

    public function finePerDayFor(string $dbRole): float
    {
        return match ($dbRole) {
            'faculty' => (float) $this->get('faculty_fine_per_day'),
            default => (float) $this->get('student_fine_per_day'),
        };
    }

    public function graceDaysFor(string $dbRole): int
    {
        return match ($dbRole) {
            'faculty' => (int) $this->get('faculty_grace_days'),
            default => (int) $this->get('student_grace_days'),
        };
    }

    /** Cap on a single loan's fine, or null for uncapped. */
    public function maxFineFor(string $dbRole): ?float
    {
        $value = match ($dbRole) {
            'faculty' => $this->get('faculty_max_fine'),
            default => $this->get('student_max_fine'),
        };

        return ($value === null || $value === '' || (float) $value <= 0) ? null : (float) $value;
    }

    public function renewalEnabledFor(string $dbRole): bool
    {
        return match ($dbRole) {
            'faculty' => (bool) $this->get('faculty_renewal_enabled'),
            'administrator' => true,
            default => (bool) $this->get('student_renewal_enabled'),
        };
    }

    /** Approved renewals allowed per loan, or null for unlimited. */
    public function maxRenewalsPerLoan(): ?int
    {
        return $this->nullableInt($this->get('max_renewals_per_loan'));
    }

    public function reserveLoanDays(): int
    {
        return (int) $this->get('reserve_loan_days');
    }

    /* ------------------------------------------------------------------ */

    protected function nullableInt($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $int = (int) $value;

        // Zero or negative means "no limit" rather than "cannot borrow at all";
        // borrowing is switched off with the enabled flag, not with a 0 limit.
        return $int > 0 ? $int : null;
    }

    protected function cast($value, string $type)
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($type) {
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false,
            default => (string) $value,
        };
    }

    protected function serialise($value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string) $value;
    }
}
