<?php

namespace App\Domain\Entry\Actions;

use App\Domain\Entry\Models\Entry;

class ArchiveEntryAction
{
    public function handle(Entry $entry): Entry
    {
        if ($entry->archived_at === null) {
            $entry->update([
                'archived_at' => now(),
            ]);
        }

        return $entry;
    }
}
