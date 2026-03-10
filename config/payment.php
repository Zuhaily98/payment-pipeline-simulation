<?php

return [
    // list of accepted 3-letter currency codes
    // change via env: PAYMENT_CURRENCIES=MYR,USD,EUR
    'supported_currencies' => explode(',', env('PAYMENT_CURRENCIES', 'MYR,USD,EUR,GBP,SGD,JPY')),

    // payments with amount >= this go through PRE_SETTLEMENT_REVIEW after authorization
    // set to 0 to disable review for all payments
    'review_threshold' => (float) env('PAYMENT_REVIEW_THRESHOLD', 1000.00),

    // whether to track partial refund amounts
    // if false, any REFUND moves straight to REFUNDED regardless of amount
    'partial_refunds' => (bool) env('PAYMENT_PARTIAL_REFUNDS', true),
];