<?php
/**
 * GET  -> calendar data: unavailable nights for the booking window + pricing rules.
 * GET ?check_in=Y-m-d&check_out=Y-m-d -> also says whether that stay is available.
 */
require dirname(__DIR__) . '/lib/bootstrap.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

try {
    $today = date('Y-m-d');
    $until = date('Y-m-d', strtotime('+' . BOOKING_WINDOW_MONTHS . ' months'));

    $response = [
        'ok'           => true,
        'today'        => $today,
        'until'        => $until,
        'unavailable'  => unavailable_nights($today, $until),
        'nightly_rate' => (float)setting('nightly_rate'),
        // Seasonal rates (inclusive date ranges) that override the standard nightly rate.
        'seasons'      => array_values(array_map(fn($r) => [
            'name'  => $r['name'],
            'start' => $r['start_date'],
            'end'   => $r['end_date'],
            'rate'  => (float)$r['nightly_rate'],
        ], array_filter(rate_seasons(), fn($r) => $r['end_date'] >= $today))),
        'currency'        => setting('currency'),
        'min_nights'      => max(1, (int)setting('min_nights')),
        'max_guests'      => max(1, (int)setting('max_guests')),
        'deposit_percent' => (float)setting('deposit_percent'),
        'hold_hours'      => (int)setting('pending_hold_hours'),
    ];

    $in = (string)($_GET['check_in'] ?? '');
    $out = (string)($_GET['check_out'] ?? '');
    if ($in !== '' || $out !== '') {
        if (!valid_date($in) || !valid_date($out) || $out <= $in || $in < $today) {
            $response['available'] = false;
            $response['message'] = 'Please choose a valid check-in and check-out date.';
        } else {
            $price = price_stay($in, $out);
            $response['available'] = range_available($in, $out);
            $response['nights'] = $price['nights'];
            $response['total'] = $price['total'];
            $response['deposit'] = deposit_for($price['total']);
            $response['lines'] = $price['lines'];
        }
    }

    echo json_encode($response);
} catch (Throwable $e) {
    error_log('Elysian Heights availability error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Availability is temporarily unavailable. Please contact us directly.']);
}
