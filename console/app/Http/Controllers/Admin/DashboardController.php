<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Models\Customer;
use App\Models\License;
use App\Models\Payment;
use App\Models\Trial;
use App\Services\DateEpochService;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index()
    {
        $today = DateEpochService::todayDay();

        $licenses = License::with(['customer', 'plan'])->get();

        $activeCount = 0;
        $expiring7Count = 0;
        $expiring30Count = 0;
        $graceCount = 0;
        $expiredCount = 0;
        $expiringSoonList = [];

        foreach ($licenses as $lic) {
            $state = $lic->getRealtimeState($today);
            if ($state === 'ACTIVE') {
                $activeCount++;
                if ($lic->current_exp_day !== 0xFFFF) {
                    $diff = $lic->current_exp_day - $today;
                    if ($diff <= 30) {
                        $expiring30Count++;
                    }
                }
            } elseif ($state === 'EXPIRING') {
                $activeCount++;
                $expiring7Count++;
                $expiring30Count++;
                $expiringSoonList[] = $lic;
            } elseif ($state === 'GRACE') {
                $graceCount++;
                $expiringSoonList[] = $lic;
            } elseif ($state === 'EXPIRED') {
                $expiredCount++;
            }
        }

        // New customers
        $newCustomersThisMonth = Customer::where('created_at', '>=', now()->startOfMonth())->count();
        $newCustomersTotal = Customer::count();

        // Trial to Paid conversion rate
        $totalTrials = Trial::count();
        $convertedTrials = Trial::whereHas('license.customer.licenses', function ($q) {
            $q->whereHas('plan', fn ($p) => $p->where('kind', '!=', 'trial'));
        })->count();
        $trialConversionRate = $totalTrials > 0 ? round(($convertedTrials / $totalTrials) * 100, 1) : 0.0;

        // Revenue metrics (Approved payments only)
        $revenueThisMonth = Payment::where('status', 'approved')
            ->where('created_at', '>=', now()->startOfMonth())
            ->sum('amount');

        $revenueYtd = Payment::where('status', 'approved')
            ->where('created_at', '>=', now()->startOfYear())
            ->sum('amount');

        $revenueByMethod = Payment::where('status', 'approved')
            ->select('method', DB::raw('SUM(amount) as total'))
            ->groupBy('method')
            ->pluck('total', 'method')
            ->toArray();

        // Estimated MRR: active monthly plans + (active yearly plans / 12)
        $mrr = 0.0;
        foreach ($licenses as $lic) {
            if ($lic->getRealtimeState($today) === 'ACTIVE' || $lic->getRealtimeState($today) === 'EXPIRING') {
                if ($lic->plan?->kind === 'monthly') {
                    $mrr += (float)$lic->plan->price_etb;
                } elseif ($lic->plan?->kind === 'yearly') {
                    $mrr += (float)($lic->plan->price_etb / 12);
                }
            }
        }

        // Pending payments
        $pendingPayments = Payment::with(['customer', 'license'])
            ->where('status', 'pending')
            ->orderByDesc('id')
            ->take(5)
            ->get();

        return view('admin.dashboard', [
            'todayDay' => $today,
            'todayDate' => DateEpochService::dayToDate($today),
            'activeCount' => $activeCount,
            'expiring7Count' => $expiring7Count,
            'expiring30Count' => $expiring30Count,
            'graceCount' => $graceCount,
            'expiredCount' => $expiredCount,
            'newCustomersThisMonth' => $newCustomersThisMonth,
            'newCustomersTotal' => $newCustomersTotal,
            'trialConversionRate' => $trialConversionRate,
            'totalTrials' => $totalTrials,
            'convertedTrials' => $convertedTrials,
            'revenueThisMonth' => $revenueThisMonth,
            'revenueYtd' => $revenueYtd,
            'revenueByMethod' => $revenueByMethod,
            'mrr' => round($mrr, 2),
            'expiringSoonList' => $expiringSoonList,
            'pendingPayments' => $pendingPayments,
        ]);
    }
}
