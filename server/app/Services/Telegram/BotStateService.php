<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Models\BotState;

class BotStateService
{
    public const TTL_MINUTES = 30;

    /**
     * Start a new conversation flow for a chat.
     */
    public function start(int|string $chatId, string $flow, string $step, array $data = []): BotState
    {
        return BotState::updateOrCreate(
            ['chat_id' => (int) $chatId],
            [
                'flow' => $flow,
                'step' => $step,
                'data' => $data,
                'expires_at' => now()->addMinutes(self::TTL_MINUTES),
            ]
        );
    }

    /**
     * Set or overwrite conversation flow and step for a chat.
     */
    public function set(int|string $chatId, string $flow, string $step, array $data = []): BotState
    {
        return $this->start($chatId, $flow, $step, $data);
    }

    /**
     * Advance existing flow to the next step, optionally updating stored data.
     */
    public function next(int|string $chatId, string $step, array $data = []): ?BotState
    {
        $state = $this->get($chatId);
        if ($state === null) {
            return null;
        }

        $mergedData = array_merge($state->data ?? [], $data);
        $state->step = $step;
        $state->data = $mergedData;
        $state->expires_at = now()->addMinutes(self::TTL_MINUTES);
        $state->save();

        return $state;
    }

    /**
     * Get active conversation state. Clears and returns null if expired (>30m).
     */
    public function get(int|string $chatId): ?BotState
    {
        /** @var BotState|null $state */
        $state = BotState::find((int) $chatId);

        if ($state === null) {
            return null;
        }

        if ($state->isExpired()) {
            $state->delete();
            return null;
        }

        return $state;
    }

    /**
     * Clear active conversation state for a chat.
     */
    public function clear(int|string $chatId): void
    {
        BotState::where('chat_id', (int) $chatId)->delete();
    }

    /**
     * Prune all expired conversation states from the database.
     */
    public function pruneExpired(): int
    {
        return BotState::where('expires_at', '<', now())->delete();
    }
}
