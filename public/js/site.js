/* BtoBcms 前台脚本：UTM 首触归因 + 询盘表单 AJAX + 询盘篮 RFQ */
(function () {
    'use strict';

    /* ---------- 归因：首次访问记录 UTM 与落地页 ---------- */
    try {
        var params = new URLSearchParams(window.location.search);
        var utm = {};
        ['source', 'medium', 'campaign', 'term', 'content'].forEach(function (k) {
            var v = params.get('utm_' + k);
            if (v) utm[k] = v;
        });
        if (Object.keys(utm).length) {
            localStorage.setItem('btob_utm', JSON.stringify(utm));
        }
        if (!localStorage.getItem('btob_landing')) {
            localStorage.setItem('btob_landing', JSON.stringify({
                landing: window.location.href,
                referrer: document.referrer || null
            }));
        }
    } catch (e) { /* localStorage 不可用时静默降级 */ }

    /* ---------- 询盘篮（RFQ） ---------- */
    function getBasket() {
        try {
            var v = JSON.parse(localStorage.getItem('btob_basket') || '[]');
            return Array.isArray(v) ? v : [];
        } catch (e) { return []; }
    }

    function setBasket(ids) {
        try { localStorage.setItem('btob_basket', JSON.stringify(ids)); } catch (e) { /* ignore */ }
        updateBasketUI();
        renderBasketPage();
    }

    function updateBasketUI() {
        var count = getBasket().length;

        var badge = document.getElementById('basket-count');
        if (badge) {
            badge.textContent = count;
            badge.hidden = count === 0;
        }

        var ids = getBasket();
        document.querySelectorAll('[data-add-to-basket]').forEach(function (btn) {
            var id = parseInt(btn.dataset.addToBasket, 10);
            var added = ids.indexOf(id) !== -1;
            btn.classList.toggle('is-added', added);
            btn.textContent = added
                ? (btn.dataset.removeLabel || '✓')
                : (btn.dataset.addLabel || '+');
        });
    }

    function renderBasketPage() {
        var wrap = document.getElementById('basket-items');
        if (!wrap) return;

        var emptyHtml = wrap.dataset.emptyHtml;
        if (!emptyHtml) {
            emptyHtml = wrap.innerHTML;
            wrap.dataset.emptyHtml = emptyHtml;
        }

        var ids = getBasket();
        if (!ids.length) {
            wrap.innerHTML = emptyHtml;
            return;
        }

        fetch('/api/basket/products?ids=' + ids.join(','), {
            headers: { 'Accept': 'application/json' }
        })
            .then(function (res) { return res.json(); })
            .then(function (products) {
                if (!products.length) {
                    wrap.innerHTML = emptyHtml;
                    return;
                }
                wrap.innerHTML = products.map(function (p) {
                    var meta = [p.category, p.price, p.moq ? ('MOQ: ' + p.moq) : null]
                        .filter(Boolean).join(' · ');
                    return '<div class="basket-row" data-basket-row="' + p.id + '">' +
                        '<div class="basket-row__info">' +
                        '<a href="' + p.url + '">' + p.name + '</a>' +
                        (meta ? '<small>' + meta + '</small>' : '') +
                        '</div>' +
                        '<button type="button" class="basket-remove" data-basket-remove="' + p.id + '" aria-label="Remove">✕</button>' +
                        '</div>';
                }).join('');
            })
            .catch(function () { wrap.innerHTML = emptyHtml; });
    }

    document.addEventListener('click', function (e) {
        var addBtn = e.target.closest('[data-add-to-basket]');
        if (addBtn) {
            e.preventDefault();
            var id = parseInt(addBtn.dataset.addToBasket, 10);
            var ids = getBasket();
            var i = ids.indexOf(id);
            if (i === -1) { ids.push(id); } else { ids.splice(i, 1); }
            setBasket(ids);
            return;
        }

        var removeBtn = e.target.closest('[data-basket-remove]');
        if (removeBtn) {
            e.preventDefault();
            var removeId = parseInt(removeBtn.dataset.basketRemove, 10);
            setBasket(getBasket().filter(function (v) { return v !== removeId; }));
        }
    });

    /* ---------- 询盘表单提交 ---------- */
    document.addEventListener('submit', function (e) {
        var form = e.target.closest('[data-inquiry-form]');
        if (!form) return;

        e.preventDefault();

        var button = form.querySelector('button[type="submit"]');
        var successEl = form.querySelector('.form-status--success');
        var errorEl = form.querySelector('.form-status--error');
        var payload = Object.fromEntries(new FormData(form).entries());

        var productIds = Array.from(form.querySelectorAll('input[name="product_ids[]"]'))
            .map(function (i) { return parseInt(i.value, 10); })
            .filter(function (v) { return !isNaN(v); });

        // 询盘篮表单：提交 localStorage 中的全部产品
        if (form.hasAttribute('data-basket-form')) {
            productIds = getBasket();
        }
        if (productIds.length) payload.product_ids = productIds;

        try {
            var utmStored = JSON.parse(localStorage.getItem('btob_utm') || 'null');
            if (utmStored) payload.utm = utmStored;
            var landing = JSON.parse(localStorage.getItem('btob_landing') || 'null');
            if (landing) {
                // 旧版本存的是相对 pathname（历史访客的 localStorage），提交前归一为绝对 URL
                var l = landing.landing || '';
                payload.landing_url = /^[a-z][a-z0-9+.-]*:\/\//i.test(l)
                    ? l
                    : (function () { try { return new URL(l, location.origin).href; } catch (e) { return ''; } })();
                payload.referrer = landing.referrer;
            }
        } catch (err) { /* ignore */ }

        payload.source = form.dataset.source || 'form';

        if (button) { button.disabled = true; button.dataset.label = button.textContent; button.textContent = button.dataset.sending || '…'; }
        if (successEl) successEl.hidden = true;
        if (errorEl) errorEl.hidden = true;

        fetch(form.action, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify(payload)
        })
            .then(function (res) {
                if (!res.ok) throw new Error('HTTP ' + res.status);
                return res.json();
            })
            .then(function () {
                form.reset();
                if (successEl) successEl.hidden = false;
                // 询盘篮提交成功后清空
                if (form.hasAttribute('data-basket-form')) {
                    setBasket([]);
                }
            })
            .catch(function () {
                if (errorEl) errorEl.hidden = false;
            })
            .finally(function () {
                if (button) { button.disabled = false; button.textContent = button.dataset.label || ''; }
            });
    });

    /* ---------- 初始化 ---------- */
    function initPageFx() {
        // 导航滚动阴影
        var navbar = document.querySelector('.navbar');
        var onScroll = function () {
            if (navbar) navbar.classList.toggle('navbar--scrolled', window.scrollY > 8);
        };
        window.addEventListener('scroll', onScroll, { passive: true });
        onScroll();

        // 卡片/区块入场动效（尊重系统减少动效设置）
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
        if (!('IntersectionObserver' in window)) return;

        var targets = document.querySelectorAll(
            '.section-head, .product-card, .post-card, .category-card, .feature, .process-step'
        );
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) return;
                var el = entry.target;
                var siblings = el.parentElement ? el.parentElement.children : [];
                var idx = Array.prototype.indexOf.call(siblings, el);
                el.style.transitionDelay = Math.min(Math.max(idx, 0) * 55, 380) + 'ms';
                el.classList.add('reveal-in');
                io.unobserve(el);
            });
        }, { rootMargin: '0px 0px -7% 0px', threshold: 0.08 });

        Array.prototype.forEach.call(targets, function (el) {
            el.classList.add('reveal');
            io.observe(el);
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        updateBasketUI();
        renderBasketPage();
        initPageFx();
    });
})();
