<?php
/**
 * Shared helper functions for admin pages.
 */

function booking_status_badge(string $status): string {
    $map = [
        'pending'     => ['label' => 'Pending',     'class' => 'badge-warning'],
        'confirmed'   => ['label' => 'Confirmed',   'class' => 'badge-success'],
        'cancelled'   => ['label' => 'Cancelled',   'class' => 'badge-danger'],
        'completed'   => ['label' => 'Completed',   'class' => 'badge-info'],
        'rescheduled' => ['label' => 'Rescheduled', 'class' => 'badge-info'],
    ];
    $b = $map[$status] ?? ['label' => ucfirst($status), 'class' => 'badge-default'];
    return '<span class="badge ' . $b['class'] . '">' . $b['label'] . '</span>';
}

function payment_status_badge(string $status): string {
    $map = [
        'unpaid'         => ['label' => 'Unpaid',        'class' => 'badge-danger'],
        'deposit_paid'   => ['label' => 'Deposit Paid',  'class' => 'badge-warning'],
        'paid_in_full'   => ['label' => 'Paid in Full',  'class' => 'badge-success'],
        'refunded'       => ['label' => 'Refunded',      'class' => 'badge-info'],
        'collect_later'  => ['label' => 'Collect Later', 'class' => 'badge-default'],
    ];
    $b = $map[$status] ?? ['label' => ucfirst($status), 'class' => 'badge-default'];
    return '<span class="badge ' . $b['class'] . '">' . $b['label'] . '</span>';
}
