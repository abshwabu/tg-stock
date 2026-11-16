<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Setting;
use App\Services\DateEpochService;
use App\Services\TokenSigner;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;

class PaymentController extends Controller
{
    public function __construct(
        protected TokenSigner $signer
    ) {}

    public function index(Request $request)
    {
        $query = Payment::with(['customer', 'license.plan', 'approver']);

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($method = $request->input('method')) {
            $query->where('method', $method);
        }

        $payments = $query->orderByDesc('id')->paginate(20)->withQueryString();

        return view('admin.payments.index', [
            'payments' => $payments,
            'selectedStatus' => $status,
            'selectedMethod' => $method,
        ]);
    }

    public function create()
    {
        $customers = Customer::orderBy('name')->get();
        return view('admin.payments.create', ['customers' => $customers]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'amount' => 'required|numeric|min:0',
            'method' => 'required|string|in:telebirr,cbe,bank,cash,other',
            'reference' => 'nullable|string|max:100',
            'notes' => 'nullable|string',
            'proof_file' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:10240',
        ]);

        $proofPath = null;
        if ($request->hasFile('proof_file')) {
            $proofPath = $request->file('proof_file')->store('proofs', 'local');
        }

        $payment = Payment::create([
            'customer_id' => $validated['customer_id'],
            'amount' => $validated['amount'],
            'method' => $validated['method'],
            'reference' => $validated['reference'] ?? null,
            'proof_path' => $proofPath,
            'notes' => $validated['notes'] ?? null,
            'status' => 'pending',
        ]);

        AuditLog::log('payment_recorded', 'Payment', (string)$payment->id, [
            'amount' => $payment->amount,
            'method' => $payment->method,
            'customer_id' => $payment->customer_id,
        ]);

        return redirect()->route('admin.payments.index')
            ->with('success', 'Payment recorded successfully and marked pending approval.');
    }

    public function approve(Payment $payment)
    {
        if (!auth()->user()->isSuperAdmin()) {
            abort(403, 'Unauthorized. Support role cannot approve payments.');
        }

        $payment->status = 'approved';
        $payment->approved_by = auth()->id();
        $payment->save();

        AuditLog::log('payment_approved', 'Payment', (string)$payment->id, [
            'amount' => $payment->amount,
            'customer_id' => $payment->customer_id,
        ]);

        // Auto-issuance check
        $autoIssue = (bool)Setting::get('auto_issue_on_payment_approval', true);
        if ($autoIssue && $payment->license && $payment->license->status === 'active') {
            // Auto-renew or ensure active
        }

        return back()->with('success', 'Payment approved successfully.');
    }

    public function reject(Request $request, Payment $payment)
    {
        if (!auth()->user()->isSuperAdmin()) {
            abort(403, 'Unauthorized. Support role cannot reject payments.');
        }

        $payment->status = 'rejected';
        $payment->approved_by = auth()->id();
        $payment->notes = ($payment->notes ? $payment->notes . "\n" : '') . 'Rejected: ' . $request->input('reason', 'Payment not verified');
        $payment->save();

        AuditLog::log('payment_rejected', 'Payment', (string)$payment->id, [
            'amount' => $payment->amount,
            'customer_id' => $payment->customer_id,
        ]);

        return back()->with('success', 'Payment marked rejected.');
    }

    public function downloadProof(Payment $payment)
    {
        if (!$payment->proof_path || !Storage::disk('local')->exists($payment->proof_path)) {
            abort(404, 'Payment proof file not found.');
        }

        return Storage::disk('local')->download($payment->proof_path);
    }
}
