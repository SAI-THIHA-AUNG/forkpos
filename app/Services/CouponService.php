<?php

namespace App\Services;

use App\Models\Coupon;
use Illuminate\Validation\ValidationException;

class CouponService
{
    /**
     * Validate a coupon against an order and return the discount amount.
     * Mirrors POS Awesome pos_coupon validation rules (simplified).
     *
     * @param  float  $netTotal  order net total (before tax)
     */
    public function validateAndCompute(Coupon $coupon, float $netTotal, ?int $customerId = null): float
    {
        $today = now()->toDateString();

        if (! $coupon->is_active) {
            throw ValidationException::withMessages(['coupon_code' => ['Coupon is inactive.']]);
        }
        if ($coupon->valid_from && $coupon->valid_from->toDateString() > $today) {
            throw ValidationException::withMessages(['coupon_code' => ['Coupon is not valid yet.']]);
        }
        if ($coupon->valid_to && $coupon->valid_to->toDateString() < $today) {
            throw ValidationException::withMessages(['coupon_code' => ['Coupon has expired.']]);
        }
        if ($coupon->max_use > 0 && $coupon->used_count >= $coupon->max_use) {
            throw ValidationException::withMessages(['coupon_code' => ['Coupon usage limit reached.']]);
        }
        if ($coupon->customer_id && $coupon->customer_id !== $customerId) {
            throw ValidationException::withMessages(['coupon_code' => ['Coupon belongs to another customer.']]);
        }
        if ($coupon->min_order_amount > 0 && $netTotal < $coupon->min_order_amount) {
            throw ValidationException::withMessages([
                'coupon_code' => ["Order total must be at least {$coupon->min_order_amount} to use this coupon."],
            ]);
        }

        $discount = match ($coupon->type) {
            Coupon::TYPE_PERCENT => $netTotal * ((float) $coupon->discount_value) / 100,
            Coupon::TYPE_AMOUNT => (float) $coupon->discount_value,
            default => 0.0,
        };

        return round(min($discount, max(0.0, $netTotal)), 6);
    }

    /**
     * Non-throwing variant for the check endpoint.
     *
     * @return array{valid: bool, discount: float, message: string}
     */
    public function check(Coupon $coupon, float $amount, ?int $customerId = null): array
    {
        try {
            $discount = $this->validateAndCompute($coupon, $amount, $customerId);

            return ['valid' => true, 'discount' => $discount, 'message' => 'Coupon is valid.'];
        } catch (ValidationException $e) {
            return [
                'valid' => false,
                'discount' => 0.0,
                'message' => $e->errors()['coupon_code'][0] ?? 'Coupon is not valid.',
            ];
        }
    }
}
