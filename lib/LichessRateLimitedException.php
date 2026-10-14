<?php
declare(strict_types=1);

/** Lichess answered HTTP 429 (LichessExplorer). */
final class LichessRateLimitedException extends RuntimeException {}
