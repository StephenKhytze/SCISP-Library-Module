<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ExpireHolds extends Command
{
    protected $signature = 'holds:expire';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Expire holds that have been waiting for pickup for more than 48 hours.';

    /**
     * Execute the console command.
     */
    public function handle(\App\Services\HoldService $holdService)
    {
        $expiredHolds = \App\Models\Hold::where('status', 'fulfilled')
            ->where('updated_at', '<', now()->subHours(48)) // Assuming updated_at is when it became fulfilled
            ->get();

        $count = 0;
        foreach ($expiredHolds as $hold) {
            $holdService->cancelHold($hold->hold_id, $hold->user_id, true);
            $count++;
        }

        $this->info("Expired $count holds.");
    }
}
