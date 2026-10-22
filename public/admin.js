/**
 * Admin panel behaviour (dashboard bulk actions).
 * An external file rather than inline code, so the Content-Security-Policy
 * can forbid inline scripts.
 */

// Dashboard bulk-selection wiring: enables Bulk buttons only when something
// is selected, syncs the "select all" checkbox both ways, and guards each
// bulk action with a confirm() — bulk reject + bulk accept are destructive.
(function () {
    const form = document.getElementById('admin-bulk-form');
    if (!form) return;
    const checks = form.querySelectorAll('input.admin-bulk-check');
    const all    = document.getElementById('admin-bulk-checkall');
    const meter  = document.getElementById('admin-bulk-selected');
    const accept = form.querySelector('button[name="bulk_accept"]');
    const reject = form.querySelector('button[name="bulk_reject"]');
    function sync() {
        const n = Array.from(checks).filter((c) => c.checked).length;
        meter.textContent = n + ' selected';
        accept.disabled = n === 0;
        reject.disabled = n === 0;
        if (all) {
            all.checked = n === checks.length && n > 0;
            all.indeterminate = n > 0 && n < checks.length;
        }
    }
    checks.forEach((c) => c.addEventListener('change', sync));
    if (all) all.addEventListener('change', () => {
        checks.forEach((c) => { c.checked = all.checked; });
        sync();
    });
    [accept, reject].forEach((btn) => btn && btn.addEventListener('click', (e) => {
        const msg = btn.dataset.confirm || 'Proceed?';
        if (!confirm(msg)) e.preventDefault();
    }));
    sync();
})();
