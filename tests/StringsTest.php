<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

final class StringsTest extends TestCase
{
    #[TestDox('t: a known key, placeholders, an unknown key comes back as itself')]
    public function testT(): void
    {
        $this->assertSame('Caissa Codex', t('site.name'));
        $this->assertSame('1,234 lines', t('home.card.openings', ['count' => '1,234']));
        $this->assertSame('12 lines', t('home.card.openings', ['count' => 12]));
        $this->assertSame('{count} lines', t('home.card.openings'), 'a placeholder without a value stays');
        $this->assertSame('{count} lines', t('home.card.openings', ['other' => 1]));
        $this->assertSame('no.such.key', t('no.such.key'));
        $this->assertSame('no.such.key', t('no.such.key', ['count' => 1]));
    }
}
