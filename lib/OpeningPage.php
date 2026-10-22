<?php
declare(strict_types=1);

/**
 * What an opening page shows, worked out from the rows Routes::opening()
 * fetches: the title and meta description, the overview, the statistics, the
 * breadcrumb, the answers, the popularity by year and the JSON-LD.
 * templates/opening.php prints them; its partials get them by the same names.
 */
class OpeningPage
{
    /**
     * The variables templates/opening.php, its partials and the layout use
     * beyond what Routes::opening() passes, by name.
     *
     * @param array<string, mixed>       $o          the line (Opening::findBySlug)
     * @param array<string, mixed>|null  $parent
     * @param list<array<string, mixed>> $children
     * @param list<array<string, mixed>> $ancestors  root first
     * @return array<string, mixed>
     */
    public static function build(array $o, ?array $parent, array $children, array $ancestors, int $descendantCount, string $baseUrl, string $siteUrl): array
    {
        // Lines that share a name get their final moves appended, so each page has
        // its own <title> instead of up to 14 identical ones — all but the name's
        // main line, which keeps the plain name (Opening::distinguishingTail).
        $lineTail = Opening::distinguishingTail($o);
        $title    = self::title($o, $lineTail);
        // Other names it goes by (Opening::aliases), under the heading and in the JSON-LD.
        $aliases  = Opening::aliases((string) $o['name']);

        $ecoGroupLabel = t('group.' . substr((string) $o['eco'], 0, 1));
        $plies         = (int) $o['move_count'];
        $movesPretty   = trim((string) $o['pgn_moves']);
        $diagramUrl    = $baseUrl . Opening::diagramPath((string) $o['slug'], true);   // WebP, as shown on the page
        // "5…a6": the move that reaches the position.
        $sans          = preg_split('/\s+/', trim((string) preg_replace('/\d+\.+\s*/', ' ', $movesPretty))) ?: [];
        $lastMoveText  = $plies > 0 && $sans ? (intdiv($plies - 1, 2) + 1) . (($plies - 1) % 2 === 0 ? '. ' : '…') . end($sans) : '';

        // Lichess numbers from the server-side cache, printed into the HTML: they
        // show at once, and search engines see them at all (/api/ is closed to
        // crawlers). app.js fetches only when nothing is cached yet or the
        // numbers are over a week old — same markup as its renderStats().
        $stats      = StatsCache::cached(Opening::uci($o));
        $statsTotal = $stats ? $stats['white'] + $stats['black'] + $stats['draws'] : 0;
        $pct        = static fn (int $n, int $of): string => number_format($of > 0 ? $n * 100 / $of : 0, 1, '.', '');
        $barLabel   = static fn (string $w, string $d, string $b): string => "White $w%, Draw $d%, Black $b%";
        [$wPct, $dPct, $bPct] = [$pct($stats['white'] ?? 0, $statsTotal), $pct($stats['draws'] ?? 0, $statsTotal), $pct($stats['black'] ?? 0, $statsTotal)];
        $statsRows  = [];
        foreach ($stats['top_moves'] ?? [] as $m) {
            $mt = (int) $m['white'] + (int) $m['black'] + (int) $m['draws'];
            if ($mt > 0) $statsRows[] = [$m['san'], $mt, $pct((int) $m['white'], $mt), $pct((int) $m['draws'], $mt), $pct((int) $m['black'], $mt)];
        }

        // Moves from here that reach a named line — the variations one move on, and
        // the lines a move transposes into — for the statistics table: SAN => link.
        $trans     = Opening::transpositions((int) $o['id']);
        $nextLines = [];
        foreach ($children as $c) {
            if ((int) $c['move_count'] !== $plies + 1) continue;
            $short = self::shortName((string) $c['name'], (string) $o['name']);
            // A line under this very name but another ECO code is told apart by the code.
            if ($short === (string) $o['name']) $short = (string) $c['eco'] !== (string) $o['eco'] ? (string) $c['eco'] : 'same name';
            $nextLines[self::lastSan((string) $c['pgn_moves'])] ??= [
                'name' => $short,
                'url'  => $baseUrl . '/openings/' . $c['slug'],
            ];
        }
        foreach ($trans['to'] as [$t, $san]) {
            $nextLines[(string) $san] ??= ['name' => (string) $t['name'], 'url' => $baseUrl . '/openings/' . $t['slug']];
        }
        foreach ($nextLines as $san => $line) {
            if ($line['name'] === 'same name') unset($nextLines[$san]);
        }

        // Breadcrumb. Lichess files one name at several depths ("Sicilian
        // Defense" is 1…c5, 2. Nf3, 2…d6 and more), so each name appears once, as
        // its shortest line, and loses the part the name before it already said:
        // "Sicilian Defense › Modern Variations › Main Line › Najdorf Variation".
        $crumbs    = [];
        $crumbSeen = [];
        $prevName  = null;
        foreach ($ancestors as $a) {
            $name = (string) $a['name'];
            if (isset($crumbSeen[$name])) continue;
            $crumbSeen[$name] = true;
            $crumbs[] = [$a, self::crumb($name, $prevName)];
            $prevName = $name;
        }
        $currentCrumb = self::crumb((string) $o['name'], $prevName);
        if (isset($crumbSeen[(string) $o['name']]) && $parent) {
            // Same name as a line above it: the moves tell it apart.
            $currentCrumb .= ' (' . Opening::movesFrom((string) $o['pgn_moves'], (int) $parent['move_count']) . ')';
        }

        $lead = !empty($o['description']) ? Opening::leadSentence((string) $o['description']) : null;
        [$renderedDesc, $toc] = !empty($o['description']) ? self::descriptionHtml((string) $o['description']) : ['', []];

        // How the side to move answers the line, from the same Lichess numbers:
        // the reply that scores best, the most played one and the lowest of the
        // main ones (Replies), headed the way people search: "How to play against
        // the London System". Where that would be wrong — a line that shares its
        // name, or one whose name is the side to move's own (King's Indian
        // Defense, after 3. Nc3) — it names the last move instead.
        $eval         = EngineEval::forFen((string) ($o['fen'] ?? ''));
        $whiteToMove  = $plies % 2 === 0;
        $mainReplies  = $statsTotal > 0 ? Replies::main($stats['top_moves'], $statsTotal, $whiteToMove) : [];
        $answersTitle = $answersHtml = null;
        if ($mainReplies) {
            $answersTitle = $lineTail === '' && Opening::nameSide((string) $o['name'], $plies) !== ($whiteToMove ? 'white' : 'black')
                ? 'How to play against ' . Opening::article((string) $o['name']) . $o['name']
                : 'How to answer ' . $lastMoveText;
            $lineOf = static fn (string $san): ?array => isset($nextLines[$san]) && !preg_match('/^[A-E]\d\d$/', $nextLines[$san]['name'])
                ? [$nextLines[$san]['name'], $nextLines[$san]['url']]
                : null;
            $answersHtml = Replies::html($mainReplies, $whiteToMove, (intdiv($plies, 2) + 1) . ($whiteToMove ? '. ' : '…'), $lineOf,
                !empty($eval['pv']) ? (string) $eval['pv'][0] : null);
        }

        // How often the line is played year by year: its share of the games, from
        // the explorer's history (YearStats, fetched for the most-played lines).
        // A line that shares its name is "this line", as in the answers above.
        $years = YearStats::forOpening((int) $o['id']);
        $yearsSentence = $years ? YearStats::sentence($years,
            $lineTail === '' ? ucfirst(Opening::article((string) $o['name']) . $o['name']) : 'This line') : null;
        $yearsLast = $yearsChart = null;
        $yearsUntil = '';   // the last month of a year not yet over
        if ($yearsSentence !== null) {
            $yearsLast  = max(array_keys($years));
            $yearsChart = TrendChart::years($years, 'Line chart: share of games by year, ' . YearStats::FIRST_YEAR . ' to ' . $yearsLast . '. The numbers are in the table below.');
            if ($years[$yearsLast]['months'] < 12) $yearsUntil = date('F', (int) mktime(0, 0, 0, $years[$yearsLast]['months'], 1));
        }

        $canonical   = $siteUrl . $baseUrl . '/openings/' . $o['slug'];
        $description = self::metaDescription($o, $lineTail, $lead, $children, $stats, $statsTotal, [$wPct, $dPct, $bPct], $eval);

        $island = [
            'id'          => (int) $o['id'],
            'pgn'         => $o['pgn_moves'],
            'name'        => $o['name'],
            'statsApiUrl' => $baseUrl . '/api/stats',
            'searchApiUrl' => $baseUrl . '/api/search',
            'openingPathFmt' => $baseUrl . '/openings/{slug}',
            'plies'       => $plies,
            'nextLines'   => (object) $nextLines,
            'i18n'        => [
                'loading'     => t('opening.stats.loading'),
                'no_games'    => t('opening.stats.no_games'),
                'failed'      => t('opening.stats.failed'),
                'attribution' => t('opening.stats.attribution'),
                'show_more'   => t('opening.variations.show', ['count' => count($children)]),
                'show_less'   => t('opening.variations.hide'),
            ],
        ];

        return [
            'title'              => $title,
            'lineTail'           => $lineTail,
            'aliases'            => $aliases,
            'lead'               => $lead,
            'crumbs'             => $crumbs,
            'currentCrumb'       => $currentCrumb,
            'plies'              => $plies,
            'diagramUrl'         => $diagramUrl,
            'lastMoveText'       => $lastMoveText,
            // The moves in pairs, for the move list app.js builds (and rebuilds).
            'movePairs'          => array_chunk(array_values(array_filter($sans, static fn (string $s): bool => $s !== '')), 2),
            // Share and export — no third-party JS, no tracking.
            'shareUrl'           => $canonical,
            'shareText'          => $o['name'] . ' (' . $o['eco'] . ') — Caissa Codex',
            // The side that makes the line's last move is the one choosing it.
            'ownSide'            => $plies % 2 === 1 ? 'white' : 'black',
            'stats'              => $stats,
            'statsTotal'         => $statsTotal,
            'statsRows'          => $statsRows,
            'wPct'               => $wPct,
            'dPct'               => $dPct,
            'bPct'               => $bPct,
            'pct'                => $pct,
            'barLabel'           => $barLabel,
            'trans'              => $trans,
            'nextLines'          => $nextLines,
            'overviewHtml'       => self::overviewHtml($o, $parent, $children, $descendantCount, $baseUrl, $ecoGroupLabel, $stats, $statsTotal, [$wPct, $dPct, $bPct]),
            'renderedDesc'       => $renderedDesc,
            'toc'                => $toc,
            'answersTitle'       => $answersTitle,
            'answersHtml'        => $answersHtml,
            'whiteToMove'        => $whiteToMove,
            'yearsSentence'      => $yearsSentence,
            'yearsChart'         => $yearsChart,
            'yearsLast'          => $yearsLast,
            'yearsUntil'         => $yearsUntil,
            'opening_short_name' => self::shortName(...),
            'opening_crumb'      => self::crumb(...),
            'island'             => $island,
            'canonical'          => $canonical,
            'noindex'            => !Opening::indexedLine(!empty($o['description']), Opening::lineRank($o), (string) $o['slug']),
            'description'        => $description,
            'jsonLd'             => self::jsonLd($o, $parent, $title, $aliases, $description, $canonical, $siteUrl . $diagramUrl, $ecoGroupLabel, $baseUrl, $siteUrl, $statsTotal > 0 && !empty($stats['cached_at']) ? (string) $stats['cached_at'] : null),
        ];
    }

    /**
     * Lichess names nested variations like "<Parent>: <Suffix>" or
     * "<Parent>, <Suffix>". For breadcrumbs / subtree lists we only want to show
     * the suffix — repeating the parent's name on every level is unreadable.
     *
     * Falls back to the full name when there's no obvious prefix to strip
     * (defensive: the data isn't perfectly consistent).
     */
    public static function shortName(string $name, ?string $parentName): string
    {
        if ($parentName === null || $parentName === '') return $name;
        foreach ([': ', ', '] as $sep) {
            $prefix = $parentName . $sep;
            if (strncmp($name, $prefix, strlen($prefix)) === 0) {
                return substr($name, strlen($prefix));
            }
        }
        return $name;
    }

    /**
     * A breadcrumb name, after the one before it: the part that name already
     * said goes ("…: Modern Variations" → "Modern Variations", "…, Main Line" →
     * "Main Line"), and so does the opening's own name when both belong to it
     * ("Sicilian Defense: Najdorf Variation" after "Sicilian Defense: Modern
     * Variations, Main Line" → "Najdorf Variation").
     */
    public static function crumb(string $name, ?string $prevName): string
    {
        $short = self::shortName($name, $prevName);
        if ($short !== $name || $prevName === null) return $short;
        $family = Opening::family($name);
        if (str_starts_with($name, $family . ': ') && $family === Opening::family($prevName)) {
            return substr($name, strlen($family) + 2);
        }
        return $name;
    }

    /** The last move of a PGN, without its number: "1. e4 c5 2. Nf3" → "Nf3". */
    private static function lastSan(string $pgn): string
    {
        $tokens = preg_split('/\s+/', trim($pgn)) ?: [];
        return (string) preg_replace('/^\d+\.+/', '', (string) end($tokens));
    }

    /**
     * Titles also say what the page is and offers, in the words people search
     * with, as far as 60 characters allow: the first of these that fits.
     * "Italian Game (C50) – Chess Opening Moves & Win Rates", "Queen's Gambit
     * Declined (D30): Chess Moves & Win Rates", "Sicilian Defense: Najdorf
     * Variation (B90): Moves & Win Rates".
     * An alias with words the name lacks goes into the title, where it fits:
     * "Ruy Lopez (C60) – Spanish Opening: Moves & Win Rates" also answers
     * "spanish opening".
     * The main line of a shared name shows its moves first while they are
     * short, as people search them ("1 e4 d6" finds the Pirc): "Pirc Defense
     * (B00): 1. e4 d6 – Ufimtsev Defense", "French Defense (C00): 1. e4 e6 –
     * Chess Moves & Win Rates".
     *
     * @param array<string, mixed> $o
     */
    private static function title(array $o, string $lineTail): string
    {
        $title = $o['name'] . ' (' . $o['eco'] . ')' . ($lineTail !== '' ? ' – ' . $lineTail : '');
        if ($lineTail === '') {
            $titleAlias = Opening::titleAlias((string) $o['name']);
            $choices    = [];
            if ((int) $o['move_count'] <= 4 && Opening::nameCount((string) $o['name']) > 1) {
                $withMoves = $title . ': ' . trim((string) $o['pgn_moves']);
                if ($titleAlias !== null) $choices[] = $withMoves . ' – ' . $titleAlias;
                $choices[] = $withMoves . ' – Chess Moves & Win Rates';
                $choices[] = $withMoves . ' – Moves & Win Rates';
            }
            if ($titleAlias !== null) {
                $choices[] = $title . ' – ' . $titleAlias . ': Moves & Win Rates';
                $choices[] = $title . ' – ' . $titleAlias;
            }
            if (stripos((string) $o['name'], 'Opening') === false) $choices[] = $title . ' – Chess Opening Moves & Win Rates';
            $choices[] = $title . ': Chess Moves & Win Rates';
            $choices[] = $title . ': Moves & Win Rates';
            foreach ($choices as $choice) {
                if (mb_strlen($choice) <= 60) { $title = $choice; break; }
            }
        }
        // Search results cut a title near 60 characters: a long name keeps its words
        // and loses the ECO code (still in the heading and the description).
        if (mb_strlen($title) > 70) $title = str_replace(' (' . $o['eco'] . ')', '', $title);
        return $title;
    }

    /**
     * The overview: what the line is, how it scores on Lichess and where it
     * leads — facts from the data only, no filler. Shown only when there is
     * no written description, so human prose isn't preceded by a summary.
     *
     * @param array<string, mixed>       $o
     * @param array<string, mixed>|null  $parent
     * @param list<array<string, mixed>> $children
     * @param array{white: int, black: int, draws: int, top_moves: list<array<string, mixed>>, cached_at: mixed, fresh: bool}|null $stats
     * @param array{0: string, 1: string, 2: string} $pcts  White, draw, Black
     */
    private static function overviewHtml(array $o, ?array $parent, array $children, int $descendantCount, string $baseUrl,
        string $ecoGroupLabel, ?array $stats, int $statsTotal, array $pcts): string
    {
        [$wPct, $dPct, $bPct] = $pcts;
        $plies      = (int) $o['move_count'];
        $childCount = count($children);
        $nameEsc    = htmlspecialchars((string) $o['name'], ENT_QUOTES, 'UTF-8');
        $ecoEsc     = htmlspecialchars((string) $o['eco'], ENT_QUOTES, 'UTF-8');
        $movesEsc   = '<code class="opening-overview-moves">' . htmlspecialchars(trim((string) $o['pgn_moves']), ENT_QUOTES, 'UTF-8') . '</code>';
        $isGambit   = Opening::isGambit((string) $o['name']);
        // "Queen's Gambit Declined: …" and the like: named after a gambit that isn't taken.
        $isDeclined = !$isGambit && stripos((string) $o['name'], 'Gambit') !== false;

        $h    = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $link = static fn (array $r, ?string $text = null): string =>
            '<a href="' . $h($baseUrl . '/openings/' . $r['slug']) . '">' . $h($text ?? $r['name']) . '</a>';
        $kind = $isGambit ? 'a gambit' : ($isDeclined ? 'a declined gambit' : null);
        $overview = [$parent
            ? sprintf('<strong>%s</strong> (ECO %s, %s) is %s %s, reached after %s (%s).', $nameEsc, $ecoEsc, $h($ecoGroupLabel),
                $kind ?? 'a variation', ($kind ? 'in ' : 'of ') . Opening::article((string) $parent['name']) . $link($parent),
                $movesEsc, Opening::movesLabel($plies))
            : sprintf('<strong>%s</strong> (ECO %s, %s) is %s that starts %s.', $nameEsc, $ecoEsc, $h($ecoGroupLabel), $kind ?? 'a chess opening', $movesEsc)];
        if ($statsTotal > 0) {
            $sentence = sprintf('In %s rated Lichess games between players rated 1600 to 2500, White won %s%%, Black %s%% and %s%% were drawn.',
                number_format($statsTotal), $wPct, $bPct, $dPct);
            $top      = $stats['top_moves'][0] ?? null;
            $topGames = $top ? (int) $top['white'] + (int) $top['black'] + (int) $top['draws'] : 0;
            if ($topGames > 0) {
                $whiteNext = $plies % 2 === 0;
                $sentence .= sprintf(' %s most played move here is <strong>%s</strong>, in %d%% of those games.',
                    $whiteNext ? "White's" : "Black's",
                    $h((intdiv($plies, 2) + 1) . ($whiteNext ? '. ' : '…') . $top['san']),
                    (int) round($topGames * 100 / $statsTotal));
            }
            if ($statsTotal < Opening::FEW_GAMES) $sentence .= ' That is too few games for the percentages to mean much.';
            $overview[] = $sentence;
        }
        if ($childCount > 0) {
            // The most played, by full name; deeper lines that keep this very name say nothing new.
            $byGames = array_filter($children, static fn (array $c): bool => $c['name'] !== $o['name']);
            usort($byGames, static fn (array $a, array $b): int => (int) $b['popularity'] <=> (int) $a['popularity']);
            $named = array_map($link, array_slice($byGames, 0, 2));
            $sentence = match (true) {
                $childCount === 1 => 'It continues into one named variation' . ($named ? ', ' . $named[0] : '') . '.',
                $named === []     => sprintf('It branches into %d named variations.', $childCount),
                default           => sprintf('It branches into %d named variations; the most played %s %s.', $childCount,
                                         count($named) === 1 ? 'is' : 'are', implode(' and ', $named)),
            };
            if ($descendantCount > $childCount) $sentence .= sprintf(' In all, %d named lines continue from it.', $descendantCount);
            $overview[] = $sentence;
        }
        return '<p>' . implode('</p><p>', $overview) . '</p>';
    }

    /**
     * The description as HTML (Parsedown, safe mode) and its table of contents.
     * Auto-TOC: extract <h2>/<h3> from the rendered description, add anchor
     * IDs, so long pieces of theory (1000+ words) become scannable; the page
     * shows the TOC from 3 headings on — a TOC for one entry is overkill.
     *
     * @return array{0: string, 1: list<array{level: string, text: string, slug: string}>}
     */
    private static function descriptionHtml(string $markdown): array
    {
        $pd = new Parsedown();
        $pd->setSafeMode(true);
        $renderedDesc = $pd->text($markdown);

        $toc       = [];
        $usedSlugs = [];
        $renderedDesc = preg_replace_callback(
            '#<(h[23])>(.*?)</\1>#i',
            static function ($m) use (&$toc, &$usedSlugs) {
                $level = strtolower($m[1]);
                $text  = trim(strip_tags($m[2]));
                if ($text === '') return $m[0];
                $slug  = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $text));
                $slug  = trim($slug, '-');
                if ($slug === '') $slug = 'section';
                $base  = $slug; $n = 1;
                while (isset($usedSlugs[$slug])) $slug = $base . '-' . (++$n);
                $usedSlugs[$slug] = true;
                $toc[] = ['level' => $level, 'text' => $text, 'slug' => $slug];
                return '<' . $level . ' id="' . htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') . '">' . $m[2] . '</' . $level . '>';
            },
            $renderedDesc
        );
        return [(string) $renderedDesc, $toc];
    }

    /**
     * SEO description, in the order searchers scan it: name and ECO code, how
     * the line scores on Lichess (the one thing the title can't say), Stockfish's
     * evaluation, its first moves, the variations. Whole parts only, up to 160 characters, so search
     * results never show it cut mid-phrase.
     *
     * @param array<string, mixed>       $o
     * @param list<array<string, mixed>> $children
     * @param array{white: int, black: int, draws: int, top_moves: list<array<string, mixed>>, cached_at: mixed, fresh: bool}|null $stats
     * @param array{0: string, 1: string, 2: string} $pcts  White, draw, Black
     * @param array{depth: int, cp: int|null, mate: int|null, pv: list<string>, engine: string, evaluated_at: string}|null $descEval
     */
    private static function metaDescription(array $o, string $lineTail, ?string $lead, array $children, ?array $stats, int $statsTotal,
        array $pcts, ?array $descEval): string
    {
        [$wPct, $dPct, $bPct] = $pcts;
        $childCount = count($children);
        // The first moves, cut after a whole move ("1. e4 e5 2. Nf3 Nc6…").
        $moveSnippet = trim((string) $o['pgn_moves']);
        if (mb_strlen($moveSnippet) > 24) {
            $cut = mb_substr($moveSnippet, 0, 25);
            $cut = mb_substr($cut, 0, (int) mb_strrpos($cut, ' '));
            $moveSnippet = preg_replace('/\s*\d+\.+$/', '', $cut) . '…';   // no dangling "3."
        }
        $descParts = [$o['name'] . ' (' . $o['eco'] . ')'];
        if ($statsTotal > 0) {
            $descParts[] = sprintf('White wins %d%%, Black %d%%, draws %d%% in %s Lichess games',
                (int) round((float) $wPct), (int) round((float) $bPct), (int) round((float) $dPct),
                Rankings::compact($statsTotal));
        }
        // Stockfish's verdict on the final position: "Stockfish +0.34".
        if ($descEval) {
            $descParts[] = 'Stockfish ' . EngineEval::scoreText($descEval);
        }
        // Same-name lines share their first moves; their ending is what differs.
        $descParts[] = $lineTail !== '' ? 'line ending ' . $lineTail : $moveSnippet;
        if ($childCount > 0) {
            $descParts[] = $childCount . ($childCount === 1 ? ' variation' : ' variations');
        }
        $descParts[] = 'Interactive board' . ($statsTotal > 0 ? '' : ', Lichess statistics') . ', play vs Stockfish.';
        $description = '';
        foreach ($descParts as $i => $part) {
            $next = $description === '' ? $part : $description . ' · ' . $part;
            if (mb_strlen($next) > 160 && $i > 0) continue;   // skip a part that doesn't fit, try the shorter ones after it
            $description = $next;
        }
        // A described line leads with its description's first sentence, which says
        // what the line is, then as much of the numbers as fits.
        if ($lead !== null && mb_strlen($lead) <= 160) {
            $results = sprintf('White wins %d%%, Black %d%%', (int) round((float) $wPct), (int) round((float) $bPct));
            $tries = $statsTotal > 0 ? [
                sprintf('%s, draws %d%% in %s Lichess games', $results, (int) round((float) $dPct), Rankings::compact($statsTotal))
                    . ($descEval ? '; Stockfish ' . EngineEval::scoreText($descEval) : '') . '.',
                sprintf('%s, draws %d%% in %s Lichess games.', $results, (int) round((float) $dPct), Rankings::compact($statsTotal)),
                $results . ' on Lichess.',
            ] : [];
            $description = $lead;
            foreach ($tries as $try) {
                if (mb_strlen($lead . ' ' . $try) <= 160) { $description = $lead . ' ' . $try; break; }
            }
        }
        return $description;
    }

    /**
     * The page's JSON-LD: the Article and its BreadcrumbList.
     *
     * @param array<string, mixed>      $o
     * @param array<string, mixed>|null $parent
     * @param list<string>              $aliases
     * @param string|null               $cachedAt  when the Lichess numbers on the page were fetched
     * @return array<string, mixed>
     */
    private static function jsonLd(array $o, ?array $parent, string $title, array $aliases, string $description, string $canonical,
        string $diagramUrl, string $ecoGroupLabel, string $baseUrl, string $siteUrl, ?string $cachedAt): array
    {
        // OG image absolute URL for the JSON-LD `image`: the same URL as og:image in
        // layout.php, including its ?v=.
        $ogImageUrl = $siteUrl . $baseUrl . '/og.php?slug=' . urlencode($o['slug']) . '&v=' . Opening::OG_VERSION;

        $breadcrumbs = [
            ['name' => t('site.name'), 'url' => $siteUrl . $baseUrl . '/'],
            ['name' => 'ECO ' . $o['eco'], 'url' => $siteUrl . $baseUrl . '/eco/' . $o['eco']],
        ];
        if ($parent) {
            $breadcrumbs[] = ['name' => $parent['name'], 'url' => $siteUrl . $baseUrl . '/openings/' . $parent['slug']];
        }
        $breadcrumbs[] = ['name' => $o['name'], 'url' => $canonical];

        $siteRootUrl  = $siteUrl . $baseUrl . '/';
        $publisherLd  = [
            '@type' => 'Organization',
            'name'  => t('site.name'),
            'url'   => $siteRootUrl,
            'logo'  => [
                '@type' => 'ImageObject',
                'url'   => $siteUrl . $baseUrl . '/public/icon-512.png',
            ],
        ];

        return [
            '@context' => 'https://schema.org',
            '@graph'   => [
                [
                    '@type'            => 'Article',
                    'headline'         => $title,
                    'name'             => $o['name'],
                ] + ($aliases ? ['alternateName' => $aliases] : []) + [
                    'description'      => $description,
                    'url'              => $canonical,
                    'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $canonical],
                    'image'            => [
                        // The square diagram too: Google prefers several aspect ratios.
                        ['@type' => 'ImageObject', 'url' => $diagramUrl, 'width' => 720, 'height' => 720],
                        ['@type' => 'ImageObject', 'url' => $ogImageUrl, 'width' => 1200, 'height' => 630],
                    ],
                    'inLanguage'    => 'en',
                    'articleSection'=> $ecoGroupLabel,
                    'keywords'      => implode(', ', array_filter([
                        $o['name'],
                        $o['eco'],
                        $ecoGroupLabel,
                        'chess opening',
                        'chess theory',
                        $parent['name'] ?? null,
                    ])),
                    // Full ISO 8601 with offset — Google flags date-only values.
                    // dateModified is when the Lichess numbers on the page were last
                    // fetched: a real change, unlike "today" on every crawl.
                    // The site went live on 2026-09-25.
                    'datePublished' => '2026-09-25T12:00:00+02:00',
                    'dateModified'  => $cachedAt !== null
                        ? (new DateTimeImmutable($cachedAt))->format(DATE_ATOM)
                        : '2026-09-25T12:00:00+02:00',
                    'author'        => $publisherLd,
                    'publisher'     => $publisherLd,
                    'isPartOf'      => [
                        '@type' => 'WebSite',
                        'name'  => t('site.name'),
                        'url'   => $siteRootUrl,
                    ],
                ],
                [
                    '@type' => 'BreadcrumbList',
                    'itemListElement' => array_map(static function ($i, $b) {
                        return [
                            '@type'    => 'ListItem',
                            'position' => $i + 1,
                            'name'     => $b['name'],
                            'item'     => $b['url'],
                        ];
                    }, array_keys($breadcrumbs), $breadcrumbs),
                ],
            ],
        ];
    }
}
