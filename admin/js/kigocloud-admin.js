/**
 * KigoCloud admin: instant tab switching + Test push.
 *
 * All tabs are pre-rendered into the DOM as <div class="kigocloud-tab-pane"
 * data-tab="...">. Tab clicks toggle which pane is visible; no fetching,
 * no page reload. URL is updated via history.pushState so deep linking
 * and Back/Forward keep working.
 *
 * The whole admin page is a single <form> posting to options.php, so
 * the bottom "Save all settings" button persists every option from
 * every tab in one go.
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
    var panes   = root.querySelectorAll('.kigocloud-tab-pane');
    if (!tabsBar || !panes.length) {
        return;
    }

    function show(slug) {
        var found = false;
        for (var i = 0; i < panes.length; i++) {
            var match = panes[i].getAttribute('data-tab') === slug;
            panes[i].style.display = match ? '' : 'none';
            if (match) { found = true; }
        }
        var tabs = tabsBar.querySelectorAll('a.nav-tab');
        for (var j = 0; j < tabs.length; j++) {
            tabs[j].classList.toggle('nav-tab-active', tabs[j].getAttribute('data-tab-target') === slug);
        }
        return found;
    }

    function slugFromHref(href) {
        if (!href) { return null; }
        var m = href.match(/[?&]tab=([a-z0-9_-]+)/i);
        return m ? m[1] : null;
    }

    tabsBar.addEventListener('click', function (e) {
        var link = e.target.closest && e.target.closest('a.nav-tab');
        if (!link) { return; }
        e.preventDefault();
        var slug = link.getAttribute('data-tab-target') || slugFromHref(link.getAttribute('href'));
        if (!slug || !show(slug)) { return; }
        if (window.history && window.history.pushState) {
            window.history.pushState({ kigocloud: true, tab: slug }, '', link.getAttribute('href'));
        }
    });

    window.addEventListener('popstate', function (e) {
        var slug = (e.state && e.state.tab) || slugFromHref(location.search) || 'connection';
        show(slug);
    });

    // Test-push button wiring (the button lives inside the R1 pane which
    // is always present in the DOM, so a single bind is enough).
    var btn = document.getElementById('kigocloud-test-push');
    if (btn) {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            var resultBox = document.getElementById('kigocloud-test-push-result');
            var nonce = btn.dataset.nonce || '';
            btn.disabled = true;
            if (resultBox) {
                resultBox.textContent = btn.dataset.runningLabel || 'Running...';
                resultBox.className = 'kc-test-result kc-test-running';
                resultBox.style.display = '';
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
})();
