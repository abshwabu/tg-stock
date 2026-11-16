<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\License;
use App\Models\LicenseToken;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Reissue;
use App\Models\Revocation;
use App\Models\Setting;
use App\Services\CrockfordBase32;
use App\Services\DateEpochService;
use App\Services\HmacService;
use App\Services\TokenSigner;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LicenseController extends Controller
{
    public function __construct(
        protected TokenSigner $signer
    ) {}

    public function index(Request $request)
    {
        $today = DateEpochService::todayDay();
        $query = License::with(['customer', 'plan', 'latestToken']);

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('uuid', 'like', "%{$search}%")
                  ->orWhere('bind_code', 'like', "%{$search}%")
                  ->orWhereHas('customer', function ($cq) use ($search) {
                      $cq->where('name', 'like', "%{$search}%")
                         ->orWhere('phone', 'like', "%{$search}%")
                         ->orWhere('telegram_id', 'like', "%{$search}%")
                         ->orWhere('shop_name', 'like', "%{$search}%");
                  });
            });
        }

        if ($planId = $request->input('plan_id')) {
            $query->where('plan_id', $planId);
        }

        if ($type = $request->input('type')) {
            $query->where('type', $type);
        }

        if ($statusFilter = $request->input('status')) {
            if ($statusFilter === 'suspended' || $statusFilter === 'revoked' || $statusFilter === 'cancelled') {
                $query->where('status', $statusFilter);
            }
        }

        $licenses = $query->orderByDesc('id')->paginate(20)->withQueryString();
        $plans = Plan::where('active', true)->get();

        return view('admin.licenses.index', [
            'licenses' => $licenses,
            'plans' => $plans,
            'todayDay' => $today,
            'search' => $search,
            'selectedPlan' => $planId,
            'selectedType' => $type,
            'selectedStatus' => $request->input('status'),
        ]);
    }

    public function show(License $license)
    {
        $today = DateEpochService::todayDay();
        $license->load([
            'customer',
            'plan',
            'tokens' => fn ($q) => $q->with('issuer', 'payment')->orderByDesc('id'),
            'payments' => fn ($q) => $q->orderByDesc('id'),
            'reissues' => fn ($q) => $q->with('issuer')->orderByDesc('id'),
            'revocation' => fn ($q) => $q->with('revoker'),
        ]);

        $reissueCountYear = $license->reissues()
            ->where('created_at', '>=', now()->subYear())
            ->count();

        $warningThreshold = (int)config('license.reissue_yearly_warning_limit', 2);
        $reissueWarning = $reissueCountYear >= $warningThreshold;

        return view('admin.licenses.show', [
            'license' => $license,
            'todayDay' => $today,
            'reissueCountYear' => $reissueCountYear,
            'reissueWarning' => $reissueWarning,
        ]);
    }

    public function showWizard(Request $request)
    {
        $customers = Customer::orderBy('name')->get();
        $plans = Plan::where('active', true)->orderBy('id')->get();
        $selectedCustomerId = $request->input('customer_id');
        $todayDate = DateEpochService::dayToDate(DateEpochService::todayDay());

        return view('admin.licenses.wizard', [
            'customers' => $customers,
            'plans' => $plans,
            'selectedCustomerId' => $selectedCustomerId,
            'defaultStartDate' => $todayDate,
        ]);
    }

    public function generateToken(Request $request)
    {
        $request->validate([
            'customer_selection' => 'required|in:existing,new',
            'customer_id' => 'required_if:customer_selection,existing|nullable|exists:customers,id',
            'new_customer_name' => 'required_if:customer_selection,new|nullable|string|max:255',
            'new_customer_phone' => 'required_if:customer_selection,new|nullable|string|max:50',
            'new_customer_telegram' => 'nullable|string|max:100',
            'new_customer_shop' => 'nullable|string|max:255',
            'plan_id' => 'required|exists:plans,id',
            'bind_code' => 'required|string',
            'start_date' => 'required|date',
            'override_duration_days' => 'nullable|integer|min:0',
            'override_seats' => 'nullable|integer|min:1',
            'record_payment' => 'nullable|boolean',
            'payment_amount' => 'nullable|numeric|min:0',
            'payment_method' => 'nullable|string|in:telebirr,cbe,bank,cash,other',
            'payment_reference' => 'nullable|string|max:100',
        ]);

        $bindCode = trim($request->input('bind_code'));
        $parsedType = null;

        try {
            $bindBytes = CrockfordBase32::parseCode($bindCode, $parsedType);
        } catch (Exception $e) {
            return back()->withInput()->withErrors(['bind_code' => 'Invalid Code: ' . $e->getMessage()]);
        }

        $plan = Plan::findOrFail($request->input('plan_id'));
        $licenseType = $parsedType; // 'device' or 'shop'

        // Determine Customer
        if ($request->input('customer_selection') === 'new') {
            $customer = Customer::create([
                'name' => $request->input('new_customer_name'),
                'phone' => $request->input('new_customer_phone'),
                'telegram_id' => $request->input('new_customer_telegram'),
                'shop_name' => $request->input('new_customer_shop'),
            ]);
        } else {
            $customer = Customer::findOrFail($request->input('customer_id'));
        }

        // Calculate Dates
        $startDate = $request->input('start_date');
        $nbfDay = DateEpochService::dateToDay($startDate);
        $issuedDay = DateEpochService::todayDay();

        $duration = $request->filled('override_duration_days')
            ? (int)$request->input('override_duration_days')
            : $plan->duration_days;

        if ($plan->kind === 'lifetime' || $duration === 0) {
            $expDay = 0xFFFF; // lifetime
        } else {
            $expDay = $nbfDay + $duration;
        }

        $seats = $request->filled('override_seats')
            ? (int)$request->input('override_seats')
            : $plan->seats;

        if ($licenseType === 'device') {
            $seats = 1;
        }

        $graceDays = $plan->grace_days;
        $features = $plan->features;
        $maxMajor = 0; // standard 0 = any major

        // Sign token
        try {
            $tokenResult = $this->signer->issueToken(
                type: $licenseType,
                bindCode: $bindCode,
                planTypeInt: $plan->planTypeInt(),
                issuedDay: $issuedDay,
                nbfDay: $nbfDay,
                expDay: $expDay,
                graceDays: $graceDays,
                seats: $seats,
                features: $features,
                maxMajor: $maxMajor
            );
        } catch (Exception $e) {
            return back()->withInput()->withErrors(['bind_code' => $e->getMessage()]);
        }

        // Save License & Token within transaction
        $license = DB::transaction(function () use (
            $customer, $plan, $licenseType, $bindCode, $bindBytes, $expDay,
            $tokenResult, $graceDays, $seats, $features, $maxMajor, $request
        ) {
            $license = License::create([
                'uuid' => (string)Str::uuid(),
                'customer_id' => $customer->id,
                'plan_id' => $plan->id,
                'type' => $licenseType,
                'bind_code' => $bindCode,
                'bind_bytes_hex' => bin2hex($bindBytes),
                'status' => 'active',
                'current_exp_day' => $expDay,
            ]);

            $paymentId = null;
            if ($request->boolean('record_payment')) {
                $payment = Payment::create([
                    'customer_id' => $customer->id,
                    'license_id' => $license->id,
                    'amount' => $request->input('payment_amount', $plan->price_etb),
                    'method' => $request->input('payment_method', 'telebirr'),
                    'reference' => $request->input('payment_reference'),
                    'status' => 'approved',
                    'approved_by' => auth()->id(),
                ]);
                $paymentId = $payment->id;
            }

            LicenseToken::create([
                'license_id' => $license->id,
                'token' => $tokenResult['token'],
                'kid' => $tokenResult['kid'],
                'issued_day' => $tokenResult['issued_day'],
                'nbf_day' => $tokenResult['nbf_day'],
                'exp_day' => $tokenResult['exp_day'],
                'grace_days' => $graceDays,
                'seats' => $seats,
                'features' => $features,
                'max_major' => $maxMajor,
                'issued_by' => auth()->id(),
                'payment_id' => $paymentId,
                'raw_payload_hex' => $tokenResult['raw_payload_hex'],
            ]);

            return $license;
        });

        // Generate ready messages
        $expDateStr = DateEpochService::dayToDate($expDay);
        $messageEn = "Hello {$customer->name}!\n\nHere is your TGStock license token:\n\n{$tokenResult['token']}\n\nPlan: {$plan->name}\nType: " . ucfirst($licenseType) . "\nExpires: {$expDateStr}\n\nTo activate: Open TGStock > Settings > License > Paste Token.";

        // Flagged for Amharic Review
        $messageAm = "ሰላም {$customer->name}!\n\nየTGStock ፈቃድ ኮድዎ ይኸውና፡\n\n{$tokenResult['token']}\n\nእቅድ፡ {$plan->name}\nየሚያበቃበት ቀን፡ {$expDateStr}\n\nለመጠቀም፡ TGStock ይክፈቱ > ቅንብሮች > ፈቃድ > ኮዱን ያስገቡ።";

        AuditLog::log('license_issued', 'License', (string)$license->id, [
            'license_uuid' => $license->uuid,
            'customer_id' => $customer->id,
            'plan_id' => $plan->id,
            'bind_code' => $bindCode,
            'token' => $tokenResult['token'],
        ]);

        return view('admin.licenses.token_result', [
            'license' => $license,
            'customer' => $customer,
            'plan' => $plan,
            'token' => $tokenResult['token'],
            'expDateStr' => $expDateStr,
            'messageEn' => $messageEn,
            'messageAm' => $messageAm,
        ]);
    }

    public function renew(Request $request, License $license)
    {
        $today = DateEpochService::todayDay();
        $plan = $license->plan;

        // SPEC §8 / Task 8: Extends from max(current expiry, today)
        $baseDay = ($license->current_exp_day === 0xFFFF)
            ? $today
            : max($license->current_exp_day, $today);

        $duration = $plan->duration_days > 0 ? $plan->duration_days : 30;
        $newExpDay = ($plan->kind === 'lifetime') ? 0xFFFF : ($baseDay + $duration);

        try {
            $tokenResult = $this->signer->issueToken(
                type: $license->type,
                bindCode: $license->bind_code,
                planTypeInt: $plan->planTypeInt(),
                issuedDay: $today,
                nbfDay: $baseDay,
                expDay: $newExpDay,
                graceDays: $plan->grace_days,
                seats: $plan->seats,
                features: $plan->features,
                maxMajor: 0
            );
        } catch (Exception $e) {
            return back()->withErrors(['msg' => 'Renewal failed: ' . $e->getMessage()]);
        }

        DB::transaction(function () use ($license, $newExpDay, $tokenResult, $plan, $today, $baseDay) {
            $license->current_exp_day = $newExpDay;
            $license->status = 'active';
            $license->save();

            LicenseToken::create([
                'license_id' => $license->id,
                'token' => $tokenResult['token'],
                'kid' => $tokenResult['kid'],
                'issued_day' => $today,
                'nbf_day' => $baseDay,
                'exp_day' => $newExpDay,
                'grace_days' => $plan->grace_days,
                'seats' => $plan->seats,
                'features' => $plan->features,
                'max_major' => 0,
                'issued_by' => auth()->id(),
                'raw_payload_hex' => $tokenResult['raw_payload_hex'],
            ]);
        });

        AuditLog::log('license_renewed', 'License', (string)$license->id, [
            'new_exp_day' => $newExpDay,
            'token' => $tokenResult['token'],
        ]);

        return redirect()->route('admin.licenses.show', $license)
            ->with('success', 'License successfully renewed through ' . DateEpochService::dayToDate($newExpDay));
    }

    public function reissue(Request $request, License $license)
    {
        $request->validate([
            'new_bind_code' => 'required|string',
            'reason' => 'required|string|max:255',
        ]);

        $newCode = trim($request->input('new_bind_code'));
        $parsedType = null;

        try {
            $newBindBytes = CrockfordBase32::parseCode($newCode, $parsedType);
        } catch (Exception $e) {
            return back()->withErrors(['new_bind_code' => 'Invalid Code: ' . $e->getMessage()]);
        }

        if ($parsedType !== $license->type) {
            return back()->withErrors(['new_bind_code' => "Code type mismatch: cannot reissue {$license->type} license for {$parsedType} code."]);
        }

        $oldBindCode = $license->bind_code;
        $oldBindHex = $license->bind_bytes_hex;
        $newBindHex = bin2hex($newBindBytes);

        $today = DateEpochService::todayDay();
        $plan = $license->plan;

        try {
            $tokenResult = $this->signer->issueToken(
                type: $license->type,
                bindCode: $newCode,
                planTypeInt: $plan->planTypeInt(),
                issuedDay: $today,
                nbfDay: $today,
                expDay: $license->current_exp_day,
                graceDays: $plan->grace_days,
                seats: $plan->seats,
                features: $plan->features,
                maxMajor: 0
            );
        } catch (Exception $e) {
            return back()->withErrors(['new_bind_code' => 'Reissue signing failed: ' . $e->getMessage()]);
        }

        DB::transaction(function () use ($license, $newCode, $newBindHex, $oldBindCode, $oldBindHex, $tokenResult, $plan, $today, $request) {
            $license->bind_code = $newCode;
            $license->bind_bytes_hex = $newBindHex;
            $license->save();

            Reissue::create([
                'license_id' => $license->id,
                'old_bind_code' => $oldBindCode,
                'old_bind_bytes_hex' => $oldBindHex,
                'new_bind_code' => $newCode,
                'new_bind_bytes_hex' => $newBindHex,
                'reason' => $request->input('reason'),
                'issued_by' => auth()->id(),
            ]);

            LicenseToken::create([
                'license_id' => $license->id,
                'token' => $tokenResult['token'],
                'kid' => $tokenResult['kid'],
                'issued_day' => $today,
                'nbf_day' => $today,
                'exp_day' => $license->current_exp_day,
                'grace_days' => $plan->grace_days,
                'seats' => $plan->seats,
                'features' => $plan->features,
                'max_major' => 0,
                'issued_by' => auth()->id(),
                'raw_payload_hex' => $tokenResult['raw_payload_hex'],
            ]);
        });

        AuditLog::log('license_reissued', 'License', (string)$license->id, [
            'old_bind_code' => $oldBindCode,
            'new_bind_code' => $newCode,
            'reason' => $request->input('reason'),
            'token' => $tokenResult['token'],
        ]);

        return redirect()->route('admin.licenses.show', $license)
            ->with('success', 'License reissued successfully for new device code.');
    }

    public function suspend(License $license)
    {
        $newStatus = ($license->status === 'suspended') ? 'active' : 'suspended';
        $license->status = $newStatus;
        $license->save();

        AuditLog::log('license_status_changed', 'License', (string)$license->id, [
            'status' => $newStatus,
        ]);

        return back()->with('success', "License status updated to {$newStatus}.");
    }

    public function revoke(Request $request, License $license)
    {
        // super_admin only check
        if (!auth()->user()->isSuperAdmin()) {
            abort(403, 'Only Super Admin can revoke licenses.');
        }

        $request->validate(['reason' => 'required|string|max:255']);

        // Find the most recent token's license_id
        $latestToken = $license->latestToken;
        $licenseIdHex = $latestToken?->raw_payload_hex
            ? substr($latestToken->raw_payload_hex, 8, 16)
            : bin2hex(random_bytes(8));

        DB::transaction(function () use ($license, $licenseIdHex, $request) {
            $license->status = 'revoked';
            $license->save();

            Revocation::create([
                'license_id' => $license->id,
                'license_id_u64_hex' => $licenseIdHex,
                'reason' => $request->input('reason'),
                'revoked_by' => auth()->id(),
            ]);
        });

        AuditLog::log('license_revoked', 'License', (string)$license->id, [
            'reason' => $request->input('reason'),
            'revoked_license_id_hex' => $licenseIdHex,
        ]);

        return redirect()->route('admin.licenses.show', $license)
            ->with('success', 'License permanently revoked.');
    }
}
