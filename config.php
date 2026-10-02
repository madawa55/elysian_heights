<?php
/**
 * Site configuration for Elysian Heights.
 * Edit the values here — every page reads from this file.
 */

return [
    'site_name' => 'Elysian Heights',
    'tagline'   => 'A hillside villa retreat in Hindagala, Peradeniya',

    'address_lines' => [
        '85E, Megoda-Kalugamuwa',
        'Hindagala, Peradeniya',
        'Sri Lanka',
    ],

    // Used for the Google Maps embed and "Get directions" link.
    'map_query' => '85E Megoda-Kalugamuwa, Hindagala, Peradeniya, Sri Lanka',

    // 'tel' / 'whatsapp' must be digits only in international format (no +, no spaces).
    'phones' => [
        [
            'label'    => 'Booking Inquiry & Reservations',
            'display'  => '+94 76 100 9353',
            'tel'      => '94761009353',
            'whatsapp' => '94761009353',
        ],
        [
            'label'    => 'Guest Relations & Operations',
            'display'  => '+94 76 608 2892',
            'tel'      => '94766082892',
            'whatsapp' => '94766082892',
        ],
    ],

    'email' => 'info@elysianheight.com',

    /*
     * MySQL database for bookings, gallery and admin.
     * XAMPP default: user 'root', empty password. On cPanel, create the database
     * and user under "MySQL Databases" and put those values here.
     */
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'elysian_heights',
        'user' => 'root',
        'pass' => '',
    ],

    // Where new booking notifications are sent.
    'booking_notify_to' => 'info@elysianheight.com',

    // Where enquiry form submissions are delivered.
    'enquiry_to' => 'info@elysianheight.com',

    // "From" address for outgoing enquiry mails. Must be on your own domain,
    // otherwise most hosts / Gmail will reject or spam-folder the message.
    'mail_from' => 'info@elysianheight.com',

    /*
     * How enquiry emails are sent:
     *   'log'  - don't send; append each enquiry to storage/enquiries.log (for XAMPP testing)
     *   'smtp' - send through an SMTP mailbox (works on XAMPP and live hosting)
     *   'mail' - PHP's built-in mail() (most shared hosts; does NOT work on XAMPP)
     */
    'mail_driver' => 'smtp',

    // Only used when mail_driver = 'smtp'. Values from cPanel > Email Accounts > Connect Devices.
    'smtp' => [
        'host'     => 'node245.r-sg.register.lk',
        'port'     => 465,
        'secure'   => 'ssl',   // 'ssl' for 465, 'tls' for 587
        'username' => 'info@elysianheight.com',
        'password' => 'ElysianHeights@123',
    ],
];
