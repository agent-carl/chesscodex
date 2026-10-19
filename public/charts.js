/**
 * Crosshair and tooltip for the year charts (lib/TrendChart.php). The pointer,
 * or the arrow keys on a focused chart, picks the nearest year; the tooltip
 * reads that year's row of the chart's own table, so it never shows a number
 * the table doesn't.
 */
(function () {
    document.querySelectorAll('.trend').forEach(function (chart) {
        const plot = chart.querySelector('.trend-plot');
        const tip = chart.querySelector('.trend-tip');
        const cross = chart.querySelector('.trend-cross');
        const dot = chart.querySelector('.trend-dot.is-hover');
        const rows = chart.querySelectorAll('tbody tr');
        if (!plot || !tip || !cross || !dot || rows.length < 2) return;
        let current = -1;

        function show(i) {
            current = Math.max(0, Math.min(rows.length - 1, i));
            const row = rows[current];
            const x = parseFloat(row.dataset.x);
            const y = parseFloat(row.dataset.y);
            cross.style.left = dot.style.left = x + '%';
            dot.style.bottom = y + '%';
            cross.hidden = dot.hidden = false;

            const value = document.createElement('strong');
            value.textContent = row.cells[1].textContent;
            const when = document.createElement('span');
            when.textContent = row.cells[0].textContent + ': ' + row.cells[2].textContent + ' games';
            tip.replaceChildren(value, when);
            tip.hidden = false;
            // Over the year, kept inside the chart at both ends.
            const left = plot.offsetLeft + plot.offsetWidth * x / 100;
            const half = tip.offsetWidth / 2;
            const max = chart.querySelector('.trend-frame').clientWidth - tip.offsetWidth;
            tip.style.left = Math.max(0, Math.min(max, left - half)) + 'px';
        }

        function hide() {
            cross.hidden = dot.hidden = tip.hidden = true;
        }

        function nearest(e) {
            const box = plot.getBoundingClientRect();
            return Math.round((e.clientX - box.left) / box.width * (rows.length - 1));
        }

        plot.addEventListener('pointermove', function (e) { show(nearest(e)); });
        plot.addEventListener('pointerdown', function (e) { show(nearest(e)); });
        plot.addEventListener('pointerleave', function (e) { if (e.pointerType === 'mouse') hide(); });
        plot.addEventListener('focus', function () { show(current < 0 ? rows.length - 1 : current); });
        plot.addEventListener('blur', hide);
        plot.addEventListener('keydown', function (e) {
            // The first arrow press (no year shown yet) starts at the last year.
            const step = current < 0 ? 0 : 1;
            const at = current < 0 ? rows.length - 1 : current;
            const to = { ArrowLeft: at - step, ArrowRight: at + step, Home: 0, End: rows.length - 1 }[e.key];
            if (to !== undefined) {
                show(to);
                e.preventDefault();
            } else if (e.key === 'Escape') {
                hide();
            }
        });
    });
})();
