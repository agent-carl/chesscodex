<?php
declare(strict_types=1);

/**
 * Everything about the opening lines, under the names the pages, tools and
 * tests have always used. The code lives by concern in OpeningRows,
 * OpeningTree, OpeningNames, OpeningSearch, OpeningLists, CuratedLists and
 * Indexing; the methods here pass straight through to them.
 */
class Opening
{
    /**
     * Below this many Lichess games a line's percentages say little, and its
     * page says so.
     */
    public const FEW_GAMES = 100;

    /**
     * ?v= of every og.php URL (share cards and diagrams). Search engines and
     * social sites know the images by these URLs, so raise it only when the
     * drawing itself changes, not for every edit of og.php.
     */
    public const OG_VERSION = '2fbed566';

    /** The position diagram og.php draws (720×720 PNG), path from the site root. */
    public static function diagramPath(string $slug, bool $webp = false): string
    {
        return '/og.php?slug=' . urlencode($slug) . '&kind=diagram' . ($webp ? '&format=webp' : '') . '&v=' . self::OG_VERSION;
    }

    /** See Indexing::INDEXED_LINES. */
    public const INDEXED_LINES = Indexing::INDEXED_LINES;

    public static function indexedLine(bool $described, int $rank, string $slug): bool
    {
        return Indexing::indexedLine($described, $rank, $slug);
    }

    /** @param array<string, mixed> $o */
    public static function lineRank(array $o): int
    {
        return Indexing::lineRank($o);
    }

    /** @return array<string, mixed>|null */
    public static function findBySlug(string $slug): ?array
    {
        return OpeningRows::findBySlug($slug);
    }

    /** @return array<string, mixed>|null */
    public static function findById(int $id): ?array
    {
        return OpeningRows::findById($id);
    }

    /** @return list<string>|null */
    public static function uciById(int $id): ?array
    {
        return OpeningRows::uciById($id);
    }

    /**
     * @param array<string, mixed> $row
     * @return list<string>
     */
    public static function uci(array $row): array
    {
        return OpeningRows::uci($row);
    }

    /** @return list<array<string, mixed>> */
    public static function byEco(string $eco): array
    {
        return OpeningRows::byEco($eco);
    }

    /**
     * @param array<mixed> $slugs
     * @return list<array<string, mixed>>
     */
    public static function bySlugs(array $slugs): array
    {
        return OpeningRows::bySlugs($slugs);
    }

    public static function randomSlug(): ?string
    {
        return OpeningRows::randomSlug();
    }

    /** @return array{from: list<array{0: array<string, mixed>, 1: string}>, to: list<array{0: array<string, mixed>, 1: string}>} */
    public static function transpositions(int $id): array
    {
        return OpeningTree::transpositions($id);
    }

    /**
     * @param array<string, mixed> $opening
     * @return list<array<string, mixed>>
     */
    public static function withContinuations(array $opening): array
    {
        return OpeningTree::withContinuations($opening);
    }

    /** @param array<string, mixed> $opening */
    public static function continuationCount(array $opening): int
    {
        return OpeningTree::continuationCount($opening);
    }

    /** @return list<array<string, mixed>> */
    public static function children(int $parentId): array
    {
        return OpeningTree::children($parentId);
    }

    /** @return list<array<string, mixed>> */
    public static function ancestors(int $id): array
    {
        return OpeningTree::ancestors($id);
    }

    /** @return list<array<string, mixed>> */
    public static function siblings(int $id, int $parentId, int $limit = 8): array
    {
        return OpeningTree::siblings($id, $parentId, $limit);
    }

    public static function descendantCount(int $parentId): int
    {
        return OpeningTree::descendantCount($parentId);
    }

    /** @return list<array<string, mixed>> */
    public static function descendants(int $parentId, int $hardLimit = 2000): array
    {
        return OpeningTree::descendants($parentId, $hardLimit);
    }

    /** @return list<array<string, mixed>> */
    public static function continuationsFrom(string $canonQuery, int $limit = 5): array
    {
        return OpeningTree::continuationsFrom($canonQuery, $limit);
    }

    /** @param array<string, mixed> $opening */
    public static function distinguishingTail(array $opening): string
    {
        return OpeningNames::distinguishingTail($opening);
    }

    public static function nameCount(string $name): int
    {
        return OpeningNames::nameCount($name);
    }

    public static function nameSide(string $name, int $plies): string
    {
        return OpeningNames::nameSide($name, $plies);
    }

    public static function article(string $name): string
    {
        return OpeningNames::article($name);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<int, string>
     */
    public static function lineTails(array $rows, bool $ignoreEco = false): array
    {
        return OpeningNames::lineTails($rows, $ignoreEco);
    }

    public static function isGambit(string $name): bool
    {
        return OpeningNames::isGambit($name);
    }

    public static function movesLabel(int $plies): string
    {
        return OpeningNames::movesLabel($plies);
    }

    public static function movesFrom(string $pgn, int $fromPly): string
    {
        return OpeningNames::movesFrom($pgn, $fromPly);
    }

    public static function keepNumbers(string $moves): string
    {
        return OpeningNames::keepNumbers($moves);
    }

    public static function family(string $name): string
    {
        return OpeningNames::family($name);
    }

    /** @return list<string> */
    public static function aliases(string $name): array
    {
        return OpeningNames::aliases($name);
    }

    public static function titleAlias(string $name): ?string
    {
        return OpeningNames::titleAlias($name);
    }

    /** @param list<string> $names */
    public static function ecoLabel(array $names): string
    {
        return OpeningNames::ecoLabel($names);
    }

    public static function leadSentence(string $markdown): ?string
    {
        return OpeningNames::leadSentence($markdown);
    }

    public static function nameKey(string $s): string
    {
        return OpeningNames::nameKey($s);
    }

    /** @return array<string, mixed>|null */
    public static function searchByCanon(string $canonQuery): ?array
    {
        return OpeningSearch::searchByCanon($canonQuery);
    }

    /** @return list<array<string, mixed>> */
    public static function searchByFen(string $fen): array
    {
        return OpeningSearch::searchByFen($fen);
    }

    /** @return list<array<string, mixed>> */
    public static function searchByName(string $q, int $limit = 10): array
    {
        return OpeningSearch::searchByName($q, $limit);
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    public static function indexRow(array $r): array
    {
        return OpeningSearch::indexRow($r);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function rankByName(array $rows, string $q, int $limit = 10): array
    {
        return OpeningSearch::rankByName($rows, $q, $limit);
    }

    /** @return list<array<string, mixed>> */
    public static function suggestSimilar(string $failedSlug, int $limit = 5): array
    {
        return OpeningSearch::suggestSimilar($failedSlug, $limit);
    }

    /** @return array<string, array{count: int, label: string, slug: string, moves: string}> */
    public static function ecoCodes(): array
    {
        return OpeningLists::ecoCodes();
    }

    /** @return list<array<string, mixed>> */
    public static function described(): array
    {
        return OpeningLists::described();
    }

    /** @return array<string, int> */
    public static function countsByGroup(): array
    {
        return OpeningLists::countsByGroup();
    }

    public static function rootSlugForGroup(string $groupLetter): ?string
    {
        return OpeningLists::rootSlugForGroup($groupLetter);
    }

    /** @return array<string, list<array<string, mixed>>> */
    public static function allAlphabetical(): array
    {
        return OpeningLists::allAlphabetical();
    }

    /** @return array<string, array<string, array{name: string, lines: int, root: bool, plies: int, slug?: mixed, eco?: mixed}>> */
    public static function familiesByLetter(): array
    {
        return OpeningLists::familiesByLetter();
    }

    /** @return list<array<string, mixed>> */
    public static function topPopular(int $limit = 12): array
    {
        return CuratedLists::topPopular($limit);
    }

    /** @return list<array<string, mixed>> */
    public static function topGambits(int $limit = 12): array
    {
        return CuratedLists::topGambits($limit);
    }

    /** @return array{main: list<array<string, mixed>>, other: list<array<string, mixed>>} */
    public static function firstMoveTree(): array
    {
        return CuratedLists::firstMoveTree();
    }

    /** @return array<string, mixed>|null */
    public static function ofTheDay(): ?array
    {
        return CuratedLists::ofTheDay();
    }
}
