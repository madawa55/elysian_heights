(function () {
    // Mobile menu
    var toggle = document.querySelector('.nav-toggle');
    var links = document.querySelector('.nav-links');
    var siteHeader = document.querySelector('.site-header');
    function setMenu(open) {
        links.classList.toggle('open', open);
        siteHeader.classList.toggle('menu-open', open);
        toggle.setAttribute('aria-expanded', open);
        toggle.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
    }
    toggle.addEventListener('click', function () {
        setMenu(!links.classList.contains('open'));
    });
    links.addEventListener('click', function (e) {
        if (e.target.tagName === 'A') setMenu(false);
    });
    // Close when tapping outside the header or growing past the mobile breakpoint
    document.addEventListener('click', function (e) {
        if (links.classList.contains('open') && !siteHeader.contains(e.target)) setMenu(false);
    });
    window.addEventListener('resize', function () {
        if (window.innerWidth > 860 && links.classList.contains('open')) setMenu(false);
    });

    // Hero slideshow
    var slides = document.querySelectorAll('.hero-slide');
    var dots = document.querySelectorAll('.hero-dots button');
    var current = 0;
    var timer;
    function showSlide(i) {
        slides[current].classList.remove('active');
        dots[current].classList.remove('active');
        current = (i + slides.length) % slides.length;
        slides[current].classList.add('active');
        dots[current].classList.add('active');
    }
    function startSlideshow() {
        clearInterval(timer);
        timer = setInterval(function () { showSlide(current + 1); }, 6000);
    }
    dots.forEach(function (dot, i) {
        dot.addEventListener('click', function () { showSlide(i); startSlideshow(); });
    });
    if (slides.length > 1) startSlideshow();

    // Header background once scrolled past the hero top
    var header = document.querySelector('.site-header');
    function onScroll() {
        header.classList.toggle('scrolled', window.scrollY > 40);
    }
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();

    // Highlight the nav link of the section in view
    var navAnchors = document.querySelectorAll('.nav-links a[href^="#"]');
    var observer = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
            if (!entry.isIntersecting) return;
            navAnchors.forEach(function (a) {
                a.classList.toggle('active', a.getAttribute('href') === '#' + entry.target.id);
            });
        });
    }, { rootMargin: '-45% 0px -50% 0px' });
    document.querySelectorAll('main section[id]').forEach(function (s) { observer.observe(s); });

    // Date inputs: no past dates, check-out after check-in
    var checkIn = document.getElementById('check_in');
    var checkOut = document.getElementById('check_out');
    var today = new Date().toISOString().split('T')[0];
    checkIn.min = today;
    checkOut.min = today;
    checkIn.addEventListener('change', function () {
        checkOut.min = checkIn.value || today;
        if (checkOut.value && checkOut.value <= checkIn.value) checkOut.value = '';
    });

    // AJAX enquiry form
    var form = document.getElementById('enquiry-form');
    var status = form.querySelector('.form-status');
    var button = form.querySelector('button[type="submit"]');

    function showStatus(message, ok) {
        status.textContent = message;
        status.className = 'form-status ' + (ok ? 'success' : 'error');
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();

        var name = form.name.value.trim();
        var email = form.email.value.trim();
        var message = form.message.value.trim();
        if (!name || !message || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            showStatus('Please fill in your name, a valid email and a message.', false);
            return;
        }

        button.disabled = true;
        button.textContent = 'Sending…';
        status.className = 'form-status';
        status.textContent = '';

        fetch(form.action, { method: 'POST', body: new FormData(form) })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                showStatus(data.message, data.ok);
                if (data.ok) form.reset();
            })
            .catch(function () {
                showStatus('Something went wrong. Please try again or contact us by phone/WhatsApp.', false);
            })
            .finally(function () {
                button.disabled = false;
                button.textContent = 'Send Enquiry';
            });
    });
})();
