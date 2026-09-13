<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Generates ABC-LIB-000001 style labels for physical copies.
 *
 * The number comes from a locked counter row, not from MAX(accession_number)+1:
 * two librarians adding copies in the same second must not be handed the same
 * label. The row lock serialises them, and the unique index on
 * book_copies.accession_number is the backstop if anything ever writes around
 * this service.
 *
 * Callers must already be inside a transaction for the lock to mean anything;
 * next() opens one if they are not.
 */
class AccessionNumberService
{
    public const PREFIX = 'ABC-LIB';
    public const PAD = 6;
    protected const COUNTER = 'accession_number';

    /** The next label, reserving it so nobody else gets the same one. */
    public function next(): string
    {
        return $this->reserve(1)[0];
    }

    /**
     * Reserve a block of labels in one round trip, for "add 10 copies".
     *
     * @return string[]
     */
    public function reserve(int $count): array
    {
        if ($count < 1) {
            return [];
        }

        $run = function () use ($count) {
            $row = DB::table('library_counters')
                ->where('name', self::COUNTER)
                ->lockForUpdate()
                ->first();

            if (! $row) {
                // First use on a database that predates the counter.
                DB::table('library_counters')->insert([
                    'name' => self::COUNTER,
                    'value' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $start = 0;
            } else {
                $start = (int) $row->value;
            }

            DB::table('library_counters')
                ->where('name', self::COUNTER)
                ->update(['value' => $start + $count, 'updated_at' => now()]);

            $numbers = [];

            for ($i = 1; $i <= $count; $i++) {
                $numbers[] = self::format($start + $i);
            }

            return $numbers;
        };

        return DB::transactionLevel() > 0 ? $run() : DB::transaction($run);
    }

    public static function format(int $sequence): string
    {
        return self::PREFIX.'-'.str_pad((string) $sequence, self::PAD, '0', STR_PAD_LEFT);
    }

    /** Loose enough to accept what a librarian types into a search box. */
    public static function looksLikeAccession(string $value): bool
    {
        return (bool) preg_match('/^'.preg_quote(self::PREFIX, '/').'-?\d+$/i', trim($value));
    }
}
