<?php

namespace App\Expenses;

use App\Models\ExpenseLine;
use Illuminate\Support\Collection;

/**
 * Finds the lines that look like the same expense entered twice: the same ticket photo, or the same date,
 * amount and nature. Lines the person confirmed as « not a duplicate » are ignored.
 */
class DuplicateFinder
{
    public function __construct(private PhotoHash $hash) {}

    /**
     * @param  Collection<int, ExpenseLine>  $lines
     * @return array<int, string> Line id => reason (same_photo | same_expense).
     */
    public function find(Collection $lines): array
    {
        $candidates = $lines->where('duplicate_confirmed', false)->values();
        $found = [];

        foreach ($candidates as $index => $line) {
            foreach ($candidates->slice($index + 1) as $other) {
                $reason = $this->reason($line, $other);

                if ($reason !== null) {
                    $found[$other->id] ??= $reason;
                    $found[$line->id] ??= $reason;
                }
            }
        }

        return $found;
    }

    /**
     * Whether two lines look like the same expense (the « not a duplicate » confirmation is not considered).
     */
    public function matches(ExpenseLine $a, ExpenseLine $b): bool
    {
        return $this->reason($a, $b) !== null;
    }

    private function reason(ExpenseLine $a, ExpenseLine $b): ?string
    {
        if ($a->photo_hash !== null && $b->photo_hash !== null && $this->hash->distance($a->photo_hash, $b->photo_hash) <= PhotoHash::DUPLICATE_DISTANCE) {
            return 'same_photo';
        }

        if ($a->amount_cents > 0 && $a->amount_cents === $b->amount_cents && $a->spent_on !== null && $a->spent_on->equalTo($b->spent_on) && $a->nature === $b->nature) {
            return 'same_expense';
        }

        return null;
    }
}
