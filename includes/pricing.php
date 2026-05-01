<?php
/**
 * Pricing calculations for Sherwood Adventure bookings.
 */

/**
 * Calculate the attraction price for a given duration.
 * Formula: base_price + (hours beyond base_hours) * additional_hourly_rate
 */
function calc_attraction_price(array $pricing, float $hours): float {
    $extra = max(0, $hours - $pricing['base_hours']);
    return round($pricing['base_price'] + ($extra * $pricing['additional_hourly_rate']), 2);
}

/**
 * Calculate an add-on price for a given booking duration.
 */
function calc_addon_price(array $addon, float $hours): float {
    if ($addon['pricing_type'] === 'flat') {
        return round((float)$addon['price'], 2);
    }
    // per_hour with optional minimum
    $amount = round($addon['price'] * $hours, 2);
    if (!empty($addon['min_charge'])) {
        $amount = max($amount, (float)$addon['min_charge']);
    }
    return $amount;
}

/**
 * Apply a coupon to attraction price and/or addons subtotal.
 * Returns the discount amount (never exceeds what it applies to).
 */
function calc_coupon_discount(array $coupon, float $attraction_price, float $addons_subtotal): float {
    $base = match($coupon['applies_to']) {
        'attraction' => $attraction_price,
        'addons'     => $addons_subtotal,
        'both'       => $attraction_price + $addons_subtotal,
        default      => 0.0,
    };

    $discount = ($coupon['discount_type'] === 'percent')
        ? round($base * ($coupon['discount_amount'] / 100), 2)
        : min((float)$coupon['discount_amount'], $base);

    return round($discount, 2);
}

/**
 * Calculate travel fee.
 * Under threshold: $0. Over threshold: $rate * (miles - threshold).
 */
function calc_travel_fee(float $miles, float $threshold, float $rate_per_mile): float {
    if ($miles <= $threshold) {
        return 0.00;
    }
    return round(($miles - $threshold) * $rate_per_mile, 2);
}

/**
 * Categorize a tax_config row's label into 'state', 'county', or 'city'.
 *
 * NOTE: This is a heuristic based on common keywords in the label. If admins
 * rename a tax to something that doesn't contain any of these keywords, it
 * falls through to 'state' as the safe default. The TOTAL tax stays correct
 * either way — only the breakdown reporting is affected.
 *
 * Convention: tax_config labels should contain one of: state, county, city,
 * or the local city/county name (goodyear, maricopa).
 */
function categorize_tax_label(string $label): string {
    $l = strtolower($label);
    if (str_contains($l, 'city') || str_contains($l, 'goodyear')) {
        return 'city';
    }
    if (str_contains($l, 'county') || str_contains($l, 'maricopa')) {
        return 'county';
    }
    // Default — state-level (most universal)
    return 'state';
}

/**
 * Calculate full tax breakdown.
 * Returns ['state' => float, 'county' => float, 'city' => float, 'total' => float]
 */
function calc_tax(float $taxable_amount, array $tax_rates): array {
    $result = ['state' => 0.0, 'county' => 0.0, 'city' => 0.0, 'total' => 0.0];
    foreach ($tax_rates as $rate) {
        $amount   = round($taxable_amount * (float)$rate['rate'], 2);
        $category = categorize_tax_label($rate['label']);
        $result[$category] += $amount;
        $result['total']   += $amount;
    }
    $result['total'] = round($result['total'], 2);
    return $result;
}

/**
 * Build a complete pricing summary for a booking.
 *
 * @param array  $attraction      attractions row
 * @param array  $pricing         attraction_pricing row
 * @param float  $hours           booked duration
 * @param array  $selected_addons [['addon' => row, 'quantity' => int], ...]
 * @param array|null $coupon      coupons row or null
 * @param float  $travel_miles    calculated one-way miles
 * @param array  $travel_config   travel_fee_config row
 * @param array  $tax_rates       tax_config rows
 * @return array                  full pricing breakdown
 */
function build_price_summary(
    array  $attraction,
    array  $pricing,
    float  $hours,
    array  $selected_addons,
    ?array $coupon,
    float  $travel_miles,
    array  $travel_config,
    array  $tax_rates
): array {
    $attraction_price = calc_attraction_price($pricing, $hours);

    // Add-ons
    $addons_taxable    = 0.0;
    $addons_nontaxable = 0.0;
    $addon_lines       = [];
    foreach ($selected_addons as $item) {
        $addon     = $item['addon'];
        $qty       = max(1, (int)$item['quantity']);
        $unit      = calc_addon_price($addon, $hours);
        $line      = round($unit * $qty, 2);
        if ($addon['is_taxable']) {
            $addons_taxable += $line;
        } else {
            $addons_nontaxable += $line;
        }
        $addon_lines[] = [
            'addon_id'    => $addon['id'],
            'addon_name'  => $addon['name'],
            'quantity'    => $qty,
            'unit_price'  => $unit,
            'total_price' => $line,
            'is_taxable'  => (bool)$addon['is_taxable'],
        ];
    }
    $addons_subtotal = round($addons_taxable + $addons_nontaxable, 2);

    // Coupon discount (never applies to travel fee)
    $discount = $coupon ? calc_coupon_discount($coupon, $attraction_price, $addons_subtotal) : 0.0;

    // Tax applies to attraction + taxable addons, after discount
    // Discount is proportioned: first from attraction, then from taxable addons
    $discount_on_attraction = min($discount, $attraction_price);
    $discount_on_addons     = max(0.0, $discount - $discount_on_attraction);
    $taxable_subtotal       = max(0.0, ($attraction_price - $discount_on_attraction) + ($addons_taxable - $discount_on_addons));

    $travel_fee = calc_travel_fee($travel_miles, (float)$travel_config['free_miles_threshold'], (float)$travel_config['rate_per_mile']);
    $tax        = calc_tax($taxable_subtotal, $tax_rates);
    $grand_total = round($taxable_subtotal + $addons_nontaxable + $tax['total'] + $travel_fee, 2);

    $combined_tax_rate = array_sum(array_column($tax_rates, 'rate'));

    return [
        'attraction_price'  => $attraction_price,
        'addons_subtotal'   => $addons_subtotal,
        'addon_lines'       => $addon_lines,
        'coupon_discount'   => $discount,
        'taxable_subtotal'  => $taxable_subtotal,
        'tax_rate'          => $combined_tax_rate,
        'tax_state'         => $tax['state'],
        'tax_county'        => $tax['county'],
        'tax_city'          => $tax['city'],
        'tax_total'         => $tax['total'],
        'travel_miles'      => $travel_miles,
        'travel_fee'        => $travel_fee,
        'grand_total'       => $grand_total,
        'deposit_amount'    => (float)$attraction['deposit_amount'],
        'balance_if_deposit' => round($grand_total - (float)$attraction['deposit_amount'], 2),
    ];
}
