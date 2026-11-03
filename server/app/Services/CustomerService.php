<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Change;
use App\Models\Customer;
use App\Scopes\ShopScope;
use Illuminate\Support\Str;
use InvalidArgumentException;

class CustomerService
{
    public function __construct(
        protected ?BalanceService $balanceService = null
    ) {}

    protected function getBalanceService(): BalanceService
    {
        return $this->balanceService ?? app(BalanceService::class);
    }

    /**
     * Normalize Ethiopian phone numbers to E.164 (+251...) format.
     */
    public static function normalizePhone(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $cleaned = (string) preg_replace('/[\s\-\(\)\.\/]+/', '', trim($phone));
        if ($cleaned === '') {
            return null;
        }

        if (str_starts_with($cleaned, '00251')) {
            $cleaned = '+' . substr($cleaned, 2);
        } elseif (str_starts_with($cleaned, '251')) {
            $cleaned = '+' . $cleaned;
        } elseif (preg_match('/^0([97]\d{8})$/', $cleaned, $matches)) {
            $cleaned = '+251' . $matches[1];
        } elseif (preg_match('/^([97]\d{8})$/', $cleaned, $matches)) {
            $cleaned = '+251' . $matches[1];
        }

        return $cleaned;
    }

    /**
     * Check if a phone number is already registered to another active customer in the same shop.
     */
    public function hasDuplicatePhone(string $shopId, ?string $phone, ?string $excludeCustomerId = null): bool
    {
        $normalized = self::normalizePhone($phone);
        if ($normalized === null) {
            return false;
        }

        $query = Customer::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shopId)
            ->whereNull('deleted_at_ms')
            ->where('phone', $normalized);

        if ($excludeCustomerId !== null) {
            $query->where('id', '!=', $excludeCustomerId);
        }

        return $query->exists();
    }

    /**
     * Create or update a customer with phone normalization and duplicate warning.
     *
     * @return array{customer: Customer, duplicate_phone: bool}
     */
    public function upsertCustomer(
        string $shopId,
        string $id,
        string $name,
        ?string $phone = null,
        ?string $note = null,
        ?int $updatedAtMs = null,
        ?string $deviceId = null
    ): array {
        $normalizedPhone = self::normalizePhone($phone);
        $duplicateWarning = $this->hasDuplicatePhone($shopId, $normalizedPhone, $id);
        $nowMs = (int) (microtime(true) * 1000);
        $effectiveUpdatedAt = $updatedAtMs ?? $nowMs;

        /** @var Customer|null $existing */
        $existing = Customer::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shopId)
            ->where('id', $id)
            ->first();

        if ($existing !== null) {
            $existing->name = $name;
            $existing->phone = $normalizedPhone;
            $existing->note = $note;
            $existing->updated_at_ms = $effectiveUpdatedAt;
            $existing->deleted_at_ms = null; // Un-delete if upserting
            $existing->save();
            $customer = $existing;
        } else {
            $customer = Customer::withoutGlobalScope(ShopScope::class)->create([
                'id' => $id,
                'shop_id' => $shopId,
                'name' => $name,
                'phone' => $normalizedPhone,
                'note' => $note,
                'created_at_ms' => $effectiveUpdatedAt,
                'updated_at_ms' => $effectiveUpdatedAt,
                'deleted_at_ms' => null,
            ]);
        }

        Change::withoutGlobalScope(ShopScope::class)->create([
            'shop_id' => $shopId,
            'device_id' => $deviceId,
            'entity' => 'customer',
            'entity_id' => $customer->id,
        ]);

        return [
            'customer' => $customer,
            'duplicate_phone' => $duplicateWarning,
        ];
    }

    /**
     * Soft delete a customer only when their balance is exactly zero.
     */
    public function softDeleteCustomer(Customer|string $customer, ?string $deviceId = null): void
    {
        $model = $customer instanceof Customer
            ? $customer
            : Customer::withoutGlobalScope(ShopScope::class)->findOrFail($customer);

        $balance = $this->getBalanceService()->customerBalance($model);
        if ($balance !== 0) {
            throw new InvalidArgumentException(
                sprintf('Cannot delete customer [%s]: outstanding balance is %d cents.', $model->name, $balance)
            );
        }

        $nowMs = (int) (microtime(true) * 1000);
        $model->deleted_at_ms = $nowMs;
        $model->updated_at_ms = $nowMs;
        $model->save();

        Change::withoutGlobalScope(ShopScope::class)->create([
            'shop_id' => $model->shop_id,
            'device_id' => $deviceId,
            'entity' => 'customer',
            'entity_id' => $model->id,
        ]);
    }
}
