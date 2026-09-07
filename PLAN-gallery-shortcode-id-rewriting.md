# Plan: Rewrite attachment IDs in classic `[gallery]` shortcodes during cdiff

## Problem

cdiff rewrites attachment IDs only inside Gutenberg blocks (`wp:*`). Classic-editor
`[gallery ids="60270,60476,..."]` shortcodes in `post_content` are left untouched, so
their IDs still point at old live IDs that don't exist locally. The images don't render.

Concrete example (Porvir): post `wp_posts.ID = 5037`
(`post_name = conheca-escolas-de-educacao-infantil-arquitetura-dos-sonhos`) contains
`[gallery ids="60270,60476,60267,..."]`. Old live ID `60270` maps to local ID `18222`
(via postmeta `newspackcontentdiff_oldid_porvir.org`), but the shortcode was never updated.

## Goal

Add classic `[gallery]` shortcode ID rewriting to cdiff's `BlockUpdater`, so it runs as
part of the same content-update pass that already rewrites block IDs. This fixes every
current and future migration, not just Porvir.

Scope: the `ids` attribute of the core `[gallery]` shortcode. Everything else
(block handling, other shortcodes) is out of scope.

## Why in `BlockUpdater` (not a one-off Porvir fixer)

- The old->new ID map (`$known_attachment_ids_updates`) is already built and threaded into
  `BlockUpdater::update_all_blocks_ids()` at
  [ContentDiffLogic.php:1857-1858](src/Logic/ContentDiffLogic.php#L1857-L1858).
- The same call also powers the README's "self-healing" re-run pass (all previously
  imported content is re-processed), so galleries get fixed on later runs too, for free.
- The CSV-ID remap logic and idempotency guard already exist in the class (see below).

This is an upstream contribution to the shared plugin. It needs full test coverage and must
pass `composer phpcs` and `composer phpunit` (Ivan runs these manually -- do NOT run them).

---

## Reuse: NMT `Shortcodes` class (no extension needed)

`Newspack\MigrationTools\Logic\Shortcodes`
([vendor/automattic/newspack-migration-tools/src/Logic/Shortcodes.php](vendor/automattic/newspack-migration-tools/src/Logic/Shortcodes.php))
already provides everything:

- `has_shortcode( 'gallery', $content ): bool` -- early-exit guard.
- `get_all_shortcodes_from_content( 'gallery', $content ): array` -- returns each full
  shortcode string (e.g. `[gallery ids="60270,60476"]`). Works without the shortcode being
  registered (uses `get_shortcode_regex(['gallery'])`).
- `get_shortcode_attribute( 'ids', $shortcode ): mixed` -- returns the CSV string, `true`
  (flag with no value), or `false` (absent). Use strict comparison; ids we want is a
  non-empty string.

No new methods on `Shortcodes` are required. Do NOT modify NMT. If during implementation a
genuine gap is found (it should not be), STOP and flag it rather than editing the vendor
package.

---

## Design

### 1. Instantiate `Shortcodes` in `BlockUpdater` constructor

Match the existing style: `wp_block_manipulator` and `html_element_manipulator` are `new`ed
directly in the constructor (not injected). Do the same.

In [BlockUpdater.php](src/Logic/BlockUpdater.php):

```php
use Newspack\MigrationTools\Logic\Shortcodes;

// property, alongside the other two manipulators:
/**
 * Shortcodes instance.
 *
 * @var Shortcodes
 */
private Shortcodes $shortcodes;

// in __construct(), alongside the other two:
$this->shortcodes = new Shortcodes();
```

Do not change the constructor signature (single caller, per coding standards).

### 2. Add `update_gallery_shortcode_ids()` method

Place it next to the other `update_*_ids()` methods (follow existing class organization,
e.g. after the jetpack methods). Signature and docblock must mirror the sibling methods:

```php
/**
 * Updates attachment IDs in classic `[gallery ids="..."]` shortcodes.
 *
 * @param string $content                      Post content.
 * @param array  $known_attachment_ids_updates Known ID mappings (old => new). Passed by reference.
 * @param array  $local_hostname_aliases       Hostnames to treat as local. Unused here; kept for signature parity.
 *
 * @return string Updated content.
 */
public function update_gallery_shortcode_ids(
    string $content,
    array &$known_attachment_ids_updates,
    array $local_hostname_aliases = []
): string {
    // ...
}
```

Note: `$local_hostname_aliases` is unused for shortcodes (no URLs involved, only CSV IDs).
Keep it in the signature for parity with the other `update_*` methods and the uniform call
in `update_all_blocks_ids()`. Add the appropriate phpcs ignore for the unused param,
matching how the class/tests already silence `Generic.CodeAnalysis.UnusedFunctionParameter`.

Algorithm:

1. Early exit: `if ( ! $this->shortcodes->has_shortcode( 'gallery', $content ) ) return $content;`
2. `$shortcodes = $this->shortcodes->get_all_shortcodes_from_content( 'gallery', $content );`
3. For each full `$shortcode` string:
   a. `$ids_csv = $this->shortcodes->get_shortcode_attribute( 'ids', $shortcode );`
   b. Skip if `! is_string( $ids_csv ) || '' === $ids_csv` (handles `[gallery]` with no
      `ids`, which pulls all post children -- must be a no-op).
   c. `$old_ids = array_map( 'trim', explode( ',', $ids_csv ) );`
   d. Build `$new_ids`: for each id,
      - If the id is already a value in `$known_attachment_ids_updates` (already local),
        keep it as-is -- idempotency guard, mirrors
        [BlockUpdater.php:557](src/Logic/BlockUpdater.php#L557).
      - Else map through `$known_attachment_ids_updates[ $id ] ?? $id`.
      - Cast consistently to match the map's int keys/values (the map is
        `array_map('intval', ...)`'d at
        [ContentDiffLogic.php:1825](src/Logic/ContentDiffLogic.php#L1825)); compare/lookup
        as int, emit as string for the CSV.
   e. If `$new_ids === $old_ids`, continue (nothing changed).
   f. `$new_ids_csv = implode( ',', $new_ids );`
   g. `$shortcode_updated = str_replace( $ids_csv, $new_ids_csv, $shortcode );`
      -- replace only the CSV value substring, preserving quote style, spacing, and any
      other attributes in the shortcode.
   h. `$content = str_replace( $shortcode, $shortcode_updated, $content );`

Rationale for the substring-replace approach (vs. rebuilding the shortcode): it preserves
the original formatting exactly (quote style, attribute order, HTML-encoded quotes), and the
ids CSV (`60270,60476,...`) is specific enough to not collide with other attribute values
like `columns="3"`. This also sidesteps fancy-quote / HTML-entity concerns, because the ids
value is digits and commas only, regardless of how the surrounding quotes are encoded.

### 3. Wire into `update_all_blocks_ids()`

Add one line at the end of the chain in
[BlockUpdater.php:85-98](src/Logic/BlockUpdater.php#L85-L98), after
`update_patterns_wp_block_ids()`:

```php
$content = $this->update_gallery_shortcode_ids( $content, $known_attachment_ids_updates, $local_hostname_aliases );
```

Also update the method's docblock block-type list (lines ~64-84) to note classic
`[gallery]` shortcode handling, so the doc stays accurate.

---

## Edge cases (must all be covered by tests)

1. **Single gallery**, all ids in map -> all remapped.
2. **Multiple galleries** in one post -> each remapped independently.
3. **Gallery mixed with Gutenberg blocks** -> both block IDs and gallery IDs updated in the
   same pass; blocks unaffected by the new code.
4. **`[gallery]` with no `ids`** (or `ids=""`) -> no-op, content unchanged.
5. **IDs not in the map** -> left unchanged (partial map; e.g. `?? $id`).
6. **Mixed** -- some ids in map, some not -> only the mapped ones change; order preserved.
7. **Idempotency / re-run** -> running twice yields the same result; ids already local
   (present as map values) are not double-mapped or corrupted.
8. **Quote/whitespace preservation** -> single vs double quotes, and spaces after commas
   (`ids="1, 2, 3"`) preserved except for the remapped digits. Confirm `get_shortcode_attribute`
   + trim handles spaced CSVs; if it does not, normalize on emit and assert the resulting
   shortcode is still valid.
9. **No gallery present** -> `has_shortcode` early-exit, content returned untouched.
10. **Duplicate identical gallery strings** -> `str_replace` updates all occurrences
    consistently (acceptable; deterministic map).

Optional / decision needed (see Open questions): `[gallery include="..."]` alias.

---

## Tests

Add to the existing unit test file
[tests/unit/Logic/BlockUpdaterTest.php](tests/unit/Logic/BlockUpdaterTest.php), matching its
conventions (PHPUnit `TestCase`, `@test`/`@covers`, fixture loading via `load_fixture()`,
asserting the ENTIRE updated content string, not just individual IDs).

- Add one method per edge case above (10+ cases).
- Add fixtures under [tests/fixtures/blocks/](tests/fixtures/blocks/) mirroring the existing
  ones (e.g. `gallery-shortcode.html`, `gallery-shortcode-mixed-with-blocks.html`,
  `gallery-shortcode-no-ids.html`). Use realistic content resembling the Porvir example.
- `@covers \Newspack\ContentDiffMigrator\Logic\BlockUpdater::update_gallery_shortcode_ids`.
- The `Shortcodes` dependency uses only WP core functions (`get_shortcode_regex`,
  `shortcode_parse_atts`, `preg_match_all`). Confirm these are available in the unit-test
  bootstrap; if the pure-`TestCase` unit context lacks them, either add a minimal integration
  test in [tests/Integration/](tests/Integration/) (which loads WP) OR verify the unit
  bootstrap already stubs/loads them (other block tests parse blocks with `parse_blocks`,
  so WP shortcode functions are likely available -- verify before choosing).
- If an integration-level assertion is warranted (the full migrate pass rewrites a
  gallery-bearing post), add it to
  [tests/Integration/CmdMigrateLiveContentBlocksTest.php](tests/Integration/CmdMigrateLiveContentBlocksTest.php).

---

## Files touched

- `src/Logic/BlockUpdater.php` -- new property, constructor line, new method, one call in
  `update_all_blocks_ids()`, docblock update.
- `tests/unit/Logic/BlockUpdaterTest.php` -- new test methods.
- `tests/fixtures/blocks/gallery-shortcode*.html` -- new fixtures.
- Possibly `tests/Integration/CmdMigrateLiveContentBlocksTest.php` -- one integration case.

No changes to NMT, NCC, `ContentDiffLogic`, or the constructor signature.

---

## Acceptance criteria

- `[gallery ids="..."]` old IDs are remapped to local IDs via `$known_attachment_ids_updates`
  during `migrate-live-content` (and its re-run self-healing pass).
- All edge cases pass; re-running is idempotent.
- `composer phpcs` clean; `composer phpunit` green (Ivan runs both manually).
- No behavior change for posts without `[gallery]`.

## Open questions (resolve before/at implementation)

1. **`include=` alias.** Core `[gallery]` also accepts `include="1,2,3"` as an alias for
   `ids`. Porvir uses `ids`. Decision: handle only `ids` now, or also `include`? Recommend
   `ids` only unless a quick DB scan shows `include=` usage. (Cheap to add later.)
2. **Unit vs integration for the shortcode tests** -- confirm WP shortcode functions are in
   the unit bootstrap; otherwise put the WP-dependent cases in Integration.
