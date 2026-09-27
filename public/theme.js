/**
 * Colour theme + service worker. Loaded in <head> without defer, so the saved
 * theme is applied before the first paint (no flash of the wrong colours).
 * An external file rather than inline code, so the Content-Security-Policy
 * can forbid inline scripts.
 */
(function () {
    var root = document.documentElement;
    var saved = null;
    try { saved = localStorage.getItem('codex-theme'); } catch (e) {}
    var systemDark = matchMedia('(prefers-color-scheme: dark)');
    var system = function () { return systemDark.matches ? 'dark' : 'light'; };
    root.dataset.theme = saved || system();
    // Following the system (nothing saved): switch along with it.
    systemDark.addEventListener('change', function () {
        var now = null;
        try { now = localStorage.getItem('codex-theme'); } catch (e) {}
        if (!now) root.dataset.theme = system();
    });

    var base = (document.currentScript && document.currentScript.dataset.base) || '';

    document.addEventListener('DOMContentLoaded', function () {
        var btn = document.getElementById('theme-toggle');
        if (!btn) return;
        function syncPressed() {
            var dark = root.dataset.theme === 'dark';
            btn.setAttribute('aria-pressed', dark ? 'true' : 'false');
            btn.setAttribute('aria-label', dark ? btn.dataset.labelLight : btn.dataset.labelDark);
        }
        syncPressed();
        btn.addEventListener('click', function () {
            var next = root.dataset.theme === 'dark' ? 'light' : 'dark';
            root.dataset.theme = next;
            // Picking the system's own theme means "follow the system" again,
            // so a later change of the system setting is followed.
            try {
                if (next === system()) localStorage.removeItem('codex-theme');
                else localStorage.setItem('codex-theme', next);
            } catch (e) {}
            syncPressed();
        });
    });

    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
            // Scope "/" is allowed by the Service-Worker-Allowed header nginx sends.
            navigator.serviceWorker.register(base + '/public/sw.js', { scope: base + '/' }).catch(function () {});
        });
    }
})();
