<?php
declare(strict_types=1);

/**
 * PDO connection factory. Two backends, picked by config.php → db.driver:
 *
 *   'mysql'  (default) — MySQL 8 / MariaDB, as on the original OVH hosting.
 *   'sqlite'           — a single database file; used on the Raspberry Pi,
 *                        where it saves running a whole DB server in 1 GB RAM.
 *
 * Queries elsewhere are written to run on both. The few spots where the SQL
 * dialects differ (upserts, RAND) branch on chess_codex_db_driver().
 */
function chess_codex_db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $config = require __DIR__ . '/../config.php';
    $db = $config['db'];

    if (($db['driver'] ?? 'mysql') === 'sqlite') {
        $pdo = chess_codex_sqlite_connect((string) $db['path']);
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $db['host'], $db['port'], $db['name'], $db['charset']
    );

    $pdo = new PDO($dsn, $db['user'], $db['password'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    return $pdo;
}

/** 'mysql' or 'sqlite' — for the handful of queries whose syntax differs. */
function chess_codex_db_driver(): string
{
    return (string) chess_codex_db()->getAttribute(PDO::ATTR_DRIVER_NAME);
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
