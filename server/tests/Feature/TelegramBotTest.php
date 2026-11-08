<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BotState;
use App\Models\BotUpdate;
use App\Models\Device;
use App\Models\PairingCode;
use App\Models\Shop;
use App\Models\User;
use App\Scopes\ShopScope;
use App\Services\PairingService;
use App\Services\Telegram\BotStateService;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramUi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class TelegramBotTest extends TestCase
{
    use RefreshDatabase;

    protected string $secretToken = 'secret-test-token-xyz123';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.telegram.bot_token', '123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11');
        Config::set('services.telegram.webhook_secret', $this->secretToken);
        Config::set('services.telegram.bot_username', 'InventoryEtBot');

        Http::fake([
            'https://api.telegram.org/bot*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 101]], 200),
            'https://api.telegram.org/bot*/editMessageText' => Http::response(['ok' => true, 'result' => ['message_id' => 101]], 200),
            'https://api.telegram.org/bot*/answerCallbackQuery' => Http::response(['ok' => true, 'result' => true], 200),
            'https://api.telegram.org/bot*/setWebhook' => Http::response(['ok' => true, 'result' => true, 'description' => 'Webhook was set'], 200),
            'https://api.telegram.org/bot*/getWebhookInfo' => Http::response([
                'ok' => true,
                'result' => [
                    'url' => 'https://example.com/api/telegram/webhook',
                    'has_custom_certificate' => false,
                    'pending_update_count' => 0,
                ],
            ], 200),
            'https://api.telegram.org/bot*/deleteWebhook' => Http::response(['ok' => true, 'result' => true], 200),
        ]);
    }

    public function test_secret_token_verification_rejects_invalid_token(): void
    {
        // 1. Missing secret token
        $resMissing = $this->postJson('/api/telegram/webhook', [
            'update_id' => 1,
            'message' => ['chat' => ['id' => 123], 'text' => '/start'],
        ]);
        $resMissing->assertStatus(403);

        // 2. Invalid secret token
        $resBad = $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'wrong-token')
            ->postJson('/api/telegram/webhook', [
                'update_id' => 2,
                'message' => ['chat' => ['id' => 123], 'text' => '/start'],
            ]);
        $resBad->assertStatus(403);

        // 3. Valid secret token
        $resOk = $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $this->secretToken)
            ->postJson('/api/telegram/webhook', [
                'update_id' => 3,
                'message' => ['chat' => ['id' => 123], 'text' => '/start'],
            ]);
        $resOk->assertStatus(200);
        $resOk->assertJson(['ok' => true]);
    }

    public function test_update_id_deduplication_ignores_replayed_updates(): void
    {
        $payload = [
            'update_id' => 99991,
            'message' => [
                'message_id' => 50,
                'chat' => ['id' => 555],
                'from' => ['id' => 555, 'first_name' => 'Abebe'],
                'text' => '/start',
            ],
        ];

        // First delivery
        $res1 = $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $this->secretToken)
            ->postJson('/api/telegram/webhook', $payload);
        $res1->assertStatus(200);
        $res1->assertJson(['ok' => true]);

        $this->assertDatabaseHas('bot_updates', ['update_id' => 99991]);

        // Duplicate delivery with same update_id
        $res2 = $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $this->secretToken)
            ->postJson('/api/telegram/webhook', $payload);
        $res2->assertStatus(200);
        $res2->assertJson(['ok' => true, 'status' => 'duplicate']);

        // Assert record count remains 1
        $this->assertSame(1, BotUpdate::where('update_id', 99991)->count());
    }

    public function test_new_user_start_onboarding_and_shop_creation(): void
    {
        $chatId = 888123;

        // Step 1: User types /start
        $startRes = $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $this->secretToken)
            ->postJson('/api/telegram/webhook', [
                'update_id' => 101,
                'message' => [
                    'message_id' => 1,
                    'chat' => ['id' => $chatId],
                    'from' => ['id' => $chatId, 'first_name' => 'Chala', 'username' => 'chala_b'],
                    'text' => '/start',
                ],
            ]);
        $startRes->assertStatus(200);

        // State created awaiting shop name
        $state = BotState::find($chatId);
        $this->assertNotNull($state);
        $this->assertSame('onboarding', $state->flow);
        $this->assertSame('await_shop_name', $state->step);

        // Step 2: User enters shop name "Bole Teff Store"
        $nameRes = $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $this->secretToken)
            ->postJson('/api/telegram/webhook', [
                'update_id' => 102,
                'message' => [
                    'message_id' => 2,
                    'chat' => ['id' => $chatId],
                    'from' => ['id' => $chatId, 'first_name' => 'Chala', 'username' => 'chala_b'],
                    'text' => 'Bole Teff Store',
                ],
            ]);
        $nameRes->assertStatus(200);

        // Conversation state cleared
        $this->assertNull(BotState::find($chatId));

        // Shop and User created
        $shop = Shop::where('name', 'Bole Teff Store')->first();
        $this->assertNotNull($shop);
        $this->assertSame('ETB', $shop->currency);

        $user = User::withoutGlobalScope(ShopScope::class)->where('telegram_id', $chatId)->first();
        $this->assertNotNull($user);
        $this->assertSame('owner', $user->role);
        $this->assertSame($shop->id, $user->shop_id);

        // Verify Telegram message sent with main menu reply keyboard
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'sendMessage')
                && str_contains($request['text'], 'Bole Teff Store')
                && isset($request['reply_markup']['keyboard']);
        });
    }

    public function test_start_with_pair_code_prompts_and_confirms_pairing(): void
    {
        $pairingService = app(PairingService::class);
        $pairData = $pairingService->startPairing('Piazza Electronics', 'Samsung Tablet');
        $code = $pairData['code'];

        $chatId = 777234;

        // 1. User taps deep link /start pair_<code>
        $startPairRes = $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $this->secretToken)
            ->postJson('/api/telegram/webhook', [
                'update_id' => 201,
                'message' => [
                    'message_id' => 10,
                    'chat' => ['id' => $chatId],
                    'from' => ['id' => $chatId, 'first_name' => 'Sara'],
                    'text' => "/start pair_{$code}",
                ],
            ]);
        $startPairRes->assertStatus(200);

        // Verify confirmation prompt was sent with inline keyboard
        Http::assertSent(function ($request) use ($code) {
            return str_contains($request->url(), 'sendMessage')
                && isset($request['reply_markup']['inline_keyboard'])
                && $request['reply_markup']['inline_keyboard'][0][0]['callback_data'] === "pair:confirm:{$code}";
        });

        // 2. User confirms pairing via inline button callback
        $cbRes = $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $this->secretToken)
            ->postJson('/api/telegram/webhook', [
                'update_id' => 202,
                'callback_query' => [
                    'id' => 'cq_999',
                    'from' => ['id' => $chatId, 'first_name' => 'Sara'],
                    'message' => [
                        'message_id' => 11,
                        'chat' => ['id' => $chatId],
                    ],
                    'data' => "pair:confirm:{$code}",
                ],
            ]);
        $cbRes->assertStatus(200);

        // Verify device was linked and status is confirmed
        $pairingStatus = $pairingService->getPairingStatus($code);
        $this->assertSame('confirmed', $pairingStatus['status']);
        $this->assertNotEmpty($pairingStatus['device_token']);

        // Verify device created in DB
        $device = Device::withoutGlobalScope(ShopScope::class)->where('name', 'Samsung Tablet')->first();
        $this->assertNotNull($device);
    }

    public function test_universal_cancel_clears_conversation_state(): void
    {
        $chatId = 666333;
        $botStateService = app(BotStateService::class);
        $botStateService->start($chatId, 'onboarding', 'await_shop_name');

        $this->assertNotNull(BotState::find($chatId));

        // User sends "❌ Cancel"
        $res = $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $this->secretToken)
            ->postJson('/api/telegram/webhook', [
                'update_id' => 301,
                'message' => [
                    'message_id' => 20,
                    'chat' => ['id' => $chatId],
                    'from' => ['id' => $chatId, 'first_name' => 'Marta'],
                    'text' => '❌ Cancel',
                ],
            ]);
        $res->assertStatus(200);

        // Conversation state cleared
        $this->assertNull(BotState::find($chatId));
    }

    public function test_conversation_state_expires_after_30_minutes(): void
    {
        $chatId = 555444;
        $botStateService = app(BotStateService::class);

        // Create state expired 5 minutes ago
        BotState::create([
            'chat_id' => $chatId,
            'flow' => 'onboarding',
            'step' => 'await_shop_name',
            'expires_at' => now()->subMinutes(5),
        ]);

        $this->assertNull($botStateService->get($chatId));
        $this->assertDatabaseMissing('bot_states', ['chat_id' => $chatId]);
    }

    public function test_language_switch_updates_user_and_shop_language(): void
    {
        $shop = Shop::create([
            'id' => (string) Str::uuid7(),
            'name' => 'Addis Mart',
            'language' => 'am',
        ]);

        $user = User::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'telegram_id' => 444555,
            'name' => 'Owner Almaz',
            'language' => 'am',
            'shop_id' => $shop->id,
            'role' => 'owner',
            'is_active' => true,
        ]);

        // Tap English language button: lang:set:en
        $res = $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $this->secretToken)
            ->postJson('/api/telegram/webhook', [
                'update_id' => 401,
                'callback_query' => [
                    'id' => 'cq_lang',
                    'from' => ['id' => 444555, 'first_name' => 'Almaz'],
                    'message' => [
                        'message_id' => 30,
                        'chat' => ['id' => 444555],
                    ],
                    'data' => 'lang:set:en',
                ],
            ]);
        $res->assertStatus(200);

        $user->refresh();
        $shop->refresh();

        $this->assertSame('en', $user->language);
        $this->assertSame('en', $shop->language);
    }

    public function test_artisan_telegram_webhook_command(): void
    {
        // 1. set webhook
        $this->artisan('telegram:webhook set --url=https://mytestshop.com/api/telegram/webhook')
            ->expectsOutputToContain('Registering webhook')
            ->expectsOutputToContain('Webhook set successfully')
            ->assertExitCode(0);

        // 2. get info
        $this->artisan('telegram:webhook info')
            ->expectsTable(
                ['Setting', 'Value'],
                [
                    ['URL', 'https://example.com/api/telegram/webhook'],
                    ['Custom Certificate', 'No'],
                    ['Pending Updates', 0],
                    ['Last Error Date', 'None'],
                    ['Last Error Message', 'None'],
                    ['Max Connections', 'Default (40)'],
                    ['Allowed Updates', 'All'],
                ]
            )
            ->assertExitCode(0);

        // 3. delete webhook
        $this->artisan('telegram:webhook delete')
            ->expectsOutputToContain('Webhook deleted successfully')
            ->assertExitCode(0);
    }

    public function test_number_parser_tolerant_of_spaces_and_commas(): void
    {
        $this->assertSame(1500.5, TelegramUi::parseNumber('1,500.50'));
        $this->assertSame(2000.0, TelegramUi::parseNumber('2 000'));
        $this->assertSame(35.25, TelegramUi::parseNumber('35.25'));
        $this->assertSame(12.5, TelegramUi::parseNumber('12,5'));
        $this->assertSame(1000000.0, TelegramUi::parseNumber('1,000,000'));
        $this->assertNull(TelegramUi::parseNumber('not-a-number'));
    }

    public function test_profile_command_applies_localized_profile_and_commands(): void
    {
        Http::fake(['https://api.telegram.org/bot*/set*' => Http::response(['ok' => true, 'result' => true], 200)]);

        $this->artisan('telegram:profile')
            ->expectsOutputToContain('Telegram profile applied')
            ->assertExitCode(0);

        Http::assertSentCount(9);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/setMyCommands')
            && $request['language_code'] === 'am'
            && collect($request['commands'])->contains(fn ($command) => $command['command'] === 'help'));
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/setChatMenuButton')
            && $request['menu_button']['type'] === 'commands');
    }

    public function test_help_is_localized_and_unknown_text_offers_menu_and_search(): void
    {
        foreach (['en', 'am'] as $locale) {
            $chatId = $locale === 'en' ? 981001 : 981002;
            $shop = Shop::create(['id' => (string) Str::uuid7(), 'name' => "{$locale} shop", 'currency' => 'ETB', 'language' => $locale]);
            User::withoutGlobalScope(ShopScope::class)->create([
                'id' => (string) Str::uuid7(), 'telegram_id' => $chatId, 'name' => 'Tester',
                'language' => $locale, 'shop_id' => $shop->id, 'role' => 'owner', 'is_active' => true,
            ]);

            $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $this->secretToken)->postJson('/api/telegram/webhook', [
                'update_id' => $chatId, 'message' => ['chat' => ['id' => $chatId], 'from' => ['id' => $chatId], 'text' => '/help'],
            ])->assertOk();
            $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $this->secretToken)->postJson('/api/telegram/webhook', [
                'update_id' => $chatId + 10, 'message' => ['chat' => ['id' => $chatId], 'from' => ['id' => $chatId], 'text' => 'something unknown'],
            ])->assertOk();
        }

        Http::assertSent(fn ($request) => str_contains((string) $request['text'], 'support@yenesuq.com'));
        Http::assertSent(fn ($request) => str_contains((string) $request['text'], '/menu') && str_contains((string) $request['text'], '/help'));
    }

    public function test_delete_account_is_owner_only_and_requires_two_confirmations(): void
    {
        $nonOwner = 982001;
        $shop = Shop::create(['id' => (string) Str::uuid7(), 'name' => 'Delete Shop', 'currency' => 'ETB']);
        User::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(), 'telegram_id' => $nonOwner, 'name' => 'Staff',
            'language' => 'en', 'shop_id' => $shop->id, 'role' => 'salesperson', 'is_active' => true,
        ]);

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $this->secretToken)->postJson('/api/telegram/webhook', [
            'update_id' => 9821, 'message' => ['chat' => ['id' => $nonOwner], 'from' => ['id' => $nonOwner], 'text' => '/deleteaccount'],
        ])->assertOk();
        Http::assertSent(fn ($request) => str_contains((string) $request['text'], 'Only the shop owner'));

        $owner = 982002;
        $ownerUser = User::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(), 'telegram_id' => $owner, 'name' => 'Owner',
            'language' => 'en', 'shop_id' => $shop->id, 'role' => 'owner', 'is_active' => true,
        ]);
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $this->secretToken)->postJson('/api/telegram/webhook', [
            'update_id' => 9822, 'message' => ['chat' => ['id' => $owner], 'from' => ['id' => $owner], 'text' => '/deleteaccount'],
        ])->assertOk();
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $this->secretToken)->postJson('/api/telegram/webhook', [
            'update_id' => 9823, 'callback_query' => ['id' => 'del-1', 'from' => ['id' => $owner], 'data' => 'deleteaccount:second:0', 'message' => ['chat' => ['id' => $owner], 'message_id' => 1]],
        ])->assertOk();
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $this->secretToken)->postJson('/api/telegram/webhook', [
            'update_id' => 9824, 'callback_query' => ['id' => 'del-2', 'from' => ['id' => $owner], 'data' => 'deleteaccount:final:0', 'message' => ['chat' => ['id' => $owner], 'message_id' => 1]],
        ])->assertOk();

        $this->assertDatabaseMissing('shops', ['id' => $shop->id]);
        $this->assertNotNull($ownerUser->id);
    }

    public function test_dube_button_and_command_renders_debtors_menu(): void
    {
        $ownerId = 887766;
        $shop = Shop::create(['id' => (string) Str::uuid7(), 'name' => 'Dube Test Shop', 'currency' => 'ETB']);
        User::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'telegram_id' => $ownerId,
            'name' => 'Owner',
            'language' => 'en',
            'shop_id' => $shop->id,
            'role' => 'owner',
            'is_active' => true,
        ]);

        // 1. Test clicking the inline menu button 'menu:dube:0'
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $this->secretToken)->postJson('/api/telegram/webhook', [
            'update_id' => 77101,
            'callback_query' => [
                'id' => 'cq-dube-1',
                'from' => ['id' => $ownerId],
                'data' => 'menu:dube:0',
                'message' => ['chat' => ['id' => $ownerId], 'message_id' => 10],
            ],
        ])->assertOk();

        // 2. Test sending text message '/dube'
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $this->secretToken)->postJson('/api/telegram/webhook', [
            'update_id' => 77102,
            'message' => [
                'chat' => ['id' => $ownerId],
                'from' => ['id' => $ownerId],
                'text' => '/dube',
            ],
        ])->assertOk();

        // 3. Test sending reply keyboard button '📒 Dube'
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $this->secretToken)->postJson('/api/telegram/webhook', [
            'update_id' => 77103,
            'message' => [
                'chat' => ['id' => $ownerId],
                'from' => ['id' => $ownerId],
                'text' => '📒 Dube',
            ],
        ])->assertOk();

        // 4. Test sending Amharic reply keyboard button '📒 ዱቤ'
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $this->secretToken)->postJson('/api/telegram/webhook', [
            'update_id' => 77104,
            'message' => [
                'chat' => ['id' => $ownerId],
                'from' => ['id' => $ownerId],
                'text' => '📒 ዱቤ',
            ],
        ])->assertOk();

        $customerId = (string) Str::uuid7();
        $customer = \App\Models\Customer::withoutGlobalScope(ShopScope::class)->create([
            'id' => $customerId,
            'shop_id' => $shop->id,
            'name' => 'Abebe Debtor',
            'phone' => '+251911223344',
            'created_at_ms' => 1730000000000,
            'updated_at_ms' => 1730000000000,
        ]);
        $location = $shop->getDefaultLocation();
        \App\Models\Sale::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $shop->id,
            'customer_id' => $customerId,
            'location_id' => $location->id,
            'total_cents' => 50000,
            'due_date' => '2026-11-20',
            'created_at_ms' => 1730000000000,
            'updated_at_ms' => 1730000000000,
        ]);

        // 5. Test clicking on the debtor customer card: dube:cust:{customerId}
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $this->secretToken)->postJson('/api/telegram/webhook', [
            'update_id' => 77105,
            'callback_query' => [
                'id' => 'cq-cust-1',
                'from' => ['id' => $ownerId],
                'data' => "dube:cust:{$customerId}",
                'message' => ['chat' => ['id' => $ownerId], 'message_id' => 10],
            ],
        ])->assertOk();

        // 6. Test clicking Receive Payment: dube:pay_start:{customerId}
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $this->secretToken)->postJson('/api/telegram/webhook', [
            'update_id' => 77106,
            'callback_query' => [
                'id' => 'cq-pay-1',
                'from' => ['id' => $ownerId],
                'data' => "dube:pay_start:{$customerId}",
                'message' => ['chat' => ['id' => $ownerId], 'message_id' => 10],
            ],
        ])->assertOk();

        // 7. Test selecting Full Balance: dube:pay_amt:{customerId}:full
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $this->secretToken)->postJson('/api/telegram/webhook', [
            'update_id' => 77107,
            'callback_query' => [
                'id' => 'cq-pay-2',
                'from' => ['id' => $ownerId],
                'data' => "dube:pay_amt:{$customerId}:full",
                'message' => ['chat' => ['id' => $ownerId], 'message_id' => 10],
            ],
        ])->assertOk();

        // 8. Test selecting Cash: dube:pay_m:{customerId}:cash:50000
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $this->secretToken)->postJson('/api/telegram/webhook', [
            'update_id' => 77108,
            'callback_query' => [
                'id' => 'cq-pay-3',
                'from' => ['id' => $ownerId],
                'data' => "dube:pay_m:{$customerId}:cash:50000",
                'message' => ['chat' => ['id' => $ownerId], 'message_id' => 10],
            ],
        ])->assertOk();

        // 9. Test reminder text: dube:remind:{customerId}
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $this->secretToken)->postJson('/api/telegram/webhook', [
            'update_id' => 77109,
            'callback_query' => [
                'id' => 'cq-remind-1',
                'from' => ['id' => $ownerId],
                'data' => "dube:remind:{$customerId}",
                'message' => ['chat' => ['id' => $ownerId], 'message_id' => 10],
            ],
        ])->assertOk();

        Http::assertSent(fn ($request) => isset($request['text']) && str_contains((string) $request['text'], 'Abebe Debtor'));
        Http::assertSent(fn ($request) => isset($request['text']) && str_contains((string) $request['text'], 'Receive Repayment'));
        Http::assertSent(fn ($request) => isset($request['text']) && str_contains((string) $request['text'], 'Repayment Received'));
        Http::assertSent(fn ($request) => isset($request['text']) && str_contains((string) $request['text'], 'Dube & Debtors Management'));

        $this->assertDatabaseHas('payments', [
            'customer_id' => $customerId,
            'amount_cents' => 50000,
            'method' => 'cash',
        ]);
    }
}
