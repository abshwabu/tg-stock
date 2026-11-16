<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\LicenseReminder;
use App\Models\Shop;
use App\Models\ShopLicense;
use App\Models\User;
use App\Scopes\ShopScope;
use App\Services\DateEpochService;
use App\Services\LicenseVerifier;
use App\Services\Telegram\TelegramClient;
use App\Support\EthiopicCalendar;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CheckLicenseExpirationsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'license:check-expirations {--day= : Override current Addis Ababa day index for testing}';

    /**
     * The console command description.
     */
    protected $description = 'Check shop license expiry milestones and dispatch idempotent notifications to owners';

    public function __construct(
        protected TelegramClient $telegram
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $todayOption = $this->option('day');
        $today = $todayOption !== null ? (int) $todayOption : DateEpochService::today();

        $this->info("Checking license expirations for day: {$today} (Date: " . DateEpochService::dayToDate($today)->format('Y-m-d') . ")");

        $licenses = ShopLicense::whereNotNull('token')->get();
        $dispatchedCount = 0;

        foreach ($licenses as $shopLicense) {
            $shop = Shop::find($shopLicense->shop_id);
            if ($shop === null) {
                continue;
            }

            $verification = LicenseVerifier::verify(
                tokenString: $shopLicense->token,
                expectedType: 2,
                expectedBind: $shop->getBindBytes(),
                today: $today
            );

            $payload = $verification['payload'];
            if (!$payload) {
                continue;
            }

            $expDay = $payload['exp_day'];
            if ($expDay === 0xFFFF) {
                // Lifetime token never expires
                continue;
            }

            $graceDays = $payload['grace_days'] ?? 7;
            $licenseId = $payload['license_id_hex'] ?? $shopLicense->license_id ?? (string) $shop->id;

            // Determine matching milestone for today
            $milestones = [];
            $daysLeft = $expDay - $today;

            if ($daysLeft === 7) {
                $milestones[] = '7_days';
            } elseif ($daysLeft === 3) {
                $milestones[] = '3_days';
            } elseif ($daysLeft === 1) {
                $milestones[] = '1_day';
            } elseif ($daysLeft === 0) {
                $milestones[] = 'expiry_day';
            }

            if ($today === ($expDay + $graceDays)) {
                $milestones[] = 'grace_end';
            }

            foreach ($milestones as $milestone) {
                // Idempotency: check if already sent for this license and day
                $alreadySent = LicenseReminder::where('license_id', $licenseId)
                    ->where('milestone', $milestone)
                    ->where('day', $today)
                    ->exists();

                if ($alreadySent) {
                    continue;
                }

                $dispatched = $this->sendMilestoneReminder($shop, $licenseId, $milestone, $daysLeft, $expDay, $today);
                if ($dispatched) {
                    LicenseReminder::create([
                        'id' => (string) Str::uuid7(),
                        'shop_id' => $shop->id,
                        'license_id' => $licenseId,
                        'milestone' => $milestone,
                        'day' => $today,
                        'sent_at' => now(),
                    ]);
                    $dispatchedCount++;
                }
            }
        }

        $this->info("Completed license expiration check. Sent {$dispatchedCount} reminder(s).");
        return self::SUCCESS;
    }

    /**
     * Format and deliver localized reminder to shop owner.
     */
    protected function sendMilestoneReminder(
        Shop $shop,
        string $licenseId,
        string $milestone,
        int $daysLeft,
        int $expDay,
        int $today
    ): bool {
        $owner = User::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shop->id)
            ->where('role', 'owner')
            ->first();

        if ($owner === null || empty($owner->telegram_id)) {
            return false;
        }

        $locale = $owner->language ?: 'am';
        $expDate = DateEpochService::dayToDate($expDay);
        $gregorian = $expDate->format('Y-m-d');
        $ethiopian = EthiopicCalendar::format($expDate, $locale);
        $expFormatted = "{$gregorian} ({$ethiopian})";

        if ($milestone === '7_days' || $milestone === '3_days' || $milestone === '1_day') {
            if ($locale === 'en') {
                $text = "⚠️ <b>License Expiry Reminder</b>\n\n"
                      . "Your license for <b>{$shop->name}</b> expires in <b>{$daysLeft} day(s)</b> on {$expFormatted}.\n\n"
                      . "To avoid interruption to sales, please renew your license soon.\n"
                      . "Go to ⚙️ <b>Settings</b> → 🔑 <b>License</b> → <b>Buy / Renew</b>.";
            } else {
                $text = "⚠️ <b>የፈቃድ ማሳሰቢያ</b>\n\n"
                      . "የሱቅዎ (<b>{$shop->name}</b>) ፈቃድ በ <b>{$daysLeft} ቀን ውስጥ</b> በ {$expFormatted} ያበቃል።\n\n"
                      . "የሽያጭ ሥራ እንዳይስተጓጎል እባክዎ ፈቃድዎን አስቀድመው ያድሱ።\n"
                      . "ወደ ⚙️ <b>ቅንብሮች</b> → 🔑 <b>ፈቃድ</b> → <b>ግዛ / አድስ</b> ይሂዱ።";
            }
        } elseif ($milestone === 'expiry_day') {
            if ($locale === 'en') {
                $text = "⏰ <b>License Expires Today!</b>\n\n"
                      . "Your license for <b>{$shop->name}</b> expires today ({$expFormatted}).\n"
                      . "A 7-day grace period has begun. Please renew your license now to maintain write access.";
            } else {
                $text = "⏰ <b>ፈቃድዎ ዛሬ ያበቃል!</b>\n\n"
                      . "የሱቅዎ (<b>{$shop->name}</b>) ፈቃድ ዛሬ ያበቃል። የችሮታ ጊዜ ተጀምሯል።\n"
                      . "ሥራዎ እንዳይቆም እባክዎ አሁኑኑ ፈቃድዎን ያድሱ።";
            }
        } elseif ($milestone === 'grace_end') {
            if ($locale === 'en') {
                $text = "❌ <b>Grace Period Ended — Read-Only Mode Active</b>\n\n"
                      . "The grace period for <b>{$shop->name}</b> has ended.\n"
                      . "Your shop is now in <b>Read-Only mode</b>. Recording new sales, stock movements, and products is blocked until renewed.\n\n"
                      . "All your historical data remains safe. Please renew your license to resume recording transactions.";
            } else {
                $text = "❌ <b>የችሮታ ጊዜው አብቅቷል (ማንበብ ብቻ)</b>\n\n"
                      . "የሱቅዎ (<b>{$shop->name}</b>) የችሮታ ጊዜ አብቅቷል።\n"
                      . "ሱቁ ወደ ማንበብ ብቻ (Read-Only) ሁነታ ተቀይሯል። ሽያጭ እና አዳዲስ ዕቃዎችን መመዝገብ ለጊዜው ተዘግቷል።\n\n"
                      . "ሙሉ አገልግሎት ለማስቀጠል እባክዎ ፈቃዱን ያድሱ።";
            }
        } else {
            return false;
        }

        try {
            $this->telegram->sendMessage((int) $owner->telegram_id, $text);
            return true;
        } catch (\Throwable $e) {
            Log::warning("Failed to deliver expiry reminder for shop {$shop->id}: " . $e->getMessage());
            return false;
        }
    }
}
