<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Telegram\TelegramClient;
use Illuminate\Console\Command;

class TelegramWebhookCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'telegram:webhook
                            {action : Action to perform (set, info, delete)}
                            {--url= : Public HTTPS URL for the webhook (for set action)}';

    /**
     * The console command description.
     */
    protected $description = 'Manage Telegram Bot API webhook registration (set, info, delete)';

    public function handle(TelegramClient $client): int
    {
        $action = strtolower((string) $this->argument('action'));

        return match ($action) {
            'set' => $this->handleSet($client),
            'info' => $this->handleInfo($client),
            'delete' => $this->handleDelete($client),
            default => $this->invalidAction($action),
        };
    }

    protected function handleSet(TelegramClient $client): int
    {
        $url = $this->option('url') ?: config('services.telegram.webhook_url');

        if (empty($url)) {
            $url = $this->ask('Please enter public HTTPS webhook URL (e.g. https://yourdomain.com/api/telegram/webhook)');
        }

        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL) || !str_starts_with($url, 'https://')) {
            $this->error('A valid public HTTPS URL is required by Telegram Bot API.');
            return self::FAILURE;
        }

        $secretToken = config('services.telegram.webhook_secret');
        $allowedUpdates = ['message', 'callback_query'];

        $this->info("Registering webhook: {$url}...");

        $success = $client->setWebhook($url, $secretToken, $allowedUpdates);

        if ($success) {
            $this->info("✅ Webhook set successfully to [{$url}].");
            if (!empty($secretToken)) {
                $this->comment('Protected with X-Telegram-Bot-Api-Secret-Token.');
            }
            return self::SUCCESS;
        }

        $this->error('❌ Failed to set Telegram webhook. Check logs and TELEGRAM_BOT_TOKEN.');
        return self::FAILURE;
    }

    protected function handleInfo(TelegramClient $client): int
    {
        $info = $client->getWebhookInfo();

        if ($info === null || empty($info['result'])) {
            $this->error('Failed to retrieve webhook info.');
            return self::FAILURE;
        }

        $result = $info['result'];
        $this->table(['Setting', 'Value'], [
            ['URL', $result['url'] ?? '(none)'],
            ['Custom Certificate', !empty($result['has_custom_certificate']) ? 'Yes' : 'No'],
            ['Pending Updates', $result['pending_update_count'] ?? 0],
            ['Last Error Date', !empty($result['last_error_date']) ? date('Y-m-d H:i:s', $result['last_error_date']) : 'None'],
            ['Last Error Message', $result['last_error_message'] ?? 'None'],
            ['Max Connections', $result['max_connections'] ?? 'Default (40)'],
            ['Allowed Updates', isset($result['allowed_updates']) ? implode(', ', $result['allowed_updates']) : 'All'],
        ]);

        return self::SUCCESS;
    }

    protected function handleDelete(TelegramClient $client): int
    {
        $this->info('Deleting webhook...');
        $success = $client->deleteWebhook();

        if ($success) {
            $this->info('✅ Webhook deleted successfully.');
            return self::SUCCESS;
        }

        $this->error('❌ Failed to delete webhook.');
        return self::FAILURE;
    }

    protected function invalidAction(string $action): int
    {
        $this->error("Invalid action [{$action}]. Use: set, info, or delete.");
        return self::FAILURE;
    }
}
