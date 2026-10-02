(function () {
    var items = Array.prototype.slice.call(document.querySelectorAll('.gallery-item'));
    var box = document.getElementById('lightbox');
    if (!items.length || !box) return;

    var img = box.querySelector('img');
    var caption = box.querySelector('figcaption');
    var current = 0;
    var lastFocus = null;

    function show(i) {
        current = (i + items.length) % items.length;
        var item = items[current];
        img.src = item.getAttribute('href');
        img.alt = item.querySelector('img').alt;
        caption.textContent = item.dataset.caption || '';
    }
    function open(i) {
        lastFocus = document.activeElement;
        show(i);
        box.hidden = false;
        document.body.classList.add('no-scroll');
        box.querySelector('.lb-close').focus();
    }
    function close() {
        box.hidden = true;
        img.src = '';
        document.body.classList.remove('no-scroll');
        if (lastFocus) lastFocus.focus();
    }

    items.forEach(function (item, i) {
        item.addEventListener('click', function (e) {
            e.preventDefault();
            open(i);
        });
    });
    box.querySelector('.lb-close').addEventListener('click', close);
    box.querySelector('.lb-prev').addEventListener('click', function () { show(current - 1); });
    box.querySelector('.lb-next').addEventListener('click', function () { show(current + 1); });
    box.addEventListener('click', function (e) {
        if (e.target === box) close();
    });
    document.addEventListener('keydown', function (e) {
        if (box.hidden) return;
        if (e.key === 'Escape') close();
        if (e.key === 'ArrowLeft') show(current - 1);
        if (e.key === 'ArrowRight') show(current + 1);
    });

    // Swipe on touch screens
    var startX = null;
    box.addEventListener('touchstart', function (e) { startX = e.touches[0].clientX; }, { passive: true });
    box.addEventListener('touchend', function (e) {
        if (startX === null) return;
        var dx = e.changedTouches[0].clientX - startX;
        if (Math.abs(dx) > 50) show(current + (dx < 0 ? 1 : -1));
        startX = null;
    });
})();
