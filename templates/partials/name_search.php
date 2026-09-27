<?php
/**
 * The name search field with its suggestion list; public/site.js fills the
 * list as you type. Used on the home page, /search and the 404 page.
 *
 * @var string      $nameSearchId           id prefix, unique on the page
 * @var string|null $nameSearchLabel        visible label; null = screen readers only
 * @var string|null $nameSearchPlaceholder
 * @var string|null $nameSearchValue        text to start with; suggestions show at once
 */
$nsId          = htmlspecialchars($nameSearchId, ENT_QUOTES, 'UTF-8');
$nsLabel       = $nameSearchLabel ?? null;
$nsPlaceholder = $nameSearchPlaceholder ?? "Najdorf, Queen's Gambit, B20…";
$nsValue       = (string) ($nameSearchValue ?? '');
?>
<div class="name-search">
    <label for="<?= $nsId ?>-input" class="<?= $nsLabel === null ? 'visually-hidden' : 'name-search-label' ?>">
        <?= htmlspecialchars($nsLabel ?? 'Search openings by name or ECO code', ENT_QUOTES, 'UTF-8') ?>
    </label>
    <div class="name-search-field">
        <input type="search" id="<?= $nsId ?>-input" name="q"
               data-name-search="<?= $nsId ?>-results"
               placeholder="<?= htmlspecialchars($nsPlaceholder, ENT_QUOTES, 'UTF-8') ?>"
               <?php if ($nsValue !== ''): ?>value="<?= htmlspecialchars($nsValue, ENT_QUOTES, 'UTF-8') ?>" data-name-search-prefilled<?php endif; ?>
               autocomplete="off" spellcheck="false" enterkeyhint="search"
               role="combobox" aria-expanded="false" aria-autocomplete="list"
               aria-controls="<?= $nsId ?>-results">
        <svg class="name-search-icon" viewBox="0 0 20 20" width="18" height="18" aria-hidden="true" focusable="false">
            <circle cx="8.5" cy="8.5" r="5.5" fill="none" stroke="currentColor" stroke-width="2"/>
            <path d="M12.6 12.6 17 17" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
        </svg>
    </div>
    <ul class="search-by-name-results" id="<?= $nsId ?>-results" hidden role="listbox"></ul>
</div>
