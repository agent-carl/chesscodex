<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every kind of page answers with the right status over HTTP — after a deploy:
 *
 *   _composer_vendor/bin/phpunit --testsuite smoke                    the live site
 *   SMOKE_BASE=http://127.0.0.1:8099 _composer_vendor/bin/phpunit --testsuite smoke
 */
final class SmokeTest extends TestCase
{
    public static function routes(): array
    {
        $base  = self::base();
        $local = (bool) preg_match('#^https?://(127\.0\.0\.1|localhost)[:/]#', $base . '/');
        return [
            // name => [path, status, text the body contains]
            '/'                              => ['/', 200, 'Caissa Codex'],
            '/about'                         => ['/about', 200, 'high school student'],
            '/openings'                      => ['/openings', 200, 'A–Z'],
            '/best-openings-for-white'       => ['/best-openings-for-white', 200, 'White score'],
            '/gambits'                       => ['/gambits', 200, 'Gambit'],
            '/openings/sicilian-defense'     => ['/openings/sicilian-defense', 200, 'Sicilian'],
            '/search'                        => ['/search', 200, 'search'],
            '/play/sicilian-defense'         => ['/play/sicilian-defense', 200, 'Stockfish'],
            '/random'                        => ['/random', 302, ''],
            '/robots.txt'                    => ['/robots.txt', 200, 'Sitemap:'],
            '/sitemap.xml'                   => ['/sitemap.xml', 200, '<sitemapindex'],
            '/api/search'                    => ['/api/search?name=naj', 200, 'Najdorf'],
            '/api/stats'                     => ['/api/stats?id=1', 200, '{'],
            '/api/suggest'                   => ['/api/suggest', 405, 'POST only'],
            '/admin'                         => ['/admin', 302, ''],
            // On the live site Cloudflare Access answers first, with a redirect to its login.
            '/admin/login'                   => ['/admin/login', $local ? 200 : 302, ''],
            '/openings/this-does-not-exist'  => ['/openings/this-does-not-exist', 404, 'Did you mean'],
            '/nonsense-page'                 => ['/nonsense-page', 404, 'Page not found'],
            '/og.php'                        => ['/og.php?slug=sicilian-defense', 200, ''],
        ];
    }

    #[DataProvider('routes')]
    public function testRoute(string $path, int $status, string $contains): void
    {
        $ch = curl_init(self::base() . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,    // a 302 is what we check
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_USERAGENT      => 'caissa-codex-smoke/1.0',
        ]);
        $body = (string) curl_exec($ch);
        $this->assertSame($status, curl_getinfo($ch, CURLINFO_HTTP_CODE), $path);
        if ($contains !== '') $this->assertStringContainsString($contains, $body, $path);
    }

    private static function base(): string
    {
        return rtrim((string) (getenv('SMOKE_BASE') ?: 'https://chesscodex.org'), '/');
    }
}
