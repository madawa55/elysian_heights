-- Elysian Heights booking system schema (MySQL 5.7+ / MariaDB 10.3+)
-- Run automatically by admin/setup.php, or import manually via phpMyAdmin.

CREATE TABLE IF NOT EXISTS admins (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(60)  NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bookings (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reference       VARCHAR(20)   NOT NULL UNIQUE,
    guest_name      VARCHAR(100)  NOT NULL,
    email           VARCHAR(150)  NOT NULL,
    phone           VARCHAR(30)   NOT NULL DEFAULT '',
    guests          SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    check_in        DATE          NOT NULL,
    check_out       DATE          NOT NULL,
    nights          SMALLINT UNSIGNED NOT NULL,
    nightly_rate    DECIMAL(12,2) NOT NULL,              -- average rate per night (informational)
    total           DECIMAL(12,2) NOT NULL,
    price_breakdown TEXT          NULL,                  -- JSON: [{from, to, nights, rate, label}]
    deposit_amount  DECIMAL(12,2) NOT NULL DEFAULT 0,    -- amount required to confirm the booking
    amount_paid     DECIMAL(12,2) NOT NULL DEFAULT 0,    -- sum of the payments table
    status          ENUM('pending_payment','confirmed','completed','cancelled') NOT NULL DEFAULT 'pending_payment',
    payment_due_at  DATETIME      NULL,                  -- unpaid pending bookings are auto-cancelled after this
    confirmed_at    DATETIME      NULL,
    guest_notes     TEXT          NULL,
    admin_notes     TEXT          NULL,
    created_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_dates (check_in, check_out),
    INDEX idx_status (status),
    INDEX idx_due (status, payment_due_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payments (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_id  INT UNSIGNED  NOT NULL,
    amount      DECIMAL(12,2) NOT NULL,
    reference   VARCHAR(100)  NOT NULL DEFAULT '',
    paid_on     DATE          NOT NULL,
    note        VARCHAR(255)  NOT NULL DEFAULT '',
    created_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_booking (booking_id),
    INDEX idx_paid_on (paid_on),
    CONSTRAINT fk_payments_booking FOREIGN KEY (booking_id) REFERENCES bookings (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_seasons (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(100)  NOT NULL,
    start_date    DATE          NOT NULL,
    end_date      DATE          NOT NULL,               -- inclusive: the last night charged at this rate
    nightly_rate  DECIMAL(12,2) NOT NULL,
    created_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_range (start_date, end_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS blocked_dates (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    blocked_date DATE         NOT NULL UNIQUE,
    reason       VARCHAR(150) NOT NULL DEFAULT '',
    created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gallery_images (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    filename    VARCHAR(255) NOT NULL,
    caption     VARCHAR(200) NOT NULL DEFAULT '',
    sort_order  INT          NOT NULL DEFAULT 0,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
    name  VARCHAR(60) PRIMARY KEY,
    value TEXT        NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
