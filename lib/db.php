<?php
declare(strict_types=1);

/**
 * PDO connection to the SQLite database: one file (config.php → db.path,
 * db/chesscodex.sqlite by default). The site ran on MySQL on its first host;
 * since the move to the Raspberry Pi it is SQLite only, which saves running a
 * database server in 1 GB of RAM.
 */
function chess_codex_db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $config = require __DIR__ . '/../config.php';
    $pdo = chess_codex_sqlite_connect((string) ($config['db']['path'] ?? __DIR__ . '/../db/chesscodex.sqlite'));
    return $pdo;
}

function chess_codex_sqlite_connect(string $path): PDO
{
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];
    // PHP 8.4+ returns a Pdo\Sqlite (with createFunction()) from PDO::connect();
    // older versions only offer sqliteCreateFunction() on a plain PDO.
    $pdo = method_exists(PDO::class, 'connect')
        ? PDO::connect('sqlite:' . $path, null, null, $options)
        : new PDO('sqlite:' . $path, null, null, $options);

    $pdo->exec('PRAGMA busy_timeout = 5000');   // wait for a concurrent writer instead of failing
    $pdo->exec('PRAGMA synchronous = NORMAL');  // safe with WAL; far fewer fsyncs on the SD card
    $pdo->exec('PRAGMA foreign_keys = ON');

    // MySQL's utf8mb4_unicode_ci makes LIKE case- AND accent-insensitive
    // ("grunfeld" finds "Grünfeld") and uses "\" as the default escape
    // character — the search code relies on both. SQLite's built-in LIKE
    // does neither, so swap in a PHP implementation with MySQL semantics.
    $deterministic = class_exists('Pdo\Sqlite') ? \Pdo\Sqlite::DETERMINISTIC : PDO::SQLITE_DETERMINISTIC;
    $register = method_exists($pdo, 'createFunction') ? 'createFunction' : 'sqliteCreateFunction';
    $pdo->$register('like', 'chess_codex_sqlite_like', -1, $deterministic);

    return $pdo;
}

/**
 * SQLite calls like(pattern, value[, escape]) for `value LIKE pattern [ESCAPE e]`.
 * Returns 1/0 like the built-in, or NULL when either side is NULL.
 */
function chess_codex_sqlite_like(?string $pattern, ?string $value, ?string $escape = '\\'): ?int
{
    if ($pattern === null || $value === null) {
        return null;
    }

    static $compiled = [];
    $key = $escape . "\0" . $pattern;
    if (!isset($compiled[$key])) {
        $chars = preg_split('//u', chess_codex_fold($pattern), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $regex = '';
        for ($i = 0, $n = count($chars); $i < $n; $i++) {
            $c = $chars[$i];
            if ($c === $escape && $i + 1 < $n) {
                $regex .= preg_quote($chars[++$i], '/');
            } elseif ($c === '%') {
                $regex .= '.*';
            } elseif ($c === '_') {
                $regex .= '.';
            } else {
                $regex .= preg_quote($c, '/');
            }
        }
        $compiled[$key] = '/^' . $regex . '$/su';
    }

    return preg_match($compiled[$key], chess_codex_fold($value)) === 1 ? 1 : 0;
}

/** Lowercase + strip Latin diacritics, the way utf8mb4_unicode_ci compares. */
function chess_codex_fold(string $s): string
{
    static $map = [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'ā' => 'a', 'ă' => 'a', 'ą' => 'a',
        'æ' => 'ae', 'ç' => 'c', 'ć' => 'c', 'ĉ' => 'c', 'ċ' => 'c', 'č' => 'c', 'ď' => 'd', 'đ' => 'd',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e', 'ĕ' => 'e', 'ė' => 'e', 'ę' => 'e', 'ě' => 'e',
        'ĝ' => 'g', 'ğ' => 'g', 'ġ' => 'g', 'ģ' => 'g', 'ĥ' => 'h', 'ħ' => 'h',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ĩ' => 'i', 'ī' => 'i', 'ĭ' => 'i', 'į' => 'i', 'ı' => 'i',
        'ĵ' => 'j', 'ķ' => 'k', 'ĺ' => 'l', 'ļ' => 'l', 'ľ' => 'l', 'ŀ' => 'l', 'ł' => 'l',
        'ñ' => 'n', 'ń' => 'n', 'ņ' => 'n', 'ň' => 'n',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o', 'ō' => 'o', 'ŏ' => 'o', 'ő' => 'o', 'œ' => 'oe',
        'ŕ' => 'r', 'ŗ' => 'r', 'ř' => 'r', 'ś' => 's', 'ŝ' => 's', 'ş' => 's', 'š' => 's', 'ß' => 'ss',
        'ţ' => 't', 'ť' => 't', 'ŧ' => 't',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ũ' => 'u', 'ū' => 'u', 'ŭ' => 'u', 'ů' => 'u', 'ű' => 'u', 'ų' => 'u',
        'ŵ' => 'w', 'ý' => 'y', 'ÿ' => 'y', 'ŷ' => 'y', 'ź' => 'z', 'ż' => 'z', 'ž' => 'z',
    ];
    return strtr(mb_strtolower($s, 'UTF-8'), $map);
}
