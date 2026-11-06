<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Models\User;
use App\Scopes\ShopScope;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramClient
{
    protected string $baseUrl;

    public function __construct(
        protected ?string $token = null
    ) {
        $botToken = $this->token ?: config('services.telegram.bot_token', '');
        $this->baseUrl = "https://api.telegram.org/bot{$botToken}";
    }

    /**
     * Build standard configured HTTP client (8s connect / 15s total, 1 retry, forced IPv4).
     */
    protected function httpClient(): PendingRequest
    {
        return Http::connectTimeout(8)
            ->timeout(15)
            ->withOptions([
                'force_ip_resolve' => 'v4',
            ])
            ->retry(1, 100, throw: false);
    }

    /**
     * Send a text message to a Telegram chat.
     *
     * @param int|string $chatId
     * @param string $text
     * @param array $extra Optional parameters (parse_mode, reply_markup, etc.)
     * @return array|null Telegram message result or null on failure
     */
    public function sendMessage(int|string $chatId, string $text, array $extra = []): ?array
    {
        $payload = array_merge([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ], $extra);

        $response = $this->httpClient()->post("{$this->baseUrl}/sendMessage", $payload);

        return $this->handleResponse($response, 'sendMessage', (int) $chatId);
    }

    /**
     * Edit existing message text.
     */
    public function editMessageText(int|string $chatId, int $messageId, string $text, array $extra = []): ?array
    {
        $payload = array_merge([
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ], $extra);

        $response = $this->httpClient()->post("{$this->baseUrl}/editMessageText", $payload);

        return $this->handleResponse($response, 'editMessageText', (int) $chatId);
    }

    /**
     * Answer an incoming callback query to remove spinner on the client.
     */
    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null, bool $showAlert = false): bool
    {
        $payload = [
            'callback_query_id' => $callbackQueryId,
            'show_alert' => $showAlert,
        ];

        if ($text !== null) {
            $payload['text'] = $text;
        }

        $response = $this->httpClient()->post("{$this->baseUrl}/answerCallbackQuery", $payload);

        return (bool) ($this->handleResponse($response, 'answerCallbackQuery') !== null);
    }

    /**
     * Send a document file (CSV, PDF, etc.) to a chat.
     */
    public function sendDocument(int|string $chatId, mixed $contents, string $filename, ?string $caption = null, array $extra = []): ?array
    {
        $req = $this->httpClient()->attach('document', $contents, $filename);

        $data = array_merge([
            'chat_id' => (string) $chatId,
        ], $extra);

        if ($caption !== null) {
            $data['caption'] = $caption;
            $data['parse_mode'] = 'HTML';
        }

        $response = $req->post("{$this->baseUrl}/sendDocument", $data);

        return $this->handleResponse($response, 'sendDocument', (int) $chatId);
    }

    /**
     * Register or update the Telegram webhook.
     */
    public function setWebhook(string $url, ?string $secretToken = null, array $allowedUpdates = ['message', 'callback_query']): bool
    {
        $payload = [
            'url' => $url,
            'allowed_updates' => $allowedUpdates,
        ];

        if (!empty($secretToken)) {
            $payload['secret_token'] = $secretToken;
        }

        $response = $this->httpClient()->post("{$this->baseUrl}/setWebhook", $payload);

        $res = $this->handleResponse($response, 'setWebhook');
        return $res !== null && !empty($res['ok']);
    }

    /**
     * Get current Telegram webhook status.
     */
    public function getWebhookInfo(): ?array
    {
        $response = $this->httpClient()->get("{$this->baseUrl}/getWebhookInfo");

        return $this->handleResponse($response, 'getWebhookInfo');
    }

    /**
     * Delete existing Telegram webhook.
     */
    public function deleteWebhook(): bool
    {
        $response = $this->httpClient()->post("{$this->baseUrl}/deleteWebhook");

        $res = $this->handleResponse($response, 'deleteWebhook');
        return $res !== null && !empty($res['ok']);
    }

    /** Apply a public bot profile setting through the Bot API. */
    public function setBotProfile(string $method, array $payload): bool
    {
        $response = $this->httpClient()->post("{$this->baseUrl}/{$method}", $payload);
        $result = $this->handleResponse($response, $method);
        return $result !== null && !empty($result['ok']);
    }

    /**
     * Handle HTTP response, parse JSON, and handle 429 and 403 cases.
     */
    protected function handleResponse(Response $response, string $method, ?int $chatId = null): ?array
    {
        $status = $response->status();

        if ($status === 403) {
            Log::warning("Telegram 403 Forbidden for method [{$method}] - user may have blocked bot.", [
                'chat_id' => $chatId,
            ]);

            // Deactivate blocked user if identified
            if ($chatId !== null) {
                User::withoutGlobalScope(ShopScope::class)
                    ->where('telegram_id', $chatId)
                    ->update(['is_active' => false]);
            }

            return null;
        }

        if ($status === 429) {
            $retryAfter = $response->json('parameters.retry_after') ?? $response->header('Retry-After') ?? 'unknown';
            Log::warning("Telegram 429 Rate Limit hit on [{$method}]. Retry-After: {$retryAfter}s");
            return null;
        }

        if ($response->failed()) {
            Log::error("Telegram API [{$method}] failed with status {$status}: " . $response->body());
            return null;
        }

        $json = $response->json();
        return is_array($json) ? $json : null;
    }
}
