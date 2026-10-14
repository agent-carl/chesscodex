<?php
declare(strict_types=1);

/** Another request to Lichess is in flight, or a 429 cool-down is under way (LichessExplorer). */
final class LichessBusyException extends RuntimeException
{
    /** Seconds until it's worth trying again. */
    public int $retryAfter = 2;
}
