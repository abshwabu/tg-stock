<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\DailySummaryService;
use Illuminate\Console\Command;

class DailySummaryCommand extends Command
{
    protected $signature = 'bot:daily-summary';
    protected $description = 'Send daily evening sales and stock summaries to shop owners';

    public function handle(DailySummaryService $summaryService): int
    {
        $sent = $summaryService->processDueSummaries();
        $this->info("Daily summaries dispatched for {$sent} shop(s).");
        return self::SUCCESS;
    }
}
