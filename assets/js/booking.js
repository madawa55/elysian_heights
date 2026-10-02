(function () {
    var monthsEl = document.getElementById('cal-months');
    var form = document.getElementById('booking-form');
    if (!monthsEl || !form) return;

    var hint = document.getElementById('cal-hint');
    var prevBtn = document.querySelector('[data-cal="prev"]');
    var nextBtn = document.querySelector('[data-cal="next"]');
    var fields = form.querySelector('.guest-fields');
    var status = form.querySelector('.form-status');
    var submitBtn = form.querySelector('button[type="submit"]');
    var success = document.querySelector('.booking-success');
    var mobile = window.matchMedia('(max-width: 640px)');

    var MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    var DOW = ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'];

    var data = null;          // response from api/availability.php
    var unavailable = {};     // 'Y-m-d' => true for each taken night
    var view = null;          // {y, m} of the first month shown
    var checkIn = null;
    var checkOut = null;

    /* ---------- date helpers (UTC, so time zones never shift a day) ---------- */
    function parse(s) { var p = s.split('-'); return new Date(Date.UTC(+p[0], +p[1] - 1, +p[2])); }
    function fmt(d) { return d.toISOString().slice(0, 10); }
    function addDays(s, n) { var d = parse(s); d.setUTCDate(d.getUTCDate() + n); return fmt(d); }
    function nights(a, b) { return Math.round((parse(b) - parse(a)) / 86400000); }
    function pretty(s) {
        var d = parse(s);
        return d.getUTCDate() + ' ' + MONTHS[d.getUTCMonth()].slice(0, 3) + ' ' + d.getUTCFullYear();
    }
    function money(n) {
        return data.currency + ' ' + Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    // Same rules as price_stay() in lib/bootstrap.php; the server recalculates on booking.
    function rateFor(night) {
        for (var i = 0; i < data.seasons.length; i++) {
            var s = data.seasons[i];
            if (night >= s.start && night <= s.end) return s.rate;
        }
        return data.nightly_rate;
    }
    function priceStay(a, b) {
        var total = 0;
        for (var d = a; d < b; d = addDays(d, 1)) total += rateFor(d);
        return Math.round(total * 100) / 100;
    }
    function depositFor(total) {
        var pct = data.deposit_percent;
        return pct > 0 && pct < 100 ? Math.round(total * pct) / 100 : total;
    }

    // Last date that can be a check-out for the current check-in: the first taken night after it.
    function checkoutLimit() {
        for (var d = addDays(checkIn, 1); d < data.until; d = addDays(d, 1)) {
            if (unavailable[d]) return d;
        }
        return data.until;
    }

    /* ---------- loading ---------- */
    function load() {
        return fetch('api/availability.php', { cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res.ok) throw new Error(res.message);
                data = res;
                unavailable = {};
                res.unavailable.forEach(function (d) { unavailable[d] = true; });
                if (!view) {
                    var t = parse(res.today);
                    view = { y: t.getUTCFullYear(), m: t.getUTCMonth() };
                }
                document.getElementById('b_guests').max = res.max_guests;
                var lowest = res.seasons.reduce(function (min, s) { return Math.min(min, s.rate); }, res.nightly_rate);
                var note = (lowest < res.nightly_rate ? 'From ' + money(lowest) : money(res.nightly_rate)) +
                    ' per night for the whole villa';
                if (res.seasons.length) note += ' (seasonal rates apply on some dates)';
                note += ' · up to ' + res.max_guests + ' guests';
                if (res.min_nights > 1) note += ' · minimum ' + res.min_nights + ' nights';
                if (res.deposit_percent > 0 && res.deposit_percent < 100) note += ' · ' + res.deposit_percent + '% deposit confirms your booking';
                form.querySelector('[data-sum="rate"]').textContent = note;
                render();
            })
            .catch(function () {
                monthsEl.innerHTML = '<p class="cal-error">Sorry, availability could not be loaded. Please contact us by phone or WhatsApp.</p>';
            });
    }

    /* ---------- rendering ---------- */
    function render() {
        var count = mobile.matches ? 1 : 2;
        var limit = checkIn && !checkOut ? checkoutLimit() : null;
        var html = '';

        for (var i = 0; i < count; i++) {
            var y = view.y + Math.floor((view.m + i) / 12);
            var m = (view.m + i) % 12;
            var first = new Date(Date.UTC(y, m, 1));
            var days = new Date(Date.UTC(y, m + 1, 0)).getUTCDate();
            var lead = (first.getUTCDay() + 6) % 7; // Monday first

            html += '<div class="cal-month"><h3>' + MONTHS[m] + ' ' + y + '</h3><div class="cal-grid">';
            DOW.forEach(function (d) { html += '<div class="cal-dow">' + d + '</div>'; });
            for (var l = 0; l < lead; l++) html += '<span></span>';

            for (var day = 1; day <= days; day++) {
                var ds = fmt(new Date(Date.UTC(y, m, day)));
                var cls = ['cal-day'];
                var disabled = ds < data.today || ds > data.until;
                var off = !!unavailable[ds];

                if (off) cls.push('off');
                if (ds === data.today) cls.push('today');

                if (limit) {
                    // Choosing a check-out: allow dates after check-in up to the next taken night.
                    if (ds > checkIn && ds <= limit) {
                        if (off) cls.push('checkout-ok');
                    } else if (ds > limit || off) {
                        disabled = true;
                    }
                } else if (off) {
                    disabled = true;
                }

                if (checkIn && ds === checkIn) cls.push('start');
                if (checkOut && ds === checkOut) cls.push('end');
                if (checkIn && checkOut && ds > checkIn && ds < checkOut) cls.push('in-range');

                html += '<button type="button" class="' + cls.join(' ') + '" data-date="' + ds + '"' +
                    (disabled ? ' disabled' : '') + ' aria-label="' + pretty(ds) + (off ? ' (booked)' : '') + '"' +
                    (!off && !disabled ? ' title="' + money(rateFor(ds)) + ' / night"' : '') + '>' + day + '</button>';
            }
            html += '</div></div>';
        }
        monthsEl.innerHTML = html;

        var t = parse(data.today), u = parse(data.until);
        prevBtn.disabled = view.y < t.getUTCFullYear() || (view.y === t.getUTCFullYear() && view.m <= t.getUTCMonth());
        var lastShown = view.y * 12 + view.m + count - 1;
        nextBtn.disabled = lastShown >= u.getUTCFullYear() * 12 + u.getUTCMonth();

        updateSummary();
    }

    function setHint(text, isError) {
        hint.textContent = text;
        hint.style.color = isError ? '#a52a2a' : '';
    }

    function updateSummary() {
        var sum = function (k) { return form.querySelector('[data-sum="' + k + '"]'); };
        sum('in').textContent = checkIn ? pretty(checkIn) : '—';
        sum('out').textContent = checkOut ? pretty(checkOut) : '—';
        var n = checkIn && checkOut ? nights(checkIn, checkOut) : 0;
        sum('nights').textContent = n || '—';
        var total = n ? priceStay(checkIn, checkOut) : 0;
        var deposit = depositFor(total);
        sum('total').textContent = n ? money(total) : '—';
        sum('deposit').textContent = n ? money(deposit) : '—';
        sum('deposit').parentNode.hidden = !(n && deposit < total);
        form.check_in.value = checkIn || '';
        form.check_out.value = checkOut || '';
        fields.disabled = !n;
    }

    /* ---------- interaction ---------- */
    monthsEl.addEventListener('click', function (e) {
        var btn = e.target.closest('.cal-day');
        if (!btn || btn.disabled) return;
        var ds = btn.dataset.date;

        if (checkIn && !checkOut && ds > checkIn) {
            var n = nights(checkIn, ds);
            if (n < data.min_nights) {
                setHint('The minimum stay is ' + data.min_nights + ' nights. Choose a later check-out date.', true);
                return;
            }
            checkOut = ds;
            setHint(n + ' night' + (n > 1 ? 's' : '') + ' selected — fill in your details to book', false);
        } else if (!unavailable[ds]) {
            checkIn = ds;
            checkOut = null;
            setHint('Now select your check-out date', false);
        }
        status.className = 'form-status';
        status.textContent = '';
        render();
    });

    prevBtn.addEventListener('click', function () {
        view.m--; if (view.m < 0) { view.m = 11; view.y--; }
        render();
    });
    nextBtn.addEventListener('click', function () {
        view.m++; if (view.m > 11) { view.m = 0; view.y++; }
        render();
    });
    var onResize = function () { if (data) render(); };
    if (mobile.addEventListener) mobile.addEventListener('change', onResize);
    else mobile.addListener(onResize); // Safari 13 and older

    function showStatus(message, ok) {
        status.textContent = message;
        status.className = 'form-status ' + (ok ? 'success' : 'error');
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        if (!checkIn || !checkOut) {
            showStatus('Please select your check-in and check-out dates on the calendar.', false);
            return;
        }
        var name = form.elements.name.value.trim();
        var email = form.elements.email.value.trim();
        var phone = form.elements.phone.value.trim();
        var guests = parseInt(form.elements.guests.value, 10);
        if (!name || !phone || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            showStatus('Please enter your name, a valid email and a phone number.', false);
            return;
        }
        if (!(guests >= 1 && guests <= data.max_guests)) {
            showStatus('Number of guests must be between 1 and ' + data.max_guests + '.', false);
            return;
        }

        submitBtn.disabled = true;
        submitBtn.textContent = 'Booking…';
        status.className = 'form-status';
        status.textContent = '';

        // Read the fields before anything disables the fieldset (disabled fields are left out of FormData).
        var body = new FormData(form);

        fetch(form.action, { method: 'POST', body: body })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res.ok) {
                    success.querySelector('[data-success="reference"]').textContent = res.reference;
                    var put = function (k, v) { success.querySelector('[data-success="' + k + '"]').textContent = v; };
                    var showLine = function (k, on) { success.querySelector('[data-success="' + k + '"]').hidden = !on; };
                    put('total', res.total);
                    put('pay_now', res.pay_now);
                    put('deposit-label', res.is_deposit ? ' (deposit)' : '');
                    put('balance', res.balance || '');
                    put('pay_by', res.pay_by || '');
                    showLine('balance-line', res.is_deposit);
                    showLine('payby-line', !!res.pay_by);
                    success.querySelector('[data-success="bank"]').textContent = res.bank_details;
                    success.querySelector('[data-success="note"]').textContent = res.payment_note;
                    form.hidden = true;
                    success.hidden = false;
                    success.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    form.reset();
                    checkIn = checkOut = null;
                    setHint('Select your check-in date', false);
                    load();
                } else {
                    showStatus(res.message, false);
                    if (res.unavailable) {
                        checkIn = checkOut = null;
                        setHint('Select your check-in date', false);
                        load();
                    }
                }
            })
            .catch(function () {
                showStatus('Something went wrong. Please try again or contact us by phone/WhatsApp.', false);
            })
            .finally(function () {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Book Now · Pay by Bank Transfer';
            });
    });

    success.querySelector('[data-action="new-booking"]').addEventListener('click', function () {
        success.hidden = true;
        form.hidden = false;
        status.className = 'form-status';
        status.textContent = '';
        updateSummary();
        document.getElementById('book').scrollIntoView({ behavior: 'smooth' });
    });

    load();
})();
