# Newspack Content Diff Migrator

This plugin is a content migration tool that migrates the content differential from one or more remote WordPress sites on top of the destination site while keeping the existing local content intact.

## Overview

The Newspack Content Diff Migrator is designed to synchronize content between remote sites (also addressed as "live site", after Newspack's own migration workflow) and a local site (also addressed as "staging site") by importing only the new or modified content from the remote site. This is particularly useful for maintaining staging environments that need to stay current with production content without overwriting staging-specific changes.

The plugin migrates all the database content, while files synchronization should be done additionally.

## Features

- **Incremental "diff" Migration**: Can be run multiple times to migrate the entire new and modified content differential, and the subsequent migrations will resume from the last successful step
- **Preserves Local Content**: Keeps existing local content intact during migration
- **Multi-Source Migration**: Supports importing content from multiple source hostnames, each with its own database tables and content, all the while preserving the integrity of the local content
- **Selective Import**: Only imports new or modified content from the live site
- **Content Coverage**: Handles posts, pages, attachments, users, comments, and basic taxonomies (category,post_tag,author) as well as custom taxonomies and custom post types, all the while preserving the integrity of the local content
- **Error Handling, Logging and Graceful Degradation**: Comprehensive error logging and recovery mechanisms, provides detailed logging for troubleshooting, and continues processing even when individual items fail
- **Side-by-side Tables**: Works with remote site's database tables temporarily imported alongside local tables with a different table prefix

## Installation

Use latest release from the [Newspack Plugins Repository](https://github.com/newspack-archive/newspack-plugins/releases), or install from repository:

1. Clone or download the plugin to your WordPress plugins directory
2. Navigate to the plugin directory
3. Run `composer install` to install dependencies

## Usage

### Interactive Mode (Easiest)

New to the plugin or prefer a guided interactive mode? Use the `index` command:

```bash
wp newspack-content-diff-migrator index
```

This launches an interactive menu that guides you through selecting any of the available commands, with their descriptions, and prompts you for all required arguments step-by-step. Perfect for learning the plugin or running commands without memorizing syntax.

### Quick Start

1. **Import Live Tables**: Import live site database tables with a specific prefix (e.g., `cdiff_`)
2. **Attribute Existing Content** (optional): If local site already contains some content from source(s) (e.g. cloned from live)), see [Attribution Commands](#attribution-commands) to attribute it to the source hostname before migrating. Needs to be run only once per source to attribute existing content, and it lets the plugin know that this existing local content came from these source hostnames, so it can properly match it to that site and properlycompare during subsequent migrations.
3. **Run Migration** (two commands, run in sequence):
```bash
# Step 1: Search for new/modified content
wp newspack-content-diff-migrator search-new-content-on-live \
    --live-table-prefix=cdiff_ \
    --source-hostname=www.example-1.com \
    --data-dir=/tmp/cdiff_data

# Step 2: Migrate the identified content
wp newspack-content-diff-migrator migrate-live-content \
    --live-table-prefix=cdiff_ \
    --source-hostname=www.example-1.com \
    --data-dir=/tmp/cdiff_data
```

---

## Full Commands Reference

### Migration Commands

The two migration commands **must be executed in sequence**: first `search-new-content-on-live`, then `migrate-live-content`.

#### `search-new-content-on-live`

Searches for new and modified posts in the live site tables and notes the IDs which should be migrated. This command must be run before `migrate-live-content`.

**How the search command works:**

1. **Auto-attribution**: Before checking for new/modified content, the command scans for unattributed local content (content without `newspackcontentdiff_oldid_*` metas) and automatically matches it against live tables using ID fields comparison (like title, slug, date, type, etc.). This handles scenarios like cloned sites where content exists locally but hasn't been attributed yet. It will prompt the user to attribute the remaining unattributed content to the source hostname, or continue without attribution.

2. **New content detection**: The command determines "new" content by checking if each live content object is referenced in the local `newspackcontentdiff_oldid_*` metas. If a live ID is not in this local meta mapping, it's considered new and queued for import.

3. **Modified content detection**: Following the Newspack Migration Data Consistency Standard, specific fields are examined for changes (like post_modified date, post_status, post_author, featured image, and taxonomies). If any of these fields have changed, the content is considered modified and queued for update (either full reimport for posts, or individual field updates, depending on the object type).

```bash
wp newspack-content-diff-migrator search-new-content-on-live \
    --live-table-prefix=<prefix> \          // Prefix of the imported live site tables (e.g., `cdiff_`)
    --source-hostname=<hostname> \          // e.g. www.example-1.com
    --data-dir=<path> \                     // Directory to store migration run-state data and logs
    [--post-types-csv]                      // Optionally include `guest-author` for CAP's Guest Authors.
```

#### `migrate-live-content`

Imports the content differential identified by `search-new-content-on-live`. Must be run after the search command.

```bash
wp newspack-content-diff-migrator migrate-live-content \
    --live-table-prefix=<prefix> \    // Prefix of the imported live site tables (e.g., `cdiff_`)
    --source-hostname=<hostname> \    // e.g. www.example-1.com
    --data-dir=<path> \               // Same directory used in the search command
    [--custom-taxonomies-csv]         // List of optional custom taxonomies to migrate.
```
> **⚠️ Important:**

- Always run `search-new-content-on-live` before `migrate-live-content` for each migration cycle, it's a prerequisite -- unless when resuming a previously interrupted migration, then re-run the migrate command which has been interrupted using that same `--data-dir`
- Always use the **same** `--data-dir` for search and migrate commands in the same migration cycle, it's used to store the migration run-state data and logs. See recommendation below
- Always use a **new** `--data-dir` for a new migration cycle, to keep separate records and preserve previous logs for debugging or resuming a previous migration which was interrupted

---

### Utility Commands

#### `list-previously-migrated-source-hostnames`

Lists all previously migrated source hostnames. Useful for checking what sources have already been migrated.

```bash
wp newspack-content-diff-migrator list-previously-migrated-source-hostnames
```

### Attribution

When you run a CDiff migration, the imported content gets the "old ID and hostname meta" assigned to it. The following WP data objects can have this meta assigned to them, stored in the corresponding WP metas tables:
- Posts/Pages/Attachments/CPTs (stored in `wp_postmeta`)
- Users (stored in `wp_usermeta`)
- Terms (stored in `wp_termmeta`)

To **"attribute content to a source hostname"** simply means to assign "old ID and hostname metas" to that content -- so that CDiff can track that content to the original source hostname, properly compare it to live tables, and do content refreshes. These metas are what makes multi-source migrations work.

#### Automatic Attribution

The `search-new-content-on-live` command now **automatically attributes** matching content when it detects unattributed local content:

1. **Checks for unattributed content** - Content without source hostname meta
2. **Auto-matches to live tables** - Compares local content against live DB tables
3. **Attributes matches** - Assigns source hostname meta to matching content
4. **Warns about remaining** - If content couldn't be matched, prompts whether to continue

This eliminates the need to manually run attribution commands before searching in most scenarios. The auto-attribution matches content using:
- **Posts/CPTs**: Matched by title + slug + date + type
- **Attachments**: Matched by title + slug + date
- **Users**: Matched by user_login
- **Terms**: Matched by slug + taxonomy

#### How Source Hostname Metas Work Per Type of Object

Some object types rely on the source hostname meta to be properly compared/cdiff-ed against the live tables, and this meta does affect whether they're being imported, while some other objects have it purely for tracking of origin purpose.

| Entity | Meta Table | Meta used for import decision? | What happens WITHOUT the meta? |
|--------|------------|---------------------------|---------------------------|
| **Posts** (all CPTs) | `wp_postmeta` | **YES** | Object is considered "new" → **DUPLICATE CREATED** |
| **Attachments** | `wp_postmeta` | **YES** | Object is considered "new" → **DUPLICATE CREATED** |
| **Users** | `wp_usermeta` | No | Matched by `user_login` → merged/reused existing object |
| **Terms** | `wp_termmeta` | No | Matched by name+taxonomy+parent → merged/reused existing object |

**Posts and Attachments** — The source hostname meta is **critical** for these. During migration, the plugin builds a mapping of `live_id => local_id` from the metas. If a live post's ID is not in this map, it's considered "new" and will be imported — potentially creating a duplicate if that content already exists locally but wasn't attributed.

**Users** — The meta is for tracking/mapping only. User lookup during import is done by `user_login` match, not by meta, and if a user with the same login exists locally, it will be reused regardless of whether it has the source hostname meta (with an appropriate warning in the log), while the meta is added for internal reference.

**Terms (Categories, Tags, etc.)** — The meta is for tracking/mapping only. Term lookup during import is done by **name + taxonomy + parent**, not by meta. And if a term with the same name exists with the same taxonomy, and under the same parent, it will be reused regardless of whether it has the source hostname meta, while the meta is added for internal reference.

#### Manual Attribution: `attribute-ids`

Manual attribution is very much an edge case, made for special ops, and "just in case" scenarios where automatic attribution won't work.

**Use case**: Content from a source hostname was migrated with different parallel migration tools. For example, the first part of content was migrated by an external tool (Ghost CMS migrator, WP Importer, etc.) which transformed the content identifiers (different slugs, dates, etc.), and then for some reason you need to use the CDiff to migrate the second part of the content. If you have the ID mappings from the external tool (old IDs => new IDs), in order to use CDiff on the second part, you need to "attribute" i.e. set "old IDs and source hostname" metas to the first part of the content, so that CDiff doesn't create duplicates.

This command takes actual old IDs and new IDs, and simply assigns/attributes the "old ID and source hostname meta", enabling CDiff to properly compare and migrate the remaining content.

**How it works:**

Once metas are set by this command, CDiff relies solely on those metas to determine what's "already migrated" vs "new" — it does **not** re-compare content fields (title, slug, date). This is intentional: external migration tools may have transformed those fields, making field-based matching impossible. The metas become the authoritative link between local and live content.

**Arguments** At least one of these arguments is required:
- `--source-hostname` (required): Source hostname (e.g., www.example.com)
- `--data-dir` (required): Data directory for logs and reports
- `--post-ids` (optional): Path to JSONL file with post ID pairs
- `--attachment-ids` (optional): Path to JSONL file with attachment ID pairs
- `--user-ids` (optional): Path to JSONL file with user ID pairs
- `--term-ids` (optional): Path to JSONL file with term ID pairs

```bash
wp newspack-content-diff-migrator attribute-ids \
    --source-hostname=www.example.com \
    --data-dir=/tmp/migration_data \
    --post-ids=/tmp/post_ids.jsonl \
    --user-ids=/tmp/user_ids.jsonl
```

---

### Database Utility Commands

These commands help diagnose and equalize database differences between live and local tables.

#### `display-collations-comparison`

Displays a comparison table of collations between live and local WordPress tables. Useful for diagnosing character encoding issues.

```bash
wp newspack-content-diff-migrator display-collations-comparison \
    --live-table-prefix=<prefix> \
    [--skip-tables=<csv>] \
    [--different-collations-only]
```

#### `correct-collations-for-live-wp-tables`

Automatically fixes collation mismatches between live and local tables. Speed is auto-determined based on total table size.

```bash
wp newspack-content-diff-migrator correct-collations-for-live-wp-tables \
    --live-table-prefix=<prefix> \
    [--skip-tables=<csv>]
```

> **Note:** The migration commands (search and migrate) automatically run collation fixes when needed, so you typically don't need to run this manually.

---

## Reports

At the end of each migration run, CSV reports are created in the `reports/` subfolder within your `--data-dir`. These CSVs are human-friendly summaries of all the key migration activity, and they are derived from (duplicated from) the run-state JSONL files for your easy review.

### Generated Reports

| File | Description | Columns |
|------|-------------|---------|
| `reports/posts.csv` | All migrated or reimported posts, pages, attachments, and custom post types | `status`, `post_type`, `id_old`, `id_new` |
| `reports/users.csv` | All imported, merged, or modified users | `status`, `id_old`, `id_new` |
| `reports/terms.csv` | All imported or merged terms (categories, tags, custom taxonomies) | `status`, `term_id_old`, `term_id_new`, `taxonomy` |

`status` column possible values:

- **imported**: Record was newly created during this migration
- **modified**: Record was deleted and reimported, or had fields updated
- **merged**: Record with the same unique identifier already existed locally and was merged/reused

Tracked in the reports:

- Posts (all types) that were imported or reimported
- Attachments that were imported or had MDCS field updates
- Users that were imported, merged, or had MDCS field updates
- Terms that were imported, merged, or had MDCS field updates

Not tracked in the reports:

- Post parent ID corrections (internal plumbing, not content changes)
- Featured image ID corrections (internal plumbing)
- Block attachment ID corrections (internal plumbing)
- Comments and comment metadata

> **Note:** The ID correction operations (post parent, featured image, block attachment IDs) process all previously imported content from the source hostname, not just the current batch. This serves as a self-healing mechanism if an attachment import failed in a previous run, but succeeds in a later run, all posts that reference that attachment will have their IDs correctly updated.

---

## Using the Data Directory (`--data-dir`)

The `--data-dir` parameter stores logs and run-state data in a `run-state` subfolder (e.g., `/tmp/cdiff_data/run-state/`).

The run-state data includes:
- **`manifest.json`** — Migration summary with
  - timestamp
  - source hostname
  - post types
  - counts of new/modified IDs
  - Useful for quickly reviewing what was migrated.
- **`new_ids.json`** / **`modified_ids.json`** — IDs to be imported or reimported
- **`imported_posts.jsonl`**, **`updated_*.jsonl`** — Progress tracking files for resume capability

### Resuming an Interrupted Migration

If a migration command is interrupted (e.g., timeout, crash), simply run the same command again with the **same `--data-dir`**. The migration will pick up from where it left off.

### Starting a New Migration Cycle

Once a migration completes successfully, you can start a fresh migration cycle at any time. **Use a new `--data-dir`** to keep separate records and preserve previous logs for debugging.

If you attempt to run `search-new-content-on-live` with a `--data-dir` that contains existing run-state files, the command will exit with a message to use a new `--data-dir` to protect your previous migration data and logs (useful for debugging or resuming a previous migration which was interrupted).

## Migration Data Consistency Standard

The Migration Data Consistency Standard (see [internal P2](https://newspackp2.wordpress.com/2025/09/24/migration-data-consistency-standard/) for more details defines how new and modified content is handled, which fields get updated on subsequent migration runs and which fields are ignored.

It contains specific filtering rules, which are optimal for Newspack's own migration workflow, and ensures that changes made on the live site are reflected on the local site with a curated set of rules.

### How It Works

The plugin uses two complementary update strategies internally, depending on the object type:

| Object Type | Detection | Update Method |
|-------------|-----------|---------------|
| **Posts** | Checked on each run | Full reimport (delete + reimport) |
| **Custom Post Types** | Same fields as posts | Full reimport (delete + reimport) |
| **Pages** | Not checked | Import once only (first run) |
| **Attachments** | Checked on each run | Individual field updates |
| **Users** | Checked on each run | Individual field updates |
| **Terms** (categories, tags) | Checked on each run | Individual field updates |

### How Modification Detection Works

During the `search-new-content-on-live` command, each object type is checked for modifications according to the Migration Data Consistency Standard. Understanding this logic may be important for debugging why a particular object was (or wasn't) flagged as modified.

#### Posts and all Custom Post Types

The search command runs **6 checks in order** for each previously imported post. Once one fo these checks triggers, the post is marked as modified and will be deleted and reimported (with same existing local ID, to preserve any references to that reimported local post ID).

| # | Field | Comparison |
|---|-------|------------|
| 1 | `post_modified` | Local and live values directly compared |
| 2 | `post_status` | Local and live values directly compared |
| 3 | `comment_count` | Local and live values directly compared |
| 4 | `post_author` | Old VS New ID mapping is compared to detect a change |
| 5 | `_thumbnail_id` | Old ID VS New ID mapping is compared to detect a change |
| 6 | Taxonomies | Old IDs VS New IDs mapping is compared to detect any changes |

Pages and attachments are **excluded** from these modification checks as per the Migration Data Consistency Standard.

Note that locally added terms (such as Newspack Brands assigned during Newspackification) will not trigger the modification detection, thanks to the fact that they don't have a `newspackcontentdiff_oldid_{hostname}` termmeta. Such local terms without the metas are skipped during the taxonomy comparison to allow for some Newspack customizations to posts in migration.

#### Users

All users are processed on every migration run, and modification is detected by comparing:

- **Email** — live VS local `user_email`
- **Display name** — live VS local `display_name`
- **Avatar** — Simple Local Avatars attachment ID (Old VS New ID mapping is compared to detect a change)

Users are uniquely matched by `user_login` -- not by old_id meta. If any of the above fields differ, the user is marked as modified and those changed fields get updated individually/directly (not by a full reimport, only posts are reimported this way).

#### Attachments

Attachments are not checked for modification in the same way as posts. Instead, specific fields are compared individually and updated directly:

- **Caption** (`post_excerpt`)
- **Alt text** (`_wp_attachment_image_alt`)
- **Description** (`post_content`)
- **Credit** (`_media_credit`)
- **Credit URL** (`_media_credit_url`)

Each field is compared independently, and only the fields that actually differ are updated.

#### Terms (Categories, Tags, Custom Taxonomies)

Terms are matched by **name + taxonomy + parent** (not by old_id meta). Specific fields are compared and updated individually:

- **Slug** — updated if different
- **Description** — updated if different

Name and parent are identifier fields and are not updated.

### Field-by-Field Specification

#### Posts

The following fields are **directly scanned** for changes (see [check order above](#posts-and-custom-post-types)) and a change on any of these fields triggers a full post reimport ("modified" post is deleted and reimported):

- **Date modified** — compared directly (`post_modified`), and live timestamp must be newer
- **Status** — compared directly (`post_status`)
- **Comment count** — compared directly (`comment_count`)
- **Author** — local `post_author` (Old VS New ID mapping is compared to detect a change)
- **Featured image** — local `_thumbnail_id` (Old VS New ID mapping is compared to detect a change)
- **Category/tags/taxonomies** — local term IDs (Old IDs VS New IDs mapping is compared to detect any changes), which also covers changes in 'author' (Guest Author) taxonomy term changes

The following fields are **not scanned directly**, but changes to them will bump `post_modified` (when edited through Gutenberg), which triggers the post_modified check above and causes a full reimport:

- **Content** — detected indirectly via `post_modified` change
- **Excerpt** — detected indirectly via `post_modified` change

The following fields are **not detected** (changes on live will not trigger reimport, unless another scanned field also changed):

- **Postmeta**
- **Comments**

The following are **identifier fields** — changes to these fields are ignored unless another field triggers reimport:

- **Title** — not scanned; only updated if post is reimported
- **Slug** — not scanned; only updated if post is reimported
- **Date published** — not scanned; only updated if post is reimported

When any directly-scanned field has changed, or when `post_modified` is newer on live, the post is marked as **modified** and will be deleted and fully reimported. Such a full reimport **preserves its local `wp_posts.ID`** to ensure any references to the reimported local post ID remain valid.

The directly-scanned fields for author, featured image, and taxonomies exist because WordPress does NOT update `post_modified` when these are changed in Gutenberg — so they must be checked independently.

#### Custom Post Types

Same rules as posts.

#### Pages

- Pages are migrated only once during the first migration run
- Changes to page fields on live do not get updated on local
- New pages are **not** imported during consecutive migration runs (this specifically serves Newspack's own migration workflow best)

#### Categories

- **Name** — does not get updated (identifier field)
- **Parent** — does not get updated (identifier field)
- **Slug** — gets updated directly
- **Description** — gets updated directly

#### Custom Taxonomies (Hierarchical)

Same rules as categories.

#### Post Tags

- **Name** — does not get updated (identifier field)
- **Slug** — gets updated directly
- **Description** — gets updated directly

#### Custom Taxonomies (Non-Hierarchical)

Same rules as post tags.

#### Users

All users get migrated fully during every migration run (to migrate subscribers and subscription data).

- **Username/login** — identifier field; if changed, a new user gets inserted (original user remains)
- **Email** — gets updated directly
- **Display name** — gets updated directly
- **Avatar (Simple Local Avatars)** — gets updated directly

#### Attachments

- **Caption** — gets updated directly (`post_excerpt`)
- **Alt text** — gets updated directly (`_wp_attachment_image_alt`)
- **Description** — gets updated directly (`post_content`)
- **Credit** — gets updated directly (`_media_credit`)
- **Credit URL** — gets updated directly (`_media_credit_url`)

**Why Full Reimport?**

Modified posts use a "full reimport" strategy: the local post is deleted and then reimported fresh from the live site.

However this reimport of the modified post **preserves its local `wp_posts.ID`**. The post gets reimported with the same ID, ensuring any external references to the reimported local post ID remain valid. The original live ID is also preserved in the `newspackcontentdiff_oldid_{hostname}` postmeta for mapping.

This approach elegantly handles the complexity of post updates:

- **Block content**: Attachment IDs embedded in Gutenberg blocks are automatically updated to local IDs
- **Featured images**: Thumbnail references are properly mapped to local attachment IDs
- **Taxonomies**: All term relationships are reimported fresh
- **Postmeta**: All post metadata is synchronized (note: postmeta changes do not trigger reimport)
- **Comments**: All comments and comment metadata are reimported (note: comment-only changes do not trigger reimport)
- **Parent references**: Post parent IDs are updated to local IDs

This single operation ensures all related data is consistent, rather than attempting to diff and update individual fields which could miss embedded ID references in content. Additionally it cause no performance overhead compared to the alternative of updating individual fields which could miss embedded ID references in content.

### Multi-Source Merged Entities

When importing from multiple sources, certain entities with the same unique identifiers get **merged** into a single local entity rather than creating duplicates. This is by design and handled gracefully without crashes.

**Fields that cause merging:**

| Entity | Unique Field(s) | Merge Behavior |
|--------|----------------|----------------|
| **Users** | `user_login` | Same username from different sources → merged to one user |
| **Categories** | `name` + `parent` | Same category name under same parent → merged to one |
| **Tags** | `name` | Same tag name → merged to one |

**What happens when entities are merged:**

1. The first import creates the entity (user, term) with the source's old_id meta
2. Subsequent imports from other sources find the existing entity and:
   - Add their own source-specific old_id meta (e.g., `newspackcontentdiff_oldid_www.source-b.com`), and so these records will have multiple old_id metas — one per contributing source
   - Log a WARNING (once per source per entity) noting the merge, so you can review the merged entities and their old_id metas
3. The result is a single entity with multiple old_id metas — one per contributing source

**Example:** If `www.source-a.com` and `www.source-b.com` both have a user "admin", after importing both:
- One local "admin" user exists
- That user has TWO old_id metas:
  - `newspackcontentdiff_oldid_www.source-a.com` = 123
  - `newspackcontentdiff_oldid_www.source-b.com` = 456

**Entities that are NOT merged (always create new):**
- **Posts** — different sources = different posts (even with same title/slug)
- **Attachments** — different sources = different attachments (even with same filename)

## Best Practices

1. **Backup First**: Always backup your local staging site before running migrations
2. **Monitor Logs**: Check log files for any issues or warnings

## Development

### Code Standards

Run `./vendor/bin/phpcs --standard=phpcs.xml {File}` to check for coding standards issues.
Run `./vendor/bin/phpcbf --standard=phpcs.xml {File}` to apply automatic fixes.

### Working with the NMT dependency (newspack-migration-tools)

This plugin points to the [Newspack Migration Tools](https://github.com/Automattic/newspack-migration-tools) `dev-trunk` branch. Whenever newer code has been merged to trunk in the NMT, run `composer update automattic/newspack-migration-tools` to update the lockfile and get the latest from the NMT. If nothing happens when you update, then run `composer clear-cache` and try again.

Here is a one-liner (well – there are multiple lines for readability) that is safe to use even if you have the NMT symlinked into the `vendor` directory. From your PR's branch run:

```bash
rm -rf vendor/automattic/newspack-migration-tools && \
composer update automattic/newspack-migration-tools && \
git add composer.lock && \
git commit -m 'Updating NMT composer pointer' && \
git push origin $(git symbolic-ref --short HEAD)
```

## Troubleshooting and Common Issues

- Memory Exhaustion: Increase PHP memory limits or reduce batch size
- CLI Timeout Issues: Consider running migrations in smaller batches, or simply rerun to resume the migration from the last successful step
- Check the log files in the `--data-dir` directory
- Review the error log for specific error messages

## Multiple Source Hostnames

Importing content from multiple source hostnames is fully supported. Content of each site is treated independently from the other, and the plugin will not overwrite or merge any content from other sites.

Each source is identified by a unique `--source-hostname` parameter that must be provided to all commands.

### Workflow for Multiple Sources

```bash
# Import from Site A
wp newspack-content-diff-migrator search-new-content-on-live \
    --live-table-prefix=cdiff_ --source-hostname=www.example-1.com --data-dir=/tmp/cdiff_eg1
wp newspack-content-diff-migrator migrate-live-content \
    --live-table-prefix=cdiff_ --source-hostname=www.example-1.com --data-dir=/tmp/cdiff_eg1

# Import from Site B
wp newspack-content-diff-migrator search-new-content-on-live \
    --live-table-prefix=eg2_ --source-hostname=www.example-2.com --data-dir=/tmp/cdiff_eg2
wp newspack-content-diff-migrator migrate-live-content \
    --live-table-prefix=eg2_ --source-hostname=www.example-2.com --data-dir=/tmp/cdiff_eg2
```

Use `list-previously-migrated-source-hostnames` to see which sources have been previously migrated.

### Attributing Cloned Content

If your local site was cloned from the live site, you must first attribute that existing local content to the source hostname before running the content diff migration. See [Attribution Commands](#attribution-commands) for details.

---

## License

This plugin is part of the Newspack ecosystem and follows the same licensing terms as other Newspack plugins.

## Disclaimer

This plugin is provided as-is without any warranty or support. Use at your own risk. The authors and contributors are not responsible for any data loss or any kind of damage caused by the use of this plugin.
