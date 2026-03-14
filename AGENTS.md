# AI agent guidelines for newspack-content-diff-migrator (NCDM) v2

CLI-only WordPress plugin for multi-source content differential migration. Syncs new/modified content from one or more remote "live" sites to a local "staging" site using side-by-side database tables. PHP-only, no frontend.

**Abbreviations:** NCDM = this plugin, NMT = newspack-migration-tools (dependency), NCC = newspack-content-converter (dependency), MDCS = Migration Data Consistency Standard.

## Architecture

Modular design with 12 source files:

**Command layer:**
- `Command/ContentDiffMigrator.php` – CLI commands, `COMMANDS` constant defines all commands
- `Command/ContentDiffMigratorIndex.php` – Interactive command selector menu, with an index of all commands and their descriptions, and interractive prompts for all required and optional arguments, command execution confirmation, and a loop to run another command.

**Logic layer:**
- `Logic/ContentDiffLogic.php` – Core diff detection, ID mapping, orchestrates imports
- `Logic/DataImporter.php` – Post/user/term/comment import operations
- `Logic/BlockUpdater.php` – Gutenberg block ID rewriting (10 block types)
- `Logic/RunState.php` – JSONL file state management for resumability

**Utils layer:**
- `Utils/Logger.php` – Singleton logger using NMT's CliLog/FileLog (Monolog)
- `Utils/DB.php` – Collation comparison and fixing
- `Utils/Progress.php` – Milestone-based progress tracking
- `Utils/ReportCreator.php` – CSV report generation
- `Utils/SLAHelper.php` – Simple Local Avatars integration

**Bootstrap:**
- `PluginSetup.php` – Error reporting, memory ticker

Key workflow (two-phase):

1. **Search phase**: `search-new-content-on-live` – compares live vs local posts tables, identifies new or changed posts which should be migrated.
2. **Migrate phase**: `migrate-live-content` – imports posts, users, comments, taxonomies, based on the results of the search phase.

The plugin reads from live DB tables (e.g., `cdiff_posts`, `cdiff_postmeta`) that exist alongside local WP tables in the same database, and imports the differential of content into the local site (hence the "differential" in the name).

## What this plugin does (internal capabilities)

- **Content differential detection** – Uses composite key hashing (post_name, title, type, status, date) and post_modified comparison to detect new or changed posts. O(n+m) optimized.
- **Full relational data import** – Imports each post with all related WP data: postmeta, comments, commentmeta, users, usermeta, and taxonomy relationships.
- **Hierarchical taxonomy reconstruction** – Rebuilds category/tag hierarchies recursively from root to leaf before importing posts, so parent-child relationships are preserved.
- **Gutenberg block ID rewriting** – Parses 10 block types (image, gallery, audio, video, file, cover, media-text, jetpack blocks, wp:block patterns) and rewrites embedded attachment IDs. Uses NCC's block manipulators.
- **Featured image and post_parent remapping** – Updates `_thumbnail_id` postmeta and `post_parent` to reference newly imported IDs.
- **User migration** – Imports users with SLA avatar support and ID mapping.
- **Collation handling** – Compares and automatically corrects database collations between live and local tables, so they can be compared against each other.
- **ID tracking for resumability** – Stores original live ID and source hostname in postmeta (`newspackcontentdiff_oldid_{hostname}`). Commands skip already-processed IDs on re-run.
- **Multi-source support** – Multiple `--source-hostname` values, each tracked with its own meta key.
- **On-the-fly taxonomy creation** – Categories/terms created only when needed by posts (no bulk pre-import).
- **Multi-source collision handling** – Users/terms with same unique identifiers are gracefully merged across sources.
- **RunState persistence** – JSONL files used by the application logic to track the state of the migration in `{data-dir}/run-state/`, used for resumable operations.
- **CSV report generation** – Creates posts.csv, users.csv, terms.csv in `{data-dir}/reports/` folder, used for reporting the results of the migration.

## Commands

All commands use the `newspack-content-diff-migrator` prefix.

### Migration Commands

Run these in sequence: first search, then migrate.

```bash
# Phase 1: Search for new/modified content
wp newspack-content-diff-migrator search-new-content-on-live \
  --live-table-prefix=cdiff_ \
  --source-hostname=www.example.com \
  --data-dir=/tmp/cdiff_data \
  --post-types-csv=post,page,attachment

# Phase 2: Migrate content
wp newspack-content-diff-migrator migrate-live-content \
  --live-table-prefix=cdiff_ \
  --source-hostname=www.example.com \
  --data-dir=/tmp/cdiff_data \
  --custom-taxonomies-csv=category,post_tag,author
```

### Attribution Commands

**What is attribution?** Attribution assigns `newspackcontentdiff_oldid_{hostname}` metas to existing local content. This is necessary when local content already exists (e.g., cloned from live) but doesn't have the tracking metas. Without attribution, the plugin considers such content "new" and creates duplicates during migration.

Three commands for different use cases:

```bash
# 1. attribute-all-unattributed
# USE CASE: Run immediately after cloning a site. Assigns metas to ALL
# existing content. Does NOT require live tables.
wp newspack-content-diff-migrator attribute-all-unattributed \
  --source-hostname=www.example.com \
  --data-dir=/tmp/attr_data

# 2. attribute-match-local-to-live-tables
# USE CASE: Local site was cloned but also has custom content (e.g., 
# Newspackification work). Compares local to live tables and only
# attributes content that matches. Requires live tables.
wp newspack-content-diff-migrator attribute-match-local-to-live-tables \
  --live-table-prefix=cdiff_ \
  --source-hostname=www.example.com \
  --data-dir=/tmp/attr_data

# 3. attribute-ids
# USE CASE: Edge case - custom migration was done separately and you need
# to attribute specific known IDs before running CDiff on that content.
wp newspack-content-diff-migrator attribute-ids \
  --source-hostname=www.example.com \
  --data-dir=/tmp/attr_data \
  --post-ids=123,456,789 \
  --user-ids=10,20,30
```

All three commands generate CSV reports in `{data-dir}/reports/`.

### Utility Commands

```bash
# List previously migrated source hostnames
wp newspack-content-diff-migrator list-previously-migrated-source-hostnames

# Compare collations between live and local tables
wp newspack-content-diff-migrator display-collations-comparison \
  --live-table-prefix=cdiff_ \
  --different-collations-only

# Fix collation mismatches
wp newspack-content-diff-migrator correct-collations-for-live-wp-tables \
  --live-table-prefix=cdiff_
```

### Interactive Mode

```bash
wp newspack-content-diff-migrator index
```

Launches guided menu for selecting commands and entering arguments.

## Newspack Migration Data Consistency Standard (MDCS)

Newspack Migration Data Consistency Standard (NMDCS) defines which fields trigger modification detection and which get updated on subsequent runs.

### Update strategies by object type

| Object Type | Detection | Update Method |
|-------------|-----------|---------------|
| Posts/CPTs | 6-field check | Full reimport (delete + reimport, preserves local ID) |
| Pages | Not checked | Import once only (first run) |
| Attachments | Field-by-field | Individual field updates |
| Users | Field-by-field | Individual field updates |
| Terms | Field-by-field | Individual field updates |

### Posts modification detection (6 checks in order)

| # | Field | Comparison |
|---|-------|------------|
| 1 | `post_modified` | Live timestamp must be newer |
| 2 | `post_status` | Direct comparison |
| 3 | `comment_count` | Direct comparison |
| 4 | `post_author` | Old vs new ID mapping |
| 5 | `_thumbnail_id` | Old vs new ID mapping |
| 6 | Taxonomies | Old vs new IDs mapping |

Pages and attachments are excluded from these checks.

### Fields that TRIGGER full reimport (posts)

- post_modified, post_status, comment_count, post_author, _thumbnail_id, taxonomies

### Fields detected INDIRECTLY (via post_modified bump)

- post_content, post_excerpt

### Fields NOT detected (changes ignored unless another field triggers)

- postmeta changes alone, comment changes alone

### Identifier fields (not scanned, only updated if reimport happens)

- title, slug, date published

### Attachments - fields updated directly

- Caption (`post_excerpt`)
- Alt text (`_wp_attachment_image_alt`)
- Description (`post_content`)
- Credit (`_media_credit`)
- Credit URL (`_media_credit_url`)

### Users - fields updated directly

- Email (`user_email`)
- Display name (`display_name`)
- Avatar (SLA attachment ID)

Users matched by `user_login`, not by old_id meta.

### Terms - fields updated directly

- Slug
- Description

Terms matched by name + taxonomy + parent, not by old_id meta.

### Source hostname meta - which objects REQUIRE it

| Object | Meta required for import decision? | Without meta |
|--------|-----------------------------------|--------------|
| Posts/Attachments | **YES** | Duplicate created |
| Users | No | Matched by user_login |
| Terms | No | Matched by name+taxonomy+parent |

## Run-state files

Located in `{data-dir}/run-state/`. Format is JSON or JSONL for resumability.

These files are not meant to be read by the use, they are used by the application logic to track the state of the migration, and to resume the migration from the last known state in case the migration is interrupted.

| File | Format | Purpose |
|------|--------|---------|
| `manifest.json` | JSON | Migration metadata, counts, timestamp |
| `new_ids.json` | JSON | IDs to be newly imported |
| `modified_ids.json` | JSON | Modified content mappings |
| `imported_posts.jsonl` | JSONL | Imported post records |
| `imported_users.jsonl` | JSONL | Imported user records |
| `imported_terms.jsonl` | JSONL | Imported term records |
| `updated_*.jsonl` | JSONL | Various update tracking |

Commands read these files on re-run to skip already-processed IDs. Do not delete run-state mid-migration.

## CSV Reports

Generated in `{data-dir}/reports/` at end of migration.

These are user friendly summaries of the migration.

| File | Columns |
|------|---------|
| `posts.csv` | status, post_type, id_old, id_new |
| `users.csv` | status, id_old, id_new |
| `terms.csv` | status, term_id_old, term_id_new, taxonomy |

Status values: `imported`, `modified`, `merged`

## Linting

```bash
vendor/bin/phpcs    # Check all files
vendor/bin/phpcbf   # Auto-fix
```

WordPress + VIP-Go + WordPress-Docs standards. Short array syntax `[]` allowed. PSR-4 filenames allowed.

## Testing

```bash
./bin/install-wp-tests.sh   # First-time setup
vendor/bin/phpunit          # Run tests
```

Tests use `WP_UnitTestCase`. CI runs tests on every push.

v2 has comprehensive test coverage (~600 tests, ~21k lines of test code):

- **Unit tests**: 369 tests across 5 test classes covering all Logic and Utils classes
- **Integration tests**: 230 tests across 18 test classes covering all commands, MDCS, multi-source, E2E scenarios
- **Fixtures**: Block HTML fixtures + live-table JSON fixtures for realistic test data

Key logic classes have 92-97% code coverage. The MDCS implementation alone has 47 dedicated integration tests.

## Gotchas

- **CLI-only**: Plugin returns early if `!defined('WP_CLI') || !WP_CLI`. No frontend code.
- **v2 command prefix**: `newspack-content-diff-migrator` (different from v1's `newspack-content-migrator` and from NCCM's prefix).
- **Source hostname required**: All migration/attribution commands require `--source-hostname`.
- **Classmap autoloading**: Run `composer dump-autoload` after adding/renaming files.
- **Memory ticker**: `PluginSetup::register_ticker()` kills process at 490MB (Atomic platform limit is 512MB).
- **Logger singleton**: Use `Logger::instance()->log(Logger::OUTPUT_BOTH, LogLevel::INFO, 'msg')`.
- **RunState expects --data-dir**: All commands use `--data-dir` for state persistence.
- **BlockUpdater callable**: `update_all_blocks_ids()` takes a callable for attachment URL resolution.
- **NCC dependency for blocks**: Block manipulation uses `WpBlockManipulator` and `HtmlElementManipulator` from NCC, NOT NMT.
- **Commands in COMMANDS const**: Add new commands to the `COMMANDS` array in `ContentDiffMigrator.php`.

## Recipes

### Add a new WP-CLI command

1. Add entry to `COMMANDS` constant array in `ContentDiffMigrator.php`:
   ```php
   'your-command' => [
       'callable' => 'cmd_your_command',
       'synopsis' => '...',
   ],
   ```
2. Implement method `cmd_your_command($args, $assoc_args)` (instance method)
3. Use `$this->logic->` for ContentDiffLogic, `$this->data_importer->` for DataImporter
4. Use `Logger::instance()->log(...)` for logging
5. Run `composer dump-autoload`

### Add logging to existing method

```php
use Newspack\ContentDiffMigrator\Utils\Logger;
use Psr\Log\LogLevel;

Logger::instance()->log(Logger::OUTPUT_BOTH, LogLevel::INFO, 'Your message');
```

### Add new Gutenberg block type to rewrite

1. Add method in `Logic/BlockUpdater.php` (e.g., `update_block_yourtype()`)
2. Call it from `update_all_blocks_ids()` method
3. Use `$this->block_manipulator` for parsing, `$this->get_new_attachment_url` callable for URL resolution

### Update NMT dependency

```bash
rm -rf vendor/automattic/newspack-migration-tools && \
composer update automattic/newspack-migration-tools && \
git add composer.lock && \
git commit -m 'Updating NMT composer pointer'
```

## Directory structure

```
newspack-content-diff-migrator/
├── newspack-content-diff-migrator.php  # Entry point, CLI-only guard
├── composer.json                        # classmap autoload, deps: NMT, NCC, Monolog
├── phpcs.xml                            # WPCS + VIP-Go + WordPress-Docs
├── src/
│   ├── Command/
│   │   ├── ContentDiffMigrator.php      # CLI commands (COMMANDS const)
│   │   └── ContentDiffMigratorIndex.php # Interactive selector
│   ├── Logic/
│   │   ├── ContentDiffLogic.php         # Core diff logic
│   │   ├── DataImporter.php             # Import operations
│   │   ├── BlockUpdater.php             # Block ID updates (10 types)
│   │   └── RunState.php                 # JSONL state management
│   ├── Utils/
│   │   ├── Logger.php                   # Singleton Monolog wrapper
│   │   ├── DB.php                       # Collation utils
│   │   ├── Progress.php                 # Progress tracking
│   │   ├── ReportCreator.php            # CSV reports
│   │   └── SLAHelper.php                # Avatar helper
│   └── PluginSetup.php                  # Memory ticker, error reporting
└── tests/
    └── ...
```

## Before submitting

1. `vendor/bin/phpcs` – no PHPCS errors
2. `composer dump-autoload` – if files added/renamed
3. Test commands with `--help` to verify synopsis
