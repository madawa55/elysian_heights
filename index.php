<?php
require __DIR__ . '/lib/bootstrap.php';

// The site still renders (without gallery/booking data) if the database is unreachable.
try {
    $gallery = gallery_images();
} catch (Throwable $e) {
    error_log('Elysian Heights: gallery unavailable: ' . $e->getMessage());
    $gallery = [];
}

$mapQuery = urlencode($config['map_query']);
$year = date('Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($config['site_name']) ?> | Villa in Hindagala, Peradeniya</title>
    <meta name="description" content="<?= e($config['site_name']) ?> — <?= e($config['tagline']) ?>. Contact us for bookings and reservations.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<header class="site-header" id="top">
    <nav class="nav container">
        <a href="#home" class="brand"><?= e($config['site_name']) ?></a>
        <button class="nav-toggle" aria-label="Open menu" aria-expanded="false">
            <span></span><span></span><span></span>
        </button>
        <ul class="nav-links">
            <li><a href="#home">Home</a></li>
            <li><a href="#about">The Villa</a></li>
            <?php if ($gallery): ?><li><a href="#gallery">Gallery</a></li><?php endif; ?>
            <li><a href="#contact">Contact</a></li>
            <li><a href="#location">Location</a></li>
            <li><a href="#enquiry">Enquire</a></li>
            <li><a href="#book" class="btn btn-small">Book Now</a></li>
        </ul>
    </nav>
</header>

<main>
    <!-- Hero -->
    <section class="hero" id="home">
        <div class="hero-slides">
            <div class="hero-slide active" style="background-image: url('assets/images/villa.png');"></div>
            <div class="hero-slide" style="background-image: url('assets/images/villa2.png');"></div>
        </div>
        <div class="hero-overlay"></div>
        <div class="hero-dots">
            <button type="button" class="active" aria-label="Show slide 1"></button>
            <button type="button" aria-label="Show slide 2"></button>
        </div>
        <div class="hero-content container">
            <p class="eyebrow">Hindagala · Peradeniya · Sri Lanka</p>
            <h1><?= e($config['site_name']) ?></h1>
            <p class="lead"><?= e($config['tagline']) ?></p>
            <div class="hero-actions">
                <a href="#book" class="btn">Check Availability</a>
                <a href="https://wa.me/<?= e($config['phones'][0]['whatsapp']) ?>" class="btn btn-ghost" target="_blank" rel="noopener">WhatsApp Us</a>
            </div>
        </div>
    </section>

    <!-- About -->
    <section class="section" id="about">
        <div class="container about-grid">
            <div class="about-image">
                <img src="assets/images/villa.png" alt="<?= e($config['site_name']) ?> villa surrounded by tropical gardens" loading="lazy">
            </div>
            <div class="about-text">
                <p class="eyebrow">The Villa</p>
                <h2>Your private escape in the hills</h2>
                <p>Set among lush tropical gardens above Hindagala, Elysian Heights is a peaceful hillside villa just minutes from Peradeniya and the Royal Botanical Gardens, with Kandy a short drive away.</p>
                <p>Wake up to birdsong and cool mountain air, relax on the balconies overlooking the greenery, and let our team take care of the rest.</p>
                <a href="#book" class="btn">Check Availability</a>
            </div>
        </div>
    </section>

    <?php if ($gallery): ?>
    <!-- Gallery -->
    <section class="section" id="gallery">
        <div class="container">
            <div class="section-head">
                <p class="eyebrow">Gallery</p>
                <h2>A Glimpse of Elysian Heights</h2>
            </div>
            <div class="gallery-grid">
                <?php foreach ($gallery as $i => $img): ?>
                    <a class="gallery-item" href="<?= GALLERY_DIR ?>/<?= e($img['filename']) ?>" data-index="<?= $i ?>" data-caption="<?= e($img['caption']) ?>">
                        <img src="<?= GALLERY_DIR ?>/<?= e($img['filename']) ?>" alt="<?= e($img['caption'] ?: $config['site_name'] . ' photo ' . ($i + 1)) ?>" loading="lazy">
                        <?php if ($img['caption']): ?><span class="gallery-caption"><?= e($img['caption']) ?></span><?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <div class="lightbox" id="lightbox" hidden>
        <button type="button" class="lb-close" aria-label="Close gallery">&times;</button>
        <button type="button" class="lb-prev" aria-label="Previous photo">&#8249;</button>
        <figure>
            <img src="" alt="">
            <figcaption></figcaption>
        </figure>
        <button type="button" class="lb-next" aria-label="Next photo">&#8250;</button>
    </div>
    <?php endif; ?>

    <!-- Availability & booking -->
    <section class="section section-alt" id="book">
        <div class="container">
            <div class="section-head">
                <p class="eyebrow">Availability</p>
                <h2>Check Dates &amp; Book</h2>
                <p>Pick your check-in and check-out dates on the calendar. Bookings are confirmed once the bank transfer is received.</p>
            </div>

            <div class="booking-grid">
                <div class="booking-cal">
                    <div class="cal-toolbar">
                        <button type="button" class="cal-nav-btn" data-cal="prev" aria-label="Previous month">&#8249;</button>
                        <p class="cal-hint" id="cal-hint">Select your check-in date</p>
                        <button type="button" class="cal-nav-btn" data-cal="next" aria-label="Next month">&#8250;</button>
                    </div>
                    <div class="cal-months" id="cal-months" aria-live="polite">
                        <p class="cal-loading">Loading availability…</p>
                    </div>
                    <div class="cal-legend">
                        <span><i class="lg-free"></i>Available</span>
                        <span><i class="lg-selected"></i>Your stay</span>
                        <span><i class="lg-off"></i>Booked</span>
                    </div>
                </div>

                <div class="booking-panel">
                    <form id="booking-form" class="booking-form" action="api/book.php" method="post" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="check_in">
                        <input type="hidden" name="check_out">
                        <div class="hp" aria-hidden="true">
                            <label>Leave this empty <input type="text" name="website" tabindex="-1" autocomplete="off"></label>
                        </div>

                        <div class="stay-summary">
                            <div><span>Check-in</span><strong data-sum="in">—</strong></div>
                            <div><span>Check-out</span><strong data-sum="out">—</strong></div>
                            <div><span>Nights</span><strong data-sum="nights">—</strong></div>
                            <div><span>Total</span><strong data-sum="total">—</strong></div>
                            <div class="deposit-row" hidden><span>Deposit to confirm</span><strong data-sum="deposit">—</strong></div>
                        </div>
                        <p class="rate-note" data-sum="rate"></p>

                        <fieldset class="guest-fields" disabled>
                            <div class="field-row">
                                <div class="field">
                                    <label for="b_name">Full Name *</label>
                                    <input type="text" id="b_name" name="name" required maxlength="100" autocomplete="name">
                                </div>
                                <div class="field">
                                    <label for="b_guests">Guests *</label>
                                    <input type="number" id="b_guests" name="guests" min="1" max="50" value="2" required>
                                </div>
                            </div>
                            <div class="field-row field-row-even">
                                <div class="field">
                                    <label for="b_email">Email *</label>
                                    <input type="email" id="b_email" name="email" required maxlength="150" autocomplete="email">
                                </div>
                                <div class="field">
                                    <label for="b_phone">Phone / WhatsApp *</label>
                                    <input type="tel" id="b_phone" name="phone" required maxlength="30" autocomplete="tel">
                                </div>
                            </div>
                            <div class="field">
                                <label for="b_notes">Special requests</label>
                                <textarea id="b_notes" name="notes" rows="2" maxlength="2000" placeholder="Arrival time, occasions, anything we should know"></textarea>
                            </div>
                            <button type="submit" class="btn btn-block">Book Now · Pay by Bank Transfer</button>
                        </fieldset>
                        <div class="form-status" role="status" aria-live="polite"></div>
                    </form>

                    <div class="booking-success" hidden>
                        <div class="success-icon" aria-hidden="true">&#10003;</div>
                        <h3>Booking received!</h3>
                        <p>Your dates are reserved. Your booking reference is</p>
                        <p class="booking-ref" data-success="reference"></p>
                        <p>Total for your stay: <strong data-success="total"></strong></p>
                        <p class="pay-now-line">Amount to pay now<span data-success="deposit-label"></span>: <strong data-success="pay_now"></strong></p>
                        <p data-success="balance-line" hidden>The remaining <strong data-success="balance"></strong> is payable before check-in.</p>
                        <p class="pay-by" data-success="payby-line" hidden>Please pay by <strong data-success="pay_by"></strong>. Unpaid bookings are released after this time.</p>
                        <div class="bank-box">
                            <p class="eyebrow">Pay by direct bank transfer</p>
                            <pre data-success="bank"></pre>
                            <p data-success="note"></p>
                        </div>
                        <p class="muted-small">We have emailed these details to you. Your booking is confirmed once we receive the payment.</p>
                        <button type="button" class="btn btn-small btn-outline-dark" data-action="new-booking">Make another booking</button>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Contact details -->
    <section class="section section-alt" id="contact">
        <div class="container">
            <div class="section-head">
                <p class="eyebrow">Get in Touch</p>
                <h2>Contact Details</h2>
            </div>
            <div class="contact-cards">
                <?php foreach ($config['phones'] as $phone): ?>
                    <div class="card">
                        <div class="card-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24"><path d="M6.6 10.8a15.1 15.1 0 0 0 6.6 6.6l2.2-2.2a1 1 0 0 1 1-.25 11.4 11.4 0 0 0 3.6.57 1 1 0 0 1 1 1V20a1 1 0 0 1-1 1A17 17 0 0 1 3 4a1 1 0 0 1 1-1h3.5a1 1 0 0 1 1 1c0 1.25.2 2.45.57 3.57a1 1 0 0 1-.25 1z"/></svg>
                        </div>
                        <h3><?= e($phone['label']) ?></h3>
                        <p class="card-value"><?= e($phone['display']) ?></p>
                        <p class="card-note">Calls + WhatsApp</p>
                        <div class="card-actions">
                            <a href="tel:+<?= e($phone['tel']) ?>" class="btn btn-small">Call</a>
                            <a href="https://wa.me/<?= e($phone['whatsapp']) ?>" class="btn btn-small btn-whatsapp" target="_blank" rel="noopener">WhatsApp</a>
                        </div>
                    </div>
                <?php endforeach; ?>

                <div class="card">
                    <div class="card-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><path d="M20 4H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2zm0 4-8 5-8-5V6l8 5 8-5z"/></svg>
                    </div>
                    <h3>Email</h3>
                    <p class="card-value"><?= e($config['email']) ?></p>
                    <p class="card-note">We reply within 24 hours</p>
                    <div class="card-actions">
                        <a href="mailto:<?= e($config['email']) ?>" class="btn btn-small">Send Email</a>
                    </div>
                </div>

                <div class="card">
                    <div class="card-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><path d="M12 2a7 7 0 0 0-7 7c0 5.25 7 13 7 13s7-7.75 7-13a7 7 0 0 0-7-7zm0 9.5A2.5 2.5 0 1 1 12 6.5a2.5 2.5 0 0 1 0 5z"/></svg>
                    </div>
                    <h3>Address</h3>
                    <p class="card-value address">
                        <?= implode('<br>', array_map('e', $config['address_lines'])) ?>
                    </p>
                    <div class="card-actions">
                        <a href="https://www.google.com/maps/dir/?api=1&destination=<?= $mapQuery ?>" class="btn btn-small" target="_blank" rel="noopener">Get Directions</a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Map -->
    <section class="section" id="location">
        <div class="container">
            <div class="section-head">
                <p class="eyebrow">Find Us</p>
                <h2>Location</h2>
            </div>
            <div class="map-wrap">
                <iframe
                    title="Map showing <?= e($config['site_name']) ?>"
                    src="https://www.google.com/maps?q=<?= $mapQuery ?>&output=embed"
                    loading="lazy"
                    referrerpolicy="no-referrer-when-downgrade"
                    allowfullscreen></iframe>
            </div>
        </div>
    </section>

    <!-- Enquiry form -->
    <section class="section section-alt" id="enquiry">
        <div class="container enquiry-wrap">
            <div class="section-head">
                <p class="eyebrow">Booking Inquiry</p>
                <h2>Send Us an Enquiry</h2>
                <p>Tell us your dates and we'll get back to you with availability and rates.</p>
            </div>

            <form id="enquiry-form" class="enquiry-form" action="send-enquiry.php" method="post" novalidate>
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <!-- Honeypot: hidden from humans, bots tend to fill it -->
                <div class="hp" aria-hidden="true">
                    <label>Leave this empty <input type="text" name="website" tabindex="-1" autocomplete="off"></label>
                </div>

                <div class="form-grid">
                    <div class="field">
                        <label for="name">Full Name *</label>
                        <input type="text" id="name" name="name" required maxlength="100" autocomplete="name">
                    </div>
                    <div class="field">
                        <label for="email">Email *</label>
                        <input type="email" id="email" name="email" required maxlength="150" autocomplete="email">
                    </div>
                    <div class="field">
                        <label for="phone">Phone / WhatsApp</label>
                        <input type="tel" id="phone" name="phone" maxlength="30" autocomplete="tel">
                    </div>
                    <div class="field">
                        <label for="guests">Number of Guests</label>
                        <input type="number" id="guests" name="guests" min="1" max="50">
                    </div>
                    <div class="field">
                        <label for="check_in">Check-in</label>
                        <input type="date" id="check_in" name="check_in">
                    </div>
                    <div class="field">
                        <label for="check_out">Check-out</label>
                        <input type="date" id="check_out" name="check_out">
                    </div>
                    <div class="field field-full">
                        <label for="message">Message *</label>
                        <textarea id="message" name="message" rows="5" required maxlength="3000"></textarea>
                    </div>
                </div>

                <button type="submit" class="btn btn-block">Send Enquiry</button>
                <div class="form-status" role="status" aria-live="polite"></div>
            </form>
        </div>
    </section>
</main>

<footer class="site-footer">
    <div class="container footer-inner">
        <p class="brand"><?= e($config['site_name']) ?></p>
        <p><?= implode(', ', array_map('e', $config['address_lines'])) ?></p>
        <p>
            <?php foreach ($config['phones'] as $i => $phone): ?>
                <?= $i ? ' · ' : '' ?><a href="tel:+<?= e($phone['tel']) ?>"><?= e($phone['display']) ?></a>
            <?php endforeach; ?>
            · <a href="mailto:<?= e($config['email']) ?>"><?= e($config['email']) ?></a>
        </p>
        <p class="copy">&copy; <?= $year ?> <?= e($config['site_name']) ?>. All rights reserved.</p>
    </div>
</footer>

<a href="https://wa.me/<?= e($config['phones'][0]['whatsapp']) ?>" class="whatsapp-float" target="_blank" rel="noopener" aria-label="Chat on WhatsApp">
    <svg viewBox="0 0 32 32" aria-hidden="true"><path d="M16 3a13 13 0 0 0-11.2 19.6L3 29l6.6-1.7A13 13 0 1 0 16 3zm0 23.7a10.7 10.7 0 0 1-5.5-1.5l-.4-.2-3.9 1 1-3.8-.2-.4A10.7 10.7 0 1 1 16 26.7zm5.9-8c-.3-.2-1.9-1-2.2-1s-.5-.2-.7.2-.8 1-1 1.2-.4.2-.7 0a8.8 8.8 0 0 1-4.4-3.8c-.3-.6.3-.5.9-1.7a.6.6 0 0 0 0-.5l-1-2.4c-.3-.6-.5-.5-.7-.5h-.6a1.2 1.2 0 0 0-.9.4 3.7 3.7 0 0 0-1.1 2.7 6.4 6.4 0 0 0 1.3 3.4 14.7 14.7 0 0 0 5.7 5c2.1.9 2.9 1 4 .8a3.4 3.4 0 0 0 2.2-1.6 2.8 2.8 0 0 0 .2-1.6c-.1-.1-.3-.2-.6-.4z"/></svg>
</a>

<script src="assets/js/main.js"></script>
<script src="assets/js/gallery.js"></script>
<script src="assets/js/booking.js"></script>
</body>
</html>
