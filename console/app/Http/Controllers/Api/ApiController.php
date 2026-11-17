<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\License;
use App\Models\LicenseToken;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Revocation;
use App\Models\Trial;
use App\Services\CrockfordBase32;
use App\Services\DateEpochService;
use App\Services\TokenSigner;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ApiController extends Controller
{
    public function __construct(
        protected TokenSigner $signer
    ) {}

    /**
     * POST /api/v1/payment-requests
     * Bot or shop server submits customer payment notification.
     */
    public function submitPaymentRequest(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'telegram_id' => 'nullable|string|max:100',
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:50',
            'plan_id' => 'required|exists:plans,id',
            'code' => 'required|string',
            'amount' => 'required|numeric|min:0',
            'method' => 'nullable|string|in:telebirr,cbe,bank,cash,other',
            'reference' => 'nullable|string|max:100',
        ]);

        $code = trim($validated['code']);
        $parsedType = null;
        try {
            $bindBytes = CrockfordBase32::parseCode($code, $parsedType);
        } catch (Exception $e) {
            return response()->json([
                'error' => 'Invalid Code',
                'message' => $e->getMessage(),
            ], 422);
        }

        // Find or create customer
        $customer = null;
        if (!empty($validated['telegram_id'])) {
            $customer = Customer::where('telegram_id', $validated['telegram_id'])->first();
        }
        if (!$customer) {
            $customer = Customer::where('phone', $validated['phone'])->first();
        }
        if (!$customer) {
            $customer = Customer::create([
                'name' => $validated['name'],
                'phone' => $validated['phone'],
                'telegram_id' => $validated['telegram_id'] ?? null,
            ]);
        }

        // Find linked license if already exists
        $license = License::where('bind_code', $code)->first();

        $payment = Payment::create([
            'customer_id' => $customer->id,
            'license_id' => $license?->id,
            'amount' => $validated['amount'],
            'method' => $validated['method'] ?? 'telebirr',
            'reference' => $validated['reference'] ?? null,
            'status' => 'pending',
            'notes' => "Submitted via API for code: {$code}, plan_id: {$validated['plan_id']}",
        ]);

        AuditLog::log('api_payment_request_submitted', 'Payment', (string)$payment->id, [
            'customer_id' => $customer->id,
            'amount' => $payment->amount,
            'code' => $code,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Payment request submitted and queued for super admin approval.',
            'payment_id' => $payment->id,
            'customer_id' => $customer->id,
        ], 201);
    }

    /**
     * GET /api/v1/licenses/by-code/{code}
     * Lookup license by device or shop code.
     */
    public function getLicenseByCode(string $code): JsonResponse
    {
        $parsedType = null;
        try {
            $bindBytes = CrockfordBase32::parseCode($code, $parsedType);
        } catch (Exception $e) {
            return response()->json([
                'error' => 'Invalid Code',
                'message' => $e->getMessage(),
            ], 400);
        }

        $bindHex = bin2hex($bindBytes);
        $license = License::with(['customer', 'plan', 'latestToken'])
            ->where('bind_bytes_hex', $bindHex)
            ->first();

        if (!$license) {
            return response()->json([
                'error' => 'Not Found',
                'message' => "No license found bound to code '{$code}'.",
            ], 404);
        }

        $today = DateEpochService::todayDay();

        return response()->json([
            'uuid' => $license->uuid,
            'customer_name' => $license->customer?->name,
            'type' => $license->type,
            'bind_code' => $license->bind_code,
            'status' => $license->status,
            'operational_state' => $license->getRealtimeState($today),
            'current_exp_day' => $license->current_exp_day,
            'exp_date' => DateEpochService::dayToDate($license->current_exp_day),
            'plan' => [
                'id' => $license->plan?->id,
                'name' => $license->plan?->name,
                'kind' => $license->plan?->kind,
                'seats' => $license->plan?->seats,
                'features' => $license->plan?->featureList(),
            ],
            'token' => $license->latestToken?->token,
        ]);
    }

    /**
     * POST /api/v1/trials
     * Automated trial issuance.
     * Enforces: one trial per telegram_id, one trial per device code.
     */
    public function claimTrial(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'telegram_id' => 'required|string|max:100',
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:50',
            'code' => 'required|string',
        ]);

        $code = trim($validated['code']);
        $parsedType = null;
        try {
            $bindBytes = CrockfordBase32::parseCode($code, $parsedType);
        } catch (Exception $e) {
            return response()->json([
                'error' => 'Invalid Code',
                'message' => $e->getMessage(),
            ], 422);
        }

        $bindHex = bin2hex($bindBytes);
        $telegramId = $validated['telegram_id'];

        // Enforce Trial Uniqueness (Task 8: Trial: one per Telegram id and per device code)
        if (Trial::where('telegram_id', $telegramId)->exists()) {
            return response()->json([
                'error' => 'Trial Limit Exceeded',
                'message' => 'A free trial has already been issued for this Telegram account.',
            ], 422);
        }

        if (Trial::where('bind_bytes_hex', $bindHex)->exists()) {
            return response()->json([
                'error' => 'Trial Limit Exceeded',
                'message' => 'A free trial has already been issued for this device code.',
            ], 422);
        }

        // Find or create trial plan (14 days, kind = trial)
        $trialPlan = Plan::where('kind', 'trial')->first();
        if (!$trialPlan) {
            $trialPlan = Plan::create([
                'name' => '14-Day Free Trial',
                'kind' => 'trial',
                'duration_days' => 14,
                'seats' => 1,
                'features' => Plan::FEATURE_ONLINE_SYNC | Plan::FEATURE_TELEGRAM_BOT | Plan::FEATURE_EXPORT,
                'grace_days' => 3,
                'price_etb' => 0.00,
                'active' => true,
            ]);
        }

        // Customer
        $customer = Customer::firstOrCreate(
            ['telegram_id' => $telegramId],
            [
                'name' => $validated['name'],
                'phone' => $validated['phone'],
            ]
        );

        $today = DateEpochService::todayDay();
        $nbfDay = $today;
        $expDay = $today + $trialPlan->duration_days;

        try {
            $tokenResult = $this->signer->issueToken(
                type: $parsedType,
                bindCode: $code,
                planTypeInt: $trialPlan->planTypeInt(),
                issuedDay: $today,
                nbfDay: $nbfDay,
                expDay: $expDay,
                graceDays: $trialPlan->grace_days,
                seats: $trialPlan->seats ?? 3,
                features: $trialPlan->features,
                maxMajor: 0
            );
        } catch (Exception $e) {
            return response()->json([
                'error' => 'Signing Failed',
                'message' => $e->getMessage(),
            ], 500);
        }

        $license = DB::transaction(function () use (
            $customer, $trialPlan, $parsedType, $code, $bindHex, $expDay,
            $tokenResult, $telegramId, $today, $nbfDay
        ) {
            $license = License::create([
                'uuid' => (string)Str::uuid(),
                'customer_id' => $customer->id,
                'plan_id' => $trialPlan->id,
                'type' => $parsedType,
                'bind_code' => $code,
                'bind_bytes_hex' => $bindHex,
                'status' => 'active',
                'current_exp_day' => $expDay,
            ]);

            Trial::create([
                'telegram_id' => $telegramId,
                'bind_bytes_hex' => $bindHex,
                'bind_code' => $code,
                'license_id' => $license->id,
            ]);

            LicenseToken::create([
                'license_id' => $license->id,
                'token' => $tokenResult['token'],
                'kid' => $tokenResult['kid'],
                'issued_day' => $today,
                'nbf_day' => $nbfDay,
                'exp_day' => $expDay,
                'grace_days' => $trialPlan->grace_days,
                'seats' => $trialPlan->seats ?? 3,
                'features' => $trialPlan->features,
                'max_major' => 0,
                'raw_payload_hex' => $tokenResult['raw_payload_hex'],
            ]);

            return $license;
        });

        AuditLog::log('api_trial_issued', 'Trial', (string)$license->id, [
            'telegram_id' => $telegramId,
            'bind_code' => $code,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Trial license successfully issued.',
            'license_uuid' => $license->uuid,
            'token' => $tokenResult['token'],
            'exp_day' => $expDay,
            'exp_date' => DateEpochService::dayToDate($expDay),
            'duration_days' => $trialPlan->duration_days,
        ], 201);
    }

    /**
     * GET /api/v1/revocations
     * Returns signed binary/JSON revocation list according to SPEC §12.
     */
    public function getRevocations(): JsonResponse
    {
        $revokedHexes = Revocation::pluck('license_id_u64_hex')->toArray();
        $signedRevocation = $this->signer->signRevocationList($revokedHexes);

        return response()->json([
            'status' => 'ok',
            'revocation_list' => $signedRevocation,
        ]);
    }
}
