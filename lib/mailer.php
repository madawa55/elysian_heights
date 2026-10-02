<?php
/**
 * Sends mail using config['mail_driver'] ('log', 'smtp' or 'mail').
 * Returns true on success; failures are logged, never thrown.
 */
function send_mail(string $to, string $subject, string $body, ?string $replyEmail = null, ?string $replyName = null): bool
{
    global $config;

    switch ($config['mail_driver'] ?? 'mail') {
        case 'log':
            $entry = str_repeat('=', 60) . "\nTo: {$to}\nSubject: {$subject}\n\n{$body}\n\n";
            return file_put_contents(dirname(__DIR__) . '/storage/mail.log', $entry, FILE_APPEND | LOCK_EX) !== false;

        case 'smtp':
            require_once __DIR__ . '/PHPMailer/Exception.php';
            require_once __DIR__ . '/PHPMailer/PHPMailer.php';
            require_once __DIR__ . '/PHPMailer/SMTP.php';

            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host       = $config['smtp']['host'];
                $mail->Port       = (int)$config['smtp']['port'];
                $mail->SMTPAuth   = true;
                $mail->SMTPSecure = $config['smtp']['secure'];
                $mail->Username   = $config['smtp']['username'];
                $mail->Password   = $config['smtp']['password'];
                $mail->CharSet    = 'UTF-8';

                $mail->setFrom($config['mail_from'], $config['site_name']);
                $mail->addAddress($to);
                if ($replyEmail) {
                    $mail->addReplyTo($replyEmail, (string)$replyName);
                }
                $mail->Subject = $subject;
                $mail->Body    = $body;
                $mail->send();
                return true;
            } catch (PHPMailer\PHPMailer\Exception $e) {
                error_log('Elysian Heights SMTP error: ' . $mail->ErrorInfo);
                return false;
            }

        default:
            $headers = [
                'From: ' . $config['site_name'] . ' <' . $config['mail_from'] . '>',
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=UTF-8',
                'Content-Transfer-Encoding: 8bit',
            ];
            if ($replyEmail) {
                $headers[] = 'Reply-To: ' . $replyName . ' <' . $replyEmail . '>';
            }
            return @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, implode("\r\n", $headers), '-f' . $config['mail_from']);
    }
}

// Strip CR/LF from anything that ends up in a mail header.
function header_safe(string $value): string
{
    return str_replace(["\r", "\n", '%0a', '%0d'], '', $value);
}

function booking_summary_text(array $b): string
{
    $lines = [
        "Booking reference: {$b['reference']}",
        "Name:       {$b['guest_name']}",
        "Email:      {$b['email']}",
        'Phone:      ' . ($b['phone'] ?: '-'),
        "Guests:     {$b['guests']}",
        "Check-in:   {$b['check_in']}",
        "Check-out:  {$b['check_out']}",
        "Nights:     {$b['nights']}",
        'Total:      ' . money((float)$b['total']),
    ];
    if ((float)$b['deposit_amount'] < (float)$b['total']) {
        $lines[] = 'Deposit:    ' . money((float)$b['deposit_amount']) . ' (to confirm the booking)';
    }
    if ((float)($b['amount_paid'] ?? 0) > 0) {
        $lines[] = 'Paid:       ' . money((float)$b['amount_paid']);
        $lines[] = 'Balance:    ' . money(max(0, (float)$b['total'] - (float)$b['amount_paid']));
    }
    $lines[] = 'Status:     ' . (BOOKING_STATUSES[$b['status']] ?? $b['status']);
    return implode("\n", $lines);
}

/** Bank transfer instructions for a pending booking: what to pay now, by when, and how. */
function payment_instructions_text(array $b): string
{
    $deposit = (float)$b['deposit_amount'];
    $total = (float)$b['total'];
    $lines = [
        'PAYMENT - DIRECT BANK TRANSFER',
        setting('bank_details'),
        '',
        'Amount to pay now: ' . money($deposit) . ($deposit < $total ? ' (deposit)' : ''),
    ];
    if (!empty($b['payment_due_at'])) {
        $lines[] = 'Please pay by: ' . date('d M Y, g:i A', strtotime($b['payment_due_at']))
            . ' - unpaid bookings are released after this time.';
    }
    if ($deposit < $total) {
        $lines[] = 'The balance of ' . money($total - $deposit) . ' is payable before check-in.';
    }
    $lines[] = "Please use your booking reference {$b['reference']} as the payment reference.";
    $lines[] = setting('payment_note');
    return implode("\n", $lines);
}
