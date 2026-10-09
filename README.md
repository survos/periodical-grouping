# Periodical grouping

One deterministic grouping implementation for Harvest production and Ink previews.
PHP performs page layout analysis; Node runs the exact JavaScript used in the browser.
No HTTP, AI, database, Folio reader, or application kernel is required.

Requires PHP 8.4+, mbstring, and Node 22.12+ (synchronous ESM loading).

```php
use Survos\PeriodicalGrouping\GroupingEngine;

$proposal = (new GroupingEngine())->prepare([
    'issueId' => $issueId,
    'folioCode' => $folioCode, // optional identity passed through
    'sourceHash' => $sourceHash, // SHA-256 of retained source layouts, computed by producer
    'pages' => $pages,
]);
```

Each page has `pageIndex` (zero-based), original-pixel `width` and `height`, and
`blocks`: `{id, text, box: [x,y,width,height], fontSize?, paragraphStarts?}`.
Optional `articles` preserve unambiguous existing membership:
`{id, title, type?, blocks: [{id}]}`. Optional `review` supplies reviewed masthead,
program and group membership; its page dimensions must match. `measuredBottom`
is an optional masthead boundary in original pixels. Checked-in historical review
profiles supply defaults; pass an explicit empty review to disable one.

The result retains the existing schemaVersion 1 proposal contract: page analysis,
groups with stable `g-*` IDs, source block IDs, role line spans, and explicit
masthead/program/unassigned IDs. Every source block occurs exactly once.
Malformed/duplicate identities and invalid geometry fail rather than silently
losing source text. Source OCR is never modified. Grouping is page/column-local;
there is no cross-page continuation stitching.

`algorithmHash` identifies package rules and built-in profiles. `inputHash`
also covers source, optional reviews, measured geometry and existing memberships;
UI crop URLs and cached display analysis are excluded. Consumers must still check
`sourceHash` against their current Folio before writing a proposal.

A group is not automatically an article: headline/body eligibility and whether
search uses the first body block are downstream policies. Persistence, revision
history, search indexing, PostgreSQL application state and publication remain
consumer responsibilities.

## CLI and browser

After a Composer install:

```sh
vendor/bin/group.php < issue-input.json > proposal.json
```

The low-level `bin/prepare.cjs` accepts already analyzed pages and retains the old
Ink preparation contract. Use `GroupingEngine`/the PHP CLI for raw page inputs.
Import `assets/grouping.js` for browser column previews and stored rendering;
map that directory with AssetMapper in Symfony consumers. It has no DOM dependency.

## Verification

```sh
composer install
composer test
```

Includes heuristic/masthead fixtures, invalid-input and fingerprint checks, source
conservation, and the eight-page Cordele Dispatch issue of September 12, 1926
(LOC item 2022239700), retaining its 150 group memberships and role spans.
The Cordele fixture contains historical OCR and source IDs; it is not corrected text.

The package lives in `mono/lib/periodical-grouping` and is registered in the mono
split workflow. During local development, applications install the dependency
then use `../mono/link .` as usual. Harvest adapts existing Folio entities into
this contract; Ink uses the same package for previews. Neither application owns
a second implementation of these grouping rules.
