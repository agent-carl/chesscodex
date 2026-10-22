<?php

use PHPUnit\Framework\TestCase;

/**
 * The admin panel over HTTP, on the local copy only: it creates suggestions
 * (the rows stay in the dev database). Logs in as the test admin from
 * tests/admin-test-credentials, which tools/dev/serve.sh puts into the copy's
 * config.php.
 *
 *   SMOKE_BASE=http://127.0.0.1:8099 _composer_vendor/bin/phpunit --testsuite admin
 */
final class AdminTest extends TestCase
{
    private const OPENING = 'ruy-lopez';

    private string $base;
    private string $jar;

    protected function setUp(): void
    {
        $this->base = rtrim((string) (getenv('SMOKE_BASE') ?: ''), '/');
        if (!preg_match('#^https?://(127\.0\.0\.1|localhost)(:\d+)?$#', $this->base)) {
            $this->markTestSkipped('The admin test creates data: set SMOKE_BASE to the local copy, e.g. http://127.0.0.1:8099.');
        }
        $this->jar = (string) tempnam(sys_get_temp_dir(), 'admintest');
    }

    protected function tearDown(): void
    {
        if (isset($this->jar)) @unlink($this->jar);
    }

    /**
     * @param array<string, string|list<string>>|null $fields
     * @return array{0: int, 1: string, 2: string} status, body, Location header
     */
    private function request(string $path, ?array $fields = null): array
    {
        $ch = curl_init($this->base . $path);
        /** @var array<int, mixed> $options */
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_USERAGENT      => 'caissa-codex-admin-test/1.0',
            CURLOPT_COOKIEJAR      => $this->jar,
            CURLOPT_COOKIEFILE     => $this->jar,
            CURLOPT_HEADER         => true,
        ];
        if ($fields !== null) {
            $options[CURLOPT_POST]       = true;
            $options[CURLOPT_POSTFIELDS] = http_build_query($fields);
        }
        curl_setopt_array($ch, $options);
        $raw    = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $head   = substr($raw, 0, (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE));
        $body   = substr($raw, strlen($head));
        return [$status, $body, $this->first('/^Location:\s*(\S+)/mi', $head, '')];
    }

    /** The first capture group of $pattern in $text; $default, or a failed test without one. */
    private function first(string $pattern, string $text, ?string $default = null): string
    {
        $found = preg_match($pattern, $text, $m) === 1 ? ($m[1] ?? '') : $default;
        if ($found === null) $this->fail("$pattern not found");
        return $found;
    }

    private function csrf(string $html): string
    {
        return $this->first('/name="csrf" value="([0-9a-f]+)"/', $html);
    }

    /** The opening page's description section, which a decision must not change. */
    private function description(): string
    {
        [$status, $html] = $this->request('/openings/' . self::OPENING);
        $this->assertSame(200, $status);
        return $this->first('#(<section class="opening-description".*?</section>)#s', $html);
    }

    private function suggest(int $openingId, string $text): int
    {
        [$status, $body] = $this->request('/api/suggest', [
            'opening_id' => (string) $openingId,
            'markdown'   => $text,
            'name'       => 'Admin test',
        ]);
        $this->assertSame(200, $status, $body);
        $json = json_decode($body, true);
        $this->assertTrue($json['ok'] ?? false);
        return (int) $json['id'];
    }

    private function statusOf(int $id): string
    {
        [$status, $html] = $this->request('/admin/review/' . $id);
        $this->assertSame(200, $status);
        return $this->first('#<dt>Status</dt>\s*<dd><strong>(\w+)</strong>#', $html);
    }

    public function testAdminPanel(): void
    {
        [$user, $password] = explode("
", trim((string) file_get_contents(__DIR__ . '/admin-test-credentials')));

        // Not logged in.
        [$status, , $location] = $this->request('/admin');
        $this->assertSame(302, $status);
        $this->assertStringEndsWith('/admin/login', $location);

        // Wrong password: the login page again, with the error, still no access.
        [$status, $body] = $this->request('/admin/login', ['username' => $user, 'password' => $password . 'x']);
        $this->assertSame(200, $status);
        $this->assertStringContainsString('Invalid credentials.', $body);
        $this->assertSame(302, $this->request('/admin')[0]);

        // Right password.
        [$status, , $location] = $this->request('/admin/login', ['username' => $user, 'password' => $password]);
        $this->assertSame(302, $status);
        $this->assertStringEndsWith('/admin', $location);
        [$status, $dashboard] = $this->request('/admin');
        $this->assertSame(200, $status);
        $csrf = $this->csrf($dashboard);

        // Four suggestions through the public form.
        $openingId = (int) $this->first('/name="opening_id" value="(\d+)"/', $this->request('/openings/' . self::OPENING)[1]);
        $tag = 'admin test ' . bin2hex(random_bytes(4));
        $ids = [];
        foreach (['accept', 'reject', 'bulk-accept', 'bulk-reject'] as $what) {
            $ids[$what] = $this->suggest($openingId, "Suggestion for the $what check, $tag. It is long enough to be accepted by the form.");
        }

        // They show on the dashboard and on their review pages.
        [, $dashboard] = $this->request('/admin');
        [, $review] = $this->request('/admin/review/' . $ids['accept']);
        foreach ($ids as $id) {
            $this->assertStringContainsString('/admin/review/' . $id . '"', $dashboard);
        }
        $this->assertStringContainsString($tag, $review);
        $this->assertStringContainsString('<button type="submit" class="admin-btn-accept">Accept</button>', $review);
        $this->assertSame('pending', $this->statusOf($ids['accept']));

        // Accept needs the CSRF token, and only marks the suggestion.
        $before = $this->description();
        $this->assertSame(400, $this->request('/admin/review/' . $ids['accept'] . '/accept', ['notes' => 'x'])[0]);
        $this->assertSame('pending', $this->statusOf($ids['accept']));
        $this->assertSame(302, $this->request('/admin/review/' . $ids['accept'] . '/accept', ['csrf' => $csrf, 'notes' => 'ok'])[0]);
        $this->assertSame('accepted', $this->statusOf($ids['accept']));
        $this->assertSame($before, $this->description(), 'accepting changed the public description');
        $this->assertStringNotContainsString($tag, $this->description());

        // Reject.
        $this->assertSame(302, $this->request('/admin/review/' . $ids['reject'] . '/reject', ['csrf' => $csrf, 'notes' => 'no'])[0]);
        $this->assertSame('rejected', $this->statusOf($ids['reject']));

        // Bulk.
        $this->assertSame(400, $this->request('/admin/bulk', ['bulk_accept' => '1', 'ids' => [(string) $ids['bulk-accept']]])[0]);
        $this->assertSame(302, $this->request('/admin/bulk', ['csrf' => $csrf, 'bulk_accept' => '1', 'ids' => [(string) $ids['bulk-accept']]])[0]);
        $this->assertSame(302, $this->request('/admin/bulk', ['csrf' => $csrf, 'bulk_reject' => '1', 'ids' => [(string) $ids['bulk-reject']]])[0]);
        $this->assertSame('accepted', $this->statusOf($ids['bulk-accept']));
        $this->assertSame('rejected', $this->statusOf($ids['bulk-reject']));
        $this->assertSame($before, $this->description());

        // The description editor is gone.
        $this->assertSame(404, $this->request('/admin/edit/' . self::OPENING)[0]);
        $this->assertSame(404, $this->request('/admin/preview', ['csrf' => $csrf, 'markdown' => 'x'])[0]);

        // Logout needs the token too.
        $this->request('/admin/logout', ['csrf' => $csrf]);
        [$status, , $location] = $this->request('/admin');
        $this->assertSame(302, $status);
        $this->assertStringEndsWith('/admin/login', $location);
    }
}
