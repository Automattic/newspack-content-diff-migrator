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

### Quick Start

1. **Import Live Tables**: Import live site database tables with a specific prefix (e.g., `cdiff_`)
2. **Attribute Existing Content** (e.g. if local site was cloned from source): see [`attribute-existing-content-to-hostname`](#attribute-existing-content-to-hostname) command for details, nedds to be run only once to attribute the existing content to the source hostname so that the migrator knows it should be matched/compared against your source site's content
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

```bash
wp newspack-content-diff-migrator search-new-content-on-live \
    --live-table-prefix=<prefix> \          // Prefix of the imported live site tables (e.g., `cdiff_`)
    --source-hostname=<hostname> \          // e.g. www.example-1.com
    --data-dir=<path> \                     // Directory to store migration run-state data and logs
    [--post-types-csv=post,page,attachment] // Defaults: `post,page,attachment`. Optionally include `guest-author` for CAP's Guest Authors.
```

#### `migrate-live-content`

Imports the content differential identified by `search-new-content-on-live`. Must be run after the search command.

```bash
wp newspack-content-diff-migrator migrate-live-content \
    --live-table-prefix=<prefix> \    // Prefix of the imported live site tables (e.g., `cdiff_`)
    --source-hostname=<hostname> \    // e.g. www.example-1.com
    --data-dir=<path> \               // Same directory used in the search command
    [--custom-taxonomies-csv=]        // Defaults: `category,post_tag,author`.
```
> **⚠️ Important:**

- Always run `search-new-content-on-live` before `migrate-live-content` for each migration cycle, it's a prerequisite -- unless when resuming a previously interrupted migration, then re-run the migrate command which has been interrupted using that same `--data-dir`
- Always use the **same** `--data-dir` for search and migrate commands in the same migration cycle, it's used to store the migration run-state data and logs. See recommendation below
- Always use a **new** `--data-dir` for a new migration cycle, to keep separate records and preserve previous logs for debugging or resuming a previous migration which was interrupted

---

### Helper Commands

#### `list-previously-migrated-source-hostnames`

Lists all previously migrated source hostnames. Useful for checking what sources have already been migrated.

```bash
wp newspack-content-diff-migrator list-previously-migrated-source-hostnames
```

#### `attribute-existing-content-to-hostname`

If the local site already contains some of the live site's content (for example, if local site was cloned from source), you should first attribute the existing content to a source hostname before running migrations.

This command compares local content with live DB tables and adds source-specific metadata (`newspackcontentdiff_oldid_{hostname}`) to matched objects. This lets the plugin know that the existing local content came from this specific source hostname, so it can be properly matched and compared during subsequent migrations.

```bash
wp newspack-content-diff-migrator attribute-existing-content-to-hostname \
    --live-table-prefix=<prefix> \
    --source-hostname=<hostname> \
    [--post-types-csv=post,page,attachment,...]
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

### Field-by-Field Specification

#### Posts

The following fields are **directly scanned** for changes and update on these fields triggers a full post reimport ("modified" post is deleted and reimported):

- **Date modified** — compared directly (`post_modified`)
- **Status** — compared directly (`post_status`)
- **Author** — compared directly (`post_author`)
- **Featured image** — compared directly (`_thumbnail_id` postmeta)
- **Category/tags/taxonomies** — compared directly (`term_relationships`)

The following fields are **not scanned directly**, but changes to `post_modified` will update the entire post:

- **Content** — detected indirectly via `post_modified` change
- **Excerpt** — detected indirectly via `post_modified` change

The following fields are **not detected directly** (changes on live will not trigger reimport, unless another field such as `post_modified` triggers reimport):

- **Postmeta**
- **Comments**

The following are **identifier fields** — changes to these fields are ignored unless another field triggers reimport:

- **Title** — not scanned; only updated if post is reimported
- **Slug** — not scanned; only updated if post is reimported
- **Date published** — not scanned; only updated if post is reimported

When any directly-scanned field has changed, or when `post_modified` is newer on live, the post is marked as **modified** and will be deleted and fully reimported. Such a full reimport **preserves its local `wp_posts.ID`** to ensure any references to the reimported local post ID remain valid.

Fields such as `post_content`, `post_excerpt`, `postmeta`, etc. are detected indirectly via `post_modified` change. Fields listed above as **directly scanned** fields are updated individually on the local post -- when you change a post's **categories/tags**, WordPress does NOT update `post_modified`.

What updates `post_modified` from **Gutenberg**:

- Post content, title, excerpt
- Post status, date
- Featured image, parent page

What does NOT update `post_modified` from **Gutenberg**:

- Categories, tags, custom taxonomies
- Post meta (custom fields)
- Comments

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

If your local site was cloned from the live site, you must first attribute that existing local content to the source hostname before running the content diff migration. See [`attribute-existing-content-to-hostname`](#attribute-existing-content-to-hostname) for details.

---

## License

This plugin is part of the Newspack ecosystem and follows the same licensing terms as other Newspack plugins.

## Disclaimer

This plugin is provided as-is without any warranty or support. Use at your own risk. The authors and contributors are not responsible for any data loss or any kind of damage caused by the use of this plugin.
