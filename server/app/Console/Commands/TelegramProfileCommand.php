<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Telegram\TelegramClient;
use Illuminate\Console\Command;

class TelegramProfileCommand extends Command
{
    protected $signature = 'telegram:profile';
    protected $description = 'Apply the Inventory Telegram bot profile, localized commands, and menu button';

    public function handle(TelegramClient $client): int
    {
        $name = (string) config('services.telegram.app_name', env('APP_NAME', 'Inventory'));
        $nameAm = (string) config('services.telegram.app_name_am', 'ዕቃ ዝርዝር');
        $domain = rtrim((string) config('app.url', 'https://stock.yenesuq.com'), '/');

        $requests = [
            ['setMyName', ['name' => $name, 'language_code' => 'en']],
            ['setMyName', ['name' => $nameAm, 'language_code' => 'am']],
            ['setMyDescription', ['description' => 'Offline-first shop inventory, sales, stock, reports, and Telegram sync. Start here to manage your shop.', 'language_code' => 'en']],
            ['setMyDescription', ['description' => 'የሱቅ ዕቃ፣ ሽያጭ፣ ስቶክ እና ሪፖርት አስተዳደር። ከኢንተርኔት ውጭም ይሰራል።', 'language_code' => 'am']],
            ['setMyShortDescription', ['short_description' => 'Shop inventory and sales, offline or online.', 'language_code' => 'en']],
            ['setMyShortDescription', ['short_description' => 'የሱቅ ዕቃና ሽያጭ አስተዳደር።', 'language_code' => 'am']],
            ['setMyCommands', ['commands' => $this->commands('en'), 'language_code' => 'en']],
            ['setMyCommands', ['commands' => $this->commands('am'), 'language_code' => 'am']],
            ['setChatMenuButton', ['menu_button' => ['type' => 'commands']]],
        ];

        foreach ($requests as [$method, $payload]) {
            if (!$client->setBotProfile($method, $payload)) {
                $this->error("Telegram {$method} failed.");
                return self::FAILURE;
            }
        }

        $this->info("Telegram profile applied for {$name} ({$domain}).");
        $this->comment('Upload the 640×640 profile image manually through BotFather; see /design/README.md.');
        return self::SUCCESS;
    }

    /** @return array<int, array{command:string,description:string}> */
    private function commands(string $locale): array
    {
        return $locale === 'am'
            ? [
                ['command' => 'start', 'description' => 'መጀመሪያ ይጀምሩ ወይም ሱቅዎን ይክፈቱ'],
                ['command' => 'menu', 'description' => 'ዋና ማውጫውን ይክፈቱ'],
                ['command' => 'help', 'description' => 'ፈጣን እርዳታ እና መመሪያ'],
                ['command' => 'language', 'description' => 'ቋንቋ ይቀይሩ'],
                ['command' => 'report', 'description' => 'የሽያጭ እና ስቶክ ሪፖርት'],
                ['command' => 'dube', 'description' => 'የዱቤ ሂሳብ ይከታተሉ'],
                ['command' => 'cancel', 'description' => 'የአሁኑን ተግባር ይሰርዙ'],
            ]
            : [
                ['command' => 'start', 'description' => 'Start or create your shop'],
                ['command' => 'menu', 'description' => 'Open the main menu'],
                ['command' => 'help', 'description' => 'Quick help and getting started'],
                ['command' => 'language', 'description' => 'Change bot language'],
                ['command' => 'report', 'description' => 'View sales and stock reports'],
                ['command' => 'dube', 'description' => 'Track customer credit balances'],
                ['command' => 'cancel', 'description' => 'Cancel the current action'],
            ];
    }
}
