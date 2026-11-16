@extends('layouts.admin')

@section('title', 'Issue Token Wizard')
@section('header_title', 'Issue New License Token')

@section('content')
<div style="max-width: 800px; margin: 0 auto;">
    <div class="card">
        <h2 style="font-size: 1.3rem; margin-bottom: 6px;">License Issuance Wizard</h2>
        <p style="color: var(--text-muted); font-size: 0.85rem; margin-bottom: 24px;">Issue an Ed25519-signed offline license token for a device or shop.</p>

        <form action="{{ route('admin.licenses.generate') }}" method="POST" id="wizard-form">
            @csrf

            <!-- Step 1: Customer Selection -->
            <div style="border-bottom: 1px solid var(--border-color); padding-bottom: 20px; margin-bottom: 20px;">
                <h3 style="font-size: 1rem; color: #60a5fa; margin-bottom: 14px;">1. Select or Create Customer</h3>
                
                <div style="display: flex; gap: 20px; margin-bottom: 14px;">
                    <label style="cursor: pointer; display: flex; align-items: center; gap: 8px;">
                        <input type="radio" name="customer_selection" value="existing" checked onchange="toggleCustomerType()">
                        Existing Customer
                    </label>
                    <label style="cursor: pointer; display: flex; align-items: center; gap: 8px;">
                        <input type="radio" name="customer_selection" value="new" onchange="toggleCustomerType()">
                        + New Customer
                    </label>
                </div>

                <div id="existing-customer-box" class="form-group">
                    <label for="customer_id">Select Customer</label>
                    <select name="customer_id" id="customer_id">
                        <option value="">-- Choose Customer --</option>
                        @foreach ($customers as $c)
                            <option value="{{ $c->id }}" {{ (string)$selectedCustomerId === (string)$c->id ? 'selected' : '' }}>
                                {{ $c->name }} ({{ $c->phone }}) {{ $c->shop_name ? '- ' . $c->shop_name : '' }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div id="new-customer-box" style="display: none;">
                    <div class="grid-2">
                        <div class="form-group">
                            <label for="new_customer_name">Full Name *</label>
                            <input type="text" name="new_customer_name" id="new_customer_name" placeholder="Abebe Kebede">
                        </div>
                        <div class="form-group">
                            <label for="new_customer_phone">Phone Number *</label>
                            <input type="text" name="new_customer_phone" id="new_customer_phone" placeholder="0911223344">
                        </div>
                    </div>
                    <div class="grid-2">
                        <div class="form-group">
                            <label for="new_customer_telegram">Telegram Username / ID</label>
                            <input type="text" name="new_customer_telegram" id="new_customer_telegram" placeholder="abebe_k">
                        </div>
                        <div class="form-group">
                            <label for="new_customer_shop">Shop Name</label>
                            <input type="text" name="new_customer_shop" id="new_customer_shop" placeholder="Piazza Boutique">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Step 2: Commercial Plan -->
            <div style="border-bottom: 1px solid var(--border-color); padding-bottom: 20px; margin-bottom: 20px;">
                <h3 style="font-size: 1rem; color: #60a5fa; margin-bottom: 14px;">2. Select Commercial Plan</h3>
                <div class="form-group">
                    <label for="plan_id">Plan</label>
                    <select name="plan_id" id="plan_id" required onchange="handlePlanChange()">
                        @foreach ($plans as $p)
                            <option value="{{ $p->id }}" data-kind="{{ $p->kind }}" data-duration="{{ $p->duration_days }}" data-seats="{{ $p->seats }}" data-price="{{ $p->price_etb }}">
                                {{ $p->name }} — {{ ucfirst($p->kind) }} ({{ $p->duration_days > 0 ? $p->duration_days . ' days' : 'Lifetime' }}) - {{ number_format($p->price_etb, 2) }} ETB
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>

            <!-- Step 3: Bind Code Input & Live Checksum Validation -->
            <div style="border-bottom: 1px solid var(--border-color); padding-bottom: 20px; margin-bottom: 20px;">
                <h3 style="font-size: 1rem; color: #60a5fa; margin-bottom: 14px;">3. Device Code or Shop Code</h3>
                <div class="form-group">
                    <label for="bind_code">Customer Code (e.g. DXXX-XXXX-XXXX-XXX or SXXX-XXXX-XXXX-XXX)</label>
                    <input type="text" name="bind_code" id="bind_code" required placeholder="D7ME-5Q0R-XFHD-QJS" oninput="validateCodeLive()" style="font-family: monospace; font-size: 1.1rem; letter-spacing: 1px;">
                    <div id="code-feedback" style="margin-top: 8px; font-size: 0.85rem; font-weight: 600;"></div>
                </div>
            </div>

            <!-- Step 4: Dates & Custom Overrides -->
            <div style="border-bottom: 1px solid var(--border-color); padding-bottom: 20px; margin-bottom: 20px;">
                <h3 style="font-size: 1rem; color: #60a5fa; margin-bottom: 14px;">4. Activation & Overrides (Optional)</h3>
                <div class="grid-2">
                    <div class="form-group">
                        <label for="start_date">Validity Start Date</label>
                        <input type="date" name="start_date" id="start_date" value="{{ $defaultStartDate }}" required>
                    </div>
                    <div class="form-group">
                        <label for="override_duration_days">Duration Days (Leave blank to use plan default)</label>
                        <input type="number" name="override_duration_days" id="override_duration_days" placeholder="Plan default">
                    </div>
                </div>
                <div class="form-group" id="seats-box">
                    <label for="override_seats">Shop Seats (Terminals)</label>
                    <input type="number" name="override_seats" id="override_seats" min="1" value="1">
                    <p style="font-size: 0.78rem; color: var(--text-muted); margin-top: 4px;">Device-bound licenses always enforce 1 seat.</p>
                </div>
            </div>

            <!-- Step 5: Record Payment -->
            <div style="margin-bottom: 24px;">
                <h3 style="font-size: 1rem; color: #60a5fa; margin-bottom: 14px;">5. Payment Recording</h3>
                <label style="cursor: pointer; display: flex; align-items: center; gap: 8px; margin-bottom: 14px;">
                    <input type="checkbox" name="record_payment" value="1" checked onchange="togglePaymentFields()">
                    Record approved payment with this issuance
                </label>

                <div id="payment-fields" class="grid-2">
                    <div class="form-group">
                        <label for="payment_amount">Amount (ETB)</label>
                        <input type="number" step="0.01" name="payment_amount" id="payment_amount" value="500.00">
                    </div>
                    <div class="form-group">
                        <label for="payment_method">Payment Method</label>
                        <select name="payment_method" id="payment_method">
                            <option value="telebirr">Telebirr</option>
                            <option value="cbe">CBE Birr / CBE</option>
                            <option value="bank">Bank Transfer</option>
                            <option value="cash">Cash</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label for="payment_reference">Transaction Reference / Receipt Code</label>
                        <input type="text" name="payment_reference" id="payment_reference" placeholder="e.g. TB12345678">
                    </div>
                </div>
            </div>

            <button type="submit" class="btn btn-primary" style="width: 100%; padding: 12px; font-size: 1rem;">
                🚀 Generate & Sign License Token
            </button>
        </form>
    </div>
</div>

<script>
    function toggleCustomerType() {
        var isNew = document.querySelector('input[name="customer_selection"]:checked').value === 'new';
        document.getElementById('existing-customer-box').style.display = isNew ? 'none' : 'block';
        document.getElementById('new-customer-box').style.display = isNew ? 'block' : 'none';
    }

    function togglePaymentFields() {
        var checked = document.querySelector('input[name="record_payment"]').checked;
        document.getElementById('payment-fields').style.display = checked ? 'grid' : 'none';
    }

    function handlePlanChange() {
        var select = document.getElementById('plan_id');
        var opt = select.options[select.selectedIndex];
        var price = opt.getAttribute('data-price');
        var seats = opt.getAttribute('data-seats');
        if (price) {
            document.getElementById('payment_amount').value = price;
        }
        if (seats) {
            document.getElementById('override_seats').value = seats;
        }
    }

    // Client-side Crockford Base32 live validation
    var ALPHABET = "0123456789ABCDEFGHJKMNPQRSTVWXYZ";
    function validateCodeLive() {
        var input = document.getElementById('bind_code').value.toUpperCase().replace(/[- ]/g, '');
        input = input.replace(/O/g, '0').replace(/[IL]/g, '1');
        var feedback = document.getElementById('code-feedback');

        if (input.length === 0) {
            feedback.innerHTML = '';
            return;
        }

        if (input.length !== 15) {
            feedback.innerHTML = '<span style="color: #94a3b8;">Code length: ' + input.length + '/15 chars</span>';
            return;
        }

        var prefix = input[0];
        if (prefix !== 'D' && prefix !== 'S') {
            feedback.innerHTML = '<span style="color: #ef4444;">❌ Invalid prefix: must start with D (Device) or S (Shop)</span>';
            return;
        }

        // Verify checksum
        var sum = 0;
        for (var i = 0; i < 14; i++) {
            var v = ALPHABET.indexOf(input[i]);
            if (v === -1) {
                feedback.innerHTML = '<span style="color: #ef4444;">❌ Invalid character at position ' + (i+1) + '</span>';
                return;
            }
            sum += v * (2 * i + 1);
        }
        var expectedCheck = ALPHABET[sum % 32];
        if (input[14] !== expectedCheck) {
            feedback.innerHTML = '<span style="color: #ef4444;">❌ Checksum mismatch: typo detected! (Expected ' + expectedCheck + ', got ' + input[14] + ')</span>';
            return;
        }

        var typeName = (prefix === 'D') ? 'Device Code' : 'Shop Code';
        feedback.innerHTML = '<span style="color: #10b981;">✅ Valid ' + typeName + '</span>';
    }

    // Initialize
    handlePlanChange();
</script>
@endsection
