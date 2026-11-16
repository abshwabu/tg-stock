<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Models\AuditLog;
use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class PlanController extends Controller
{
    public function index()
    {
        $plans = Plan::withCount('licenses')->orderBy('id')->get();
        return view('admin.plans.index', ['plans' => $plans]);
    }

    public function create()
    {
        return view('admin.plans.create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'kind' => 'required|string|in:trial,monthly,yearly,lifetime,custom',
            'duration_days' => 'required|integer|min:0',
            'seats' => 'required|integer|min:1|max:255',
            'features' => 'nullable|array',
            'grace_days' => 'required|integer|min:0|max:255',
            'price_etb' => 'required|numeric|min:0',
            'active' => 'nullable|boolean',
        ]);

        $featuresBitmask = 0;
        if (!empty($validated['features'])) {
            foreach ($validated['features'] as $f) {
                $featuresBitmask |= (int)$f;
            }
        }

        $plan = Plan::create([
            'name' => $validated['name'],
            'kind' => $validated['kind'],
            'duration_days' => $validated['duration_days'],
            'seats' => $validated['seats'],
            'features' => $featuresBitmask,
            'grace_days' => $validated['grace_days'],
            'price_etb' => $validated['price_etb'],
            'active' => $request->boolean('active', true),
        ]);

        AuditLog::log('plan_created', 'Plan', (string)$plan->id, [
            'name' => $plan->name,
            'kind' => $plan->kind,
            'price' => $plan->price_etb,
        ]);

        return redirect()->route('admin.plans.index')
            ->with('success', "Plan '{$plan->name}' created successfully.");
    }

    public function edit(Plan $plan)
    {
        return view('admin.plans.edit', ['plan' => $plan]);
    }

    public function update(Request $request, Plan $plan)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'kind' => 'required|string|in:trial,monthly,yearly,lifetime,custom',
            'duration_days' => 'required|integer|min:0',
            'seats' => 'required|integer|min:1|max:255',
            'features' => 'nullable|array',
            'grace_days' => 'required|integer|min:0|max:255',
            'price_etb' => 'required|numeric|min:0',
            'active' => 'nullable|boolean',
        ]);

        $featuresBitmask = 0;
        if (!empty($validated['features'])) {
            foreach ($validated['features'] as $f) {
                $featuresBitmask |= (int)$f;
            }
        }

        $plan->update([
            'name' => $validated['name'],
            'kind' => $validated['kind'],
            'duration_days' => $validated['duration_days'],
            'seats' => $validated['seats'],
            'features' => $featuresBitmask,
            'grace_days' => $validated['grace_days'],
            'price_etb' => $validated['price_etb'],
            'active' => $request->boolean('active'),
        ]);

        AuditLog::log('plan_updated', 'Plan', (string)$plan->id, [
            'name' => $plan->name,
            'kind' => $plan->kind,
        ]);

        return redirect()->route('admin.plans.index')
            ->with('success', "Plan '{$plan->name}' updated successfully.");
    }
}
