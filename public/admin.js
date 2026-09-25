/**
 * Admin panel behaviour (dashboard bulk actions, live Markdown preview).
 * An external file rather than inline code, so the Content-Security-Policy
 * can forbid inline scripts. Each part only runs on the page that has it.
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

// Live preview: POSTs markdown to /admin/preview every ~350 ms while typing,
// renders the response into #admin-edit-preview. Uses the same Parsedown
// (safe mode) as the public site, so what the admin sees is exactly what
// the user will see after Save.
(function () {
    const ta = document.getElementById('markdown');
    const out = document.getElementById('admin-edit-preview');
    const form = document.getElementById('admin-edit-form');
    if (!ta || !out || !form) return;
    const csrf = form.querySelector('input[name="csrf"]').value;
    let timer = null;
    let inflight = 0;
    async function render() {
        const md = ta.value;
        const turn = ++inflight;
        try {
            const fd = new FormData();
            fd.append('csrf', csrf);
            fd.append('markdown', md);
            const res = await fetch(form.dataset.preview, { method: 'POST', body: fd });
            if (turn !== inflight) return; // user kept typing
            if (!res.ok) { out.innerHTML = '<p><em>Preview failed: HTTP ' + res.status + '</em></p>'; return; }
            out.innerHTML = await res.text();
        } catch (e) {
            if (turn === inflight) out.innerHTML = '<p><em>Preview unavailable (network).</em></p>';
        }
    }
    ta.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(render, 350); });
    if (ta.value.trim() !== '') render();
})();
