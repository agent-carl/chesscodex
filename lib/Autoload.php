<?php
declare(strict_types=1);

/**
 * Tiny class-map autoloader. All Chess Codex classes are global (no
 * namespaces) and live in lib/<Class>.php. spl_autoload_register fires
 * when a class is first referenced and looks it up in the map below.
 */
spl_autoload_register(static function (string $class): void {
    static $map = [
        'Auth'             => __DIR__ . '/Auth.php',
        'Cache'            => __DIR__ . '/Cache.php',
        'ChessEngine'      => __DIR__ . '/ChessEngine.php',
        'I18n'             => __DIR__ . '/I18n.php',
        'LichessExplorer'  => __DIR__ . '/LichessExplorer.php',
        'Logger'           => __DIR__ . '/Logger.php',
        'Migrations'       => __DIR__ . '/Migrations.php',
        'Opening'          => __DIR__ . '/Opening.php',
        'Rankings'         => __DIR__ . '/Rankings.php',
        'RateLimit'        => __DIR__ . '/RateLimit.php',
        'Router'           => __DIR__ . '/Router.php',
        'Routes'           => __DIR__ . '/Routes.php',
        'StatsCache'       => __DIR__ . '/StatsCache.php',
        'Submissions'      => __DIR__ . '/Submissions.php',
        'Views'            => __DIR__ . '/Views.php',
    ];
    if (isset($map[$class]) && is_file($map[$class])) {
        require_once $map[$class];
    }
});
