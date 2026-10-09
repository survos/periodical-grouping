# Periodical grouping

One deterministic grouping implementation for Harvest production and Ink previews.
PHP performs both page layout analysis and grouping. Ink renders server-prepared groups.
No HTTP, AI, database, Folio reader, or application kernel is required.

Requires PHP 8.4+ and mbstring. No external runtime or subprocess is used.

```php
use Survos\PeriodicalGrouping\GroupingEngine;

$proposal = (new GroupingEngine())->prepare([
    'issueId' => $issueId,
    'sourceHash' => $sourceHash, // SHA-256 of retained source layouts, computed by producer
    'pages' => $pages,
]);
```

The library does not know where an issue is stored. Collection names, database
keys, tenant IDs and storage paths belong to the calling application's envelope;
they are not grouping inputs and are not copied into the result. `issueId` is an
opaque identity used to generate stable group IDs. The caller is responsible for
namespacing it when combining results from multiple collections.

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
`sourceHash` against their current source before writing a proposal.

A group is not automatically an article: headline/body eligibility and whether
search uses the first body block are downstream policies. Persistence, revision
history, search indexing, PostgreSQL application state and publication remain
consumer responsibilities.

## CLI and browser

After a Composer install:

```sh
vendor/bin/group.php < issue-input.json > proposal.json
```

For an already analyzed page, `PageGrouper::prepare($issueId, $page, $mode)`
supports `combined`, `saved` and `proposed` modes. Ink uses this PHP API to prepare
all preview modes, and its existing Stimulus components render the groups. This
package ships no JavaScript library or browser assets. A reusable interactive viewer
would belong in a separate Symfony UX bundle.

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
this contract; Ink uses the same PHP package for previews. Neither application owns
a second implementation of these grouping rules.
