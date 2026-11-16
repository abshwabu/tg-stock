<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Models\AuditLog;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $query = Customer::withCount('licenses');

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('telegram_id', 'like', "%{$search}%")
                  ->orWhere('shop_name', 'like', "%{$search}%");
            });
        }

        $customers = $query->orderByDesc('id')->paginate(20)->withQueryString();

        return view('admin.customers.index', [
            'customers' => $customers,
            'search' => $search,
        ]);
    }

    public function create()
    {
        return view('admin.customers.create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:50',
            'telegram_id' => 'nullable|string|max:100',
            'shop_name' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);

        $customer = Customer::create($validated);

        AuditLog::log('customer_created', 'Customer', (string)$customer->id, $validated);

        return redirect()->route('admin.customers.show', $customer)
            ->with('success', "Customer '{$customer->name}' created successfully.");
    }

    public function show(Customer $customer)
    {
        $customer->load([
            'licenses.plan',
            'licenses.tokens',
            'payments' => fn ($q) => $q->orderByDesc('id'),
        ]);

        return view('admin.customers.show', [
            'customer' => $customer,
        ]);
    }

    public function edit(Customer $customer)
    {
        return view('admin.customers.edit', [
            'customer' => $customer,
        ]);
    }

    public function update(Request $request, Customer $customer)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:50',
            'telegram_id' => 'nullable|string|max:100',
            'shop_name' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);

        $customer->update($validated);

        AuditLog::log('customer_updated', 'Customer', (string)$customer->id, $validated);

        return redirect()->route('admin.customers.show', $customer)
            ->with('success', "Customer updated successfully.");
    }
}
