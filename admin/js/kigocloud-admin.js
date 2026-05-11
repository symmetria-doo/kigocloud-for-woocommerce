/**
 * KigoCloud admin: AJAX tab swap + small UX helpers.
 *
 * - Intercepts clicks on .nav-tab links inside .kigocloud-admin
 * - Fetches the target tab via GET, extracts .kigocloud-tab-content
 *   from the response, swaps it into the page
 * - Uses history.pushState so the URL reflects the active tab and
 *   the browser Back button restores the previous tab via popstate
 * - On fetch error falls back to a full-page navigation
 *
 * No jQuery. No build step. Works on WP 5.5+ since it only uses
 * fetch / DOMParser / classList.
 */
(function () {
    'use strict';

    if (!document.body || !document.body.classList.contains('toplevel_page_kigocloud')) {
        return;
    }

    var root = document.querySelector('.kigocloud-admin');
    if (!root) {
        return;
    }

    var tabsBar = root.querySelector('.nav-tab-wrapper');
    var content = root.querySelector('.kigocloud-tab-content');
    if (!tabsBar || !content) {
        return;
    }

    function setActiveTab(href) {
        var tabs = tabsBar.querySelectorAll('a.nav-tab');
        for (var i = 0; i < tabs.length; i++) {
            tabs[i].classList.toggle('nav-tab-active', tabs[i].getAttribute('href') === href);
        }
    }

    function setBusy(isBusy) {
        content.style.transition = 'opacity 0.15s ease';
        content.style.opacity = isBusy ? '0.35' : '1';
        content.style.pointerEvents = isBusy ? 'none' : '';
    }

    function loadTab(href, push) {
        setBusy(true);
        fetch(href, {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (r) {
                if (!r.ok) { throw new Error('http ' + r.status); }
                return r.text();
            })
            .then(function (html) {
                var doc = new DOMParser().parseFromString(html, 'text/html');
                var fresh = doc.querySelector('.kigocloud-admin .kigocloud-tab-content');
                if (!fresh) { throw new Error('no tab content in response'); }
                content.innerHTML = fresh.innerHTML;
                setActiveTab(href);
                if (push) {
                    window.history.pushState({ kigocloud: true, href: href }, '', href);
                }
                wireUp();
            })
            .catch(function () {
                window.location.href = href;
            })
            .then(function () { setBusy(false); });
    }

    tabsBar.addEventListener('click', function (e) {
        var link = e.target.closest && e.target.closest('a.nav-tab');
        if (!link) { return; }
        e.preventDefault();
        loadTab(link.getAttribute('href'), true);
    });

    window.addEventListener('popstate', function (e) {
        if (e.state && e.state.kigocloud) {
            loadTab(e.state.href, false);
        } else if (location.search.indexOf('page=kigocloud') !== -1) {
            loadTab(location.href, false);
        }
    });

    // Replace initial state so the first Back works as expected.
    if (window.history && window.history.replaceState) {
        window.history.replaceState({ kigocloud: true, href: location.href }, '', location.href);
    }

    /**
     * Test-push button. Re-wired after every AJAX tab swap because the
     * tab content is replaced wholesale.
     */
    function wireUp() {
        var btn = content.querySelector('#kigocloud-test-push');
        if (btn && !btn.dataset.bound) {
            btn.dataset.bound = '1';
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                var resultBox = content.querySelector('#kigocloud-test-push-result');
                var nonce = btn.dataset.nonce || '';
                btn.disabled = true;
                if (resultBox) {
                    resultBox.textContent = btn.dataset.runningLabel || 'Running...';
                    resultBox.className = 'kc-test-result kc-test-running';
                }
                var fd = new FormData();
                fd.append('action', 'kigocloud_test_push');
                fd.append('nonce', nonce);
                fetch(window.ajaxurl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: fd
                })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (!resultBox) { return; }
                        if (data && data.success) {
                            resultBox.textContent = data.data && data.data.message ? data.data.message : 'OK';
                            resultBox.className = 'kc-test-result kc-test-ok';
                        } else {
                            var msg = data && data.data && data.data.message ? data.data.message : 'Test failed.';
                            resultBox.textContent = msg;
                            resultBox.className = 'kc-test-result kc-test-bad';
                        }
                    })
                    .catch(function (err) {
                        if (resultBox) {
                            resultBox.textContent = 'Network error: ' + err.message;
                            resultBox.className = 'kc-test-result kc-test-bad';
                        }
                    })
                    .then(function () { btn.disabled = false; });
            });
        }
    }

    wireUp();
})();
