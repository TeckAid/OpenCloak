/* Cloak admin — modal wizard helpers */
(function () {
    'use strict';

    var modals = document.querySelectorAll('.modal-backdrop');

    modals.forEach(function (backdrop) {
        if (backdrop.getAttribute('data-open') === '1') {
            open(backdrop);
        }
        var closeBtn = backdrop.querySelector('[data-close]');
        if (closeBtn) {
            closeBtn.addEventListener('click', function () { close(backdrop); });
        }
        backdrop.addEventListener('click', function (e) {
            if (e.target === backdrop) close(backdrop);
        });
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            modals.forEach(close);
        }
    });

    function open(backdrop) {
        backdrop.classList.add('is-open');
        document.body.classList.add('modal-open');
    }

    function close(backdrop) {
        backdrop.classList.remove('is-open');
        var anyOpen = document.querySelector('.modal-backdrop.is-open');
        if (!anyOpen) document.body.classList.remove('modal-open');
    }

    // Open buttons: <button data-open-modal="#link-modal">
    document.querySelectorAll('[data-open-modal]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var target = document.querySelector(btn.getAttribute('data-open-modal'));
            if (target) open(target);
        });
    });

    // Wizard steps
    document.querySelectorAll('[data-wizard]').forEach(function (form) {
        var steps = form.querySelectorAll('.wizard-step');
        var dots = form.querySelectorAll('.wizard-dot');
        var nextBtns = form.querySelectorAll('[data-wizard-next]');
        var backBtns = form.querySelectorAll('[data-wizard-back]');
        var submit = form.querySelector('[data-wizard-submit]');

        function show(index) {
            steps.forEach(function (step, i) {
                step.classList.toggle('is-current', i === index);
            });
            dots.forEach(function (dot, i) {
                dot.classList.toggle('is-current', i === index);
                dot.classList.toggle('is-done', i < index);
            });
            nextBtns.forEach(function (b) { b.style.display = (index < steps.length - 1) ? '' : 'none'; });
            if (submit) submit.style.display = (index === steps.length - 1) ? '' : 'none';
            backBtns.forEach(function (b) { b.style.display = index === 0 ? 'none' : ''; });
        }

        nextBtns.forEach(function (b) {
            b.addEventListener('click', function () {
                var current = form.querySelector('.wizard-step.is-current');
                if (!current) return;
                var index = Array.prototype.indexOf.call(steps, current);
                if (index < steps.length - 1) show(index + 1);
            });
        });
        backBtns.forEach(function (b) {
            b.addEventListener('click', function () {
                var current = form.querySelector('.wizard-step.is-current');
                if (!current) return;
                var index = Array.prototype.indexOf.call(steps, current);
                if (index > 0) show(index - 1);
            });
        });

        show(0);
    });

    // Link copy
    document.querySelectorAll('.link-url').forEach(function (el) {
        el.addEventListener('click', function () {
            var url = el.getAttribute('data-copy') || el.textContent;
            navigator.clipboard.writeText(url).then(function () {
                var original = el.textContent;
                el.textContent = 'Copied!';
                setTimeout(function () { el.textContent = original; }, 1500);
            });
        });
    });
})();
