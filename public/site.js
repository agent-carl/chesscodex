/**
 * On every page (deferred, after the markup): the name search with
 * suggestions, and the "/" and Ctrl/Cmd+K shortcuts that focus it.
 *
 * A name search is an <input data-name-search="<id of its result list>">
 * (templates/partials/name_search.php). Enter follows the highlighted
 * suggestion; with none highlighted, moves ("1.e4 e5", "e4 c5 Nf3") go to
 * the identifier on /search and a name goes to its first suggestion.
 */
(function () {
    var script = document.currentScript;
    var base = (script && script.dataset.base) || '';
    var apiUrl = base + '/api/search';
    var openingPath = base + '/openings/';
    var searchPath = base + '/search';

    // SAN moves, optionally numbered: the whole query has to be made of them.
    var MOVE = '(?:\\d+\\.+\\s*)?(?:O-O(?:-O)?|[KQRBN]?[a-h]?[1-8]?x?[a-h][1-8](?:=[QRBN])?)[+#]?';
    var MOVES_RE = new RegExp('^\\s*' + MOVE + '(?:\\s+' + MOVE + ')*\\s*$');
    var looksLikeMoves = function (q) { return /\d|^[a-h][1-8]\b/.test(q) && MOVES_RE.test(q); };

    var movesLabel = function (plies) { var n = Math.ceil(plies / 2); return n === 1 ? '1 move' : n + ' moves'; };

    function setUp(input) {
        var list = document.getElementById(input.dataset.nameSearch);
        if (!list) return;
        var timer = null;
        var ctrl = null;
        var cursor = -1;
        var lastQuery = '';

        function close() {
            list.hidden = true;
            input.setAttribute('aria-expanded', 'false');
        }
        function clear() {
            list.innerHTML = '';
            cursor = -1;
            close();
        }
        function open() {
            if (!list.children.length) return;
            list.hidden = false;
            input.setAttribute('aria-expanded', 'true');
        }

        function render(matches, q) {
            list.innerHTML = '';
            cursor = -1;
            if (looksLikeMoves(q)) {
                var li = document.createElement('li');
                li.setAttribute('role', 'option');
                var a = document.createElement('a');
                a.href = searchPath + '?moves=' + encodeURIComponent(q);
                a.className = 'search-by-name-moves';
                a.textContent = 'Identify the opening after ' + q + ' →';
                li.appendChild(a);
                list.appendChild(li);
            }
            matches.forEach(function (m) {
                var li = document.createElement('li');
                li.setAttribute('role', 'option');
                var a = document.createElement('a');
                a.href = openingPath + encodeURIComponent(m.slug);
                var tag = document.createElement('span');
                tag.className = 'eco-tag';
                tag.textContent = m.eco;
                var name = document.createElement('span');
                name.className = 'search-by-name-result-name';
                name.textContent = m.name;
                var plies = document.createElement('span');
                plies.className = 'search-by-name-result-plies';
                plies.textContent = movesLabel(m.plies);
                a.appendChild(tag);
                a.appendChild(name);
                a.appendChild(plies);
                li.appendChild(a);
                list.appendChild(li);
            });
            if (!list.children.length) {
                var empty = document.createElement('li');
                empty.className = 'search-by-name-empty';
                empty.textContent = 'No openings match that name. Try fewer words, or paste the moves.';
                list.appendChild(empty);
            }
            open();
        }

        function lookup(q) {
            if (ctrl) ctrl.abort();
            ctrl = typeof AbortController === 'function' ? new AbortController() : null;
            lastQuery = q;
            fetch(apiUrl + '?name=' + encodeURIComponent(q), {
                headers: { Accept: 'application/json' },
                signal: ctrl ? ctrl.signal : undefined,
            }).then(function (res) {
                return res.ok ? res.json() : { matches: [] };
            }).then(function (data) {
                if (q === lastQuery) render(data.matches || [], q);
            }).catch(function (e) {
                if (e.name !== 'AbortError') clear();
            });
        }

        function move(delta) {
            var items = list.querySelectorAll('li[role="option"]');
            if (!items.length) return;
            cursor = (cursor + delta + items.length) % items.length;
            items.forEach(function (li, i) {
                li.classList.toggle('is-active', i === cursor);
                li.setAttribute('aria-selected', i === cursor ? 'true' : 'false');
            });
            items[cursor].scrollIntoView({ block: 'nearest' });
        }

        input.addEventListener('input', function () {
            var q = input.value.trim();
            clearTimeout(timer);
            if (q.length < 2) { clear(); return; }
            timer = setTimeout(function () { lookup(q); }, 150);
        });
        input.addEventListener('focus', open);
        document.addEventListener('click', function (e) {
            if (!list.contains(e.target) && e.target !== input) close();
        });
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { close(); return; }
            if (e.key === 'ArrowDown') { e.preventDefault(); open(); move(1); return; }
            if (e.key === 'ArrowUp') { e.preventDefault(); move(-1); return; }
            if (e.key !== 'Enter') return;
            var q = input.value.trim();
            if (q === '') return;
            var target = list.querySelector('li.is-active a');
            if (!target && looksLikeMoves(q)) {
                e.preventDefault();
                location.href = searchPath + '?moves=' + encodeURIComponent(q);
                return;
            }
            if (!target && q === lastQuery) target = list.querySelector('li[role="option"] a');
            if (target) {
                e.preventDefault();
                location.href = target.href;
            } else if (!input.form) {
                // No suggestions yet: look them up now.
                e.preventDefault();
                lookup(q);
            }
        });

        // /search?q=… (the home page form without JavaScript, or a link).
        var preset = new URLSearchParams(location.search).get('q');
        if (preset && !input.form) {
            input.value = preset;
            lookup(preset.trim());
        }
    }

    var inputs = document.querySelectorAll('input[data-name-search]');
    inputs.forEach(setUp);

    // "/" or Ctrl/Cmd+K: focus the page's search field, or open /search.
    // "/" is ignored while typing in a field; Ctrl/Cmd+K always works.
    function focusSearch() {
        var input = inputs[0];
        if (input) {
            input.focus();
            input.select();
        } else {
            location.href = searchPath + '#search';
        }
    }
    document.addEventListener('keydown', function (e) {
        var t = e.target;
        var typing = t && t.matches && t.matches('input, textarea, select, [contenteditable]');
        if ((e.metaKey || e.ctrlKey) && (e.key === 'k' || e.key === 'K')) {
            e.preventDefault();
            focusSearch();
        } else if (!typing && e.key === '/' && !e.metaKey && !e.ctrlKey && !e.altKey) {
            e.preventDefault();
            focusSearch();
        }
    });
    if (location.hash === '#search' && inputs[0]) inputs[0].focus();
})();
