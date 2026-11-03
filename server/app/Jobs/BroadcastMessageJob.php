<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\User;
use App\Scopes\ShopScope;
use App\Services\Telegram\TelegramClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class BroadcastMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 15;

    /**
     * @param array<int, int|string> $chatIds
     */
    public function __construct(
        public array $chatIds,
        public string $text,
        public array $extra = []
    ) {}

    public function handle(TelegramClient $telegram): void
    {
        // Update cron heartbeat timestamp on every execution
        Cache::put('cron:heartbeat', now()->timestamp, now()->addHours(24));

        foreach ($this->chatIds as $chatId) {
            $res = $telegram->sendMessage($chatId, $this->text, $this->extra);

            if ($res === null) {
                // If message failed because user blocked bot, handleResponse in TelegramClient marks user inactive
            }

            // Throttle outbound messages (~20/s = 50ms sleep)
            usleep(50000);
        }
    }
}
