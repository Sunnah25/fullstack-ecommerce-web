<?php
// ============================================================
// UK SHIPPING CALCULATOR — for admin use only
// Based on real Royal Mail + Evri 2024/25 pricing
// Customers always get free delivery
// ============================================================

function calculateShipping($weightGrams) {
    $options = [];

    // ── ROYAL MAIL ──────────────────────────────────────────
    if ($weightGrams <= 750) {
        $options[] = [
            'carrier' => 'Royal Mail',
            'service' => 'Royal Mail 2nd Class Large Letter',
            'code'    => 'RM_2CLL',
            'cost'    => 1.55,
            'days'    => '2-3 working days',
            'tracked' => false,
        ];
        $options[] = [
            'carrier' => 'Royal Mail',
            'service' => 'Royal Mail 1st Class Large Letter',
            'code'    => 'RM_1CLL',
            'cost'    => 2.10,
            'days'    => '1-2 working days',
            'tracked' => false,
        ];
    }

    if ($weightGrams <= 2000) {
        $options[] = [
            'carrier' => 'Royal Mail',
            'service' => 'Royal Mail Tracked 48',
            'code'    => 'RM_T48',
            'cost'    => 3.49,
            'days'    => '2-3 working days',
            'tracked' => true,
        ];
        $options[] = [
            'carrier' => 'Royal Mail',
            'service' => 'Royal Mail Tracked 24',
            'code'    => 'RM_T24',
            'cost'    => 4.49,
            'days'    => 'Next working day',
            'tracked' => true,
        ];
    }

    if ($weightGrams <= 10000) {
        $options[] = [
            'carrier' => 'Royal Mail',
            'service' => 'Royal Mail Tracked 48 (Medium Parcel)',
            'code'    => 'RM_T48M',
            'cost'    => 5.99,
            'days'    => '2-3 working days',
            'tracked' => true,
        ];
        $options[] = [
            'carrier' => 'Royal Mail',
            'service' => 'Royal Mail Tracked 24 (Medium Parcel)',
            'code'    => 'RM_T24M',
            'cost'    => 7.49,
            'days'    => 'Next working day',
            'tracked' => true,
        ];
    }

    // ── EVRI ────────────────────────────────────────────────
    if ($weightGrams <= 1000) {
        $options[] = [
            'carrier' => 'Evri',
            'service' => 'Evri Small Parcel',
            'code'    => 'EVRI_SM',
            'cost'    => 2.99,
            'days'    => '2-4 working days',
            'tracked' => true,
        ];
    }

    if ($weightGrams <= 5000) {
        $options[] = [
            'carrier' => 'Evri',
            'service' => 'Evri Medium Parcel',
            'code'    => 'EVRI_MD',
            'cost'    => 3.99,
            'days'    => '2-4 working days',
            'tracked' => true,
        ];
    }

    if ($weightGrams <= 15000) {
        $options[] = [
            'carrier' => 'Evri',
            'service' => 'Evri Large Parcel',
            'code'    => 'EVRI_LG',
            'cost'    => 5.49,
            'days'    => '2-4 working days',
            'tracked' => true,
        ];
    }

    // Sort cheapest first
    usort($options, fn($a, $b) => $a['cost'] <=> $b['cost']);
    return $options;
}

function formatWeight($grams) {
    return $grams >= 1000
        ? number_format($grams / 1000, 2) . 'kg'
        : $grams . 'g';
}
?>