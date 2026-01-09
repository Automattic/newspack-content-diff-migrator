# Newspack Content Diff Migrator

**Version 2.0.0** — See [Legacy Migration](#legacy-migration-pre-multiple-source-installs) for upgrade instructions from v1.x.

This plugin is a content migration tool that migrates the content differential from one or more remote sites on top of your local site while keeping the existing local content intact.

## Overview

The Newspack Content Diff Migrator is designed to synchronize content between a remote site (also addressed as "live site", after Newspack's own migration workflow) and a local site (also addressed as "staging site") by importing only the new or modified content from the remote site. This is particularly useful for maintaining staging environments that need to stay current with production content without overwriting staging-specific changes.

It migrates all the database content, and files synchronization should be done additionally.

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
2. **Attribute Existing Content**: if the local site already contains some of the live site's content (for example, if local site was cloned from source), first attribute the existing content to the source hostname. This lets the plugin know that the existing local content came from this specific source hostname, and that it should be matched/compared agains the existing live content and properly import the newest differences:
```bash
wp newspack-content-diff-migrator attribute-existing-content-to-hostname \
    --live-table-prefix=cdiff_ \
    --source-hostname=www.example-1.com
```
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

If the local site already contains some of the live site's content (for example, if local site was cloned from source), first attribute the existing content to the source hostname. This lets the plugin know that the existing local content came from this specific source hostname, and that it should be matched/compared agains the existing live content and properly import the newest differences

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

The `--data-dir` parameter stores a full log, and run-state data in a `run-state` subfolder (e.g., `/tmp/cdiff_data/run-state/`). 

The RunState data contains information about migration progress and the IDs of content that has been migrated, enabling resume capability if a migration gets interrupted.

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

**Legend:**
- `=` marks **identifier fields** — once imported (old_id meta exists), changes to these fields are ignored
- `-` marks **updateable fields** — changes trigger either reimport or direct update

```
- posts
    = changed title => does not get updated (identifier field; once imported via old_id meta, changes are ignored)
    = changed slug => does not get updated (identifier field; once imported via old_id meta, changes are ignored)
    = changed date published => does not get updated (identifier field; once imported via old_id meta, changes are ignored)
    - changed date modified => existing post gets fully reimported (detected by comparing post_modified timestamps)
    - changed status => existing post gets fully reimported (detected by comparing post_status values)
    - changed post_content => existing post gets fully reimported (detected via post_modified change)
    - changed post_excerpt => existing post gets fully reimported (detected via post_modified change)
    - changed category => existing post gets fully reimported (detected by comparing term_relationships)
    - changed author => existing post gets fully reimported (detected by comparing post_author)
    - changed featured image => existing post gets fully reimported (detected by comparing _thumbnail_id)
    - postmeta changes => does NOT get updated on existing post, UNLESS post_modified triggers full reimport
- custom post_types
    - ... same rules as posts
- pages
    - pages are migrated only once during the first migration run
    - if any page fields get updated on live, they do not get updated on local
    - new pages do NOT get migrated during consecutive migration runs
- categories
    = changed name => does not get updated (identifier field; once imported via old_id termmeta, changes are ignored)
    = changed parent => does not get updated (identifier field; once imported via old_id termmeta, changes are ignored)
    - changed slug => existing category gets updated (direct update via update_modified_terms)
    - changed description => existing category gets updated (direct update via update_modified_terms)
- custom taxonomies, hierarchical
    - ... same rules as categories
- post_tags
    = changed name => does not get updated (identifier field; once imported via old_id termmeta, changes are ignored)
    - changed slug => existing tag gets updated (direct update via update_modified_terms)
    - changed description => existing tag gets updated (direct update via update_modified_terms)
- custom taxonomies, non-hierarchical
    - ... same rules as post_tag
- users
    - all users get migrated fully during every migration run (in order to migrate subscribers and subscription data), as well as authors of records given here
    = changed username/login => existing user not updated; new user gets inserted via migrate_all_users()
      (identifier field; the post author remains pointing to original user)
    - changed email => existing user gets updated (direct update via update_modified_users)
    - changed display name => existing user gets updated (direct update via update_modified_users)
    - changed avatar => existing user gets updated (direct update via update_modified_users)
- attachments
    - changed caption => existing attachment gets updated (direct update via update_modified_attachments on post_excerpt)
    - changed alt text => existing attachment gets updated (direct update via update_modified_attachments on _wp_attachment_image_alt)
    - changed description => existing attachment gets updated (direct update via update_modified_attachments on post_content)
    - changed credit => existing attachment gets updated (direct update via update_modified_attachments on _media_credit)
    - changed credit URL => existing attachment gets updated (direct update via update_modified_attachments on _media_credit_url)
```

### Posts and Custom Post Types

When a post or CPT has already been migrated, the search command checks if any of the following fields have changed on live. If so, the post is marked as **modified** and will be fully reimported:

- `post_modified` date
- `post_status`
- `post_author`
- Featured image (`_thumbnail_id`)
- Taxonomy term relationships (categories, tags, and custom taxonomies) — includes **CoAuthors Plus co-author** assignments

> **Why Full Reimport?**
>
> Modified posts use a "full reimport" strategy: the local post is deleted and then reimported fresh from the live site.

However this reimport of the modified post **preserves its local `wp_posts.ID`**. The post gets reimported with the same ID, ensuring any external references to the reimported local post ID remain valid. The original live ID is also preserved in the `newspackcontentdiff_oldid_{hostname}` postmeta for mapping.

This approach elegantly handles the complexity of post updates:
>
> - **Block content**: Attachment IDs embedded in Gutenberg blocks are automatically updated to local IDs
> - **Featured images**: Thumbnail references are properly mapped to local attachment IDs
> - **Taxonomies**: All term relationships are reimported fresh
> - **Postmeta**: All post metadata is synchronized
> - **Comments**: All comments and comment metadata are reimported
> - **Parent references**: Post parent IDs are updated to local IDs
>
> This single operation ensures all related data is consistent, rather than attempting to diff and update individual fields which could miss embedded ID references in content.

### Pages

Pages are **imported only once** during the first migration run. Existing pages are not updated on subsequent migration runs, even if their content changes on live. **New pages are also not imported on consecutive runs** — only the initial migration imports pages.

### Attachments

Attachments are imported once, but the following fields are **updated individually** if they change on live (without reimporting the entire attachment):

- Caption (`post_excerpt`)
- Description (`post_content`)
- Alt Text (`_wp_attachment_image_alt`)
- Media Credit (`_media_credit`)
- Media Credit URL (`_media_credit_url`)

> Individual field updates preserve the attachment's local ID, which is important since this ID may be referenced in post content blocks and featured image settings.

### Users

All the users from the live site get migrated fully during every migration run (in order to migrate subscribers and subscription data), as well as authors of records given here.

Migrated users have the following fields **updated individually** if they change on live:

- Email (`user_email`)
- Display Name (`display_name`)
- Simple Local Avatar's user avatar (`simple_local_avatar` usermeta) — if the [Simple Local Avatars](https://wordpress.org/plugins/simple-local-avatars/) plugin is used, avatar assignments are tracked and updated, including handling avatar removal

The `user_login` field is an **identifier field** — once a user is imported, changes to their login on live are ignored. If a new post references a user with a changed login, a new user will be created.

> Individual field updates preserve the user's local ID, which is important since this ID is used as `post_author` in posts.

### Categories and Tags

Migrated categories and post tags have the following fields **updated individually** if they change on live:

- Slug (`wp_terms.slug`)
- Description (`wp_term_taxonomy.description`)

The `name` and `parent` (for categories) fields are **identifier fields** — once a term is imported (has old_id termmeta), changes to these fields are ignored.

> Individual field updates preserve the term's local ID, which is important since this ID is used in term relationships with posts. This also applies to CoAuthors Plus author terms (taxonomy = 'author'), ensuring co-author metadata stays synchronized.

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

If your local site already contains some of the content from the source site (example case when local site was initially cloned from source), you must first attribute that existing content before running migrations. This creates the source-specific metadata needed for proper content matching, and lets the plugin know that the existing local content came from this specific source hostname, so that it could be matched/compared correctly:

```bash
wp newspack-content-diff-migrator attribute-existing-content-to-hostname \
    --live-table-prefix=cdiff_ \
    --source-hostname=www.example-1.com
```

---

## License

This plugin is part of the Newspack ecosystem and follows the same licensing terms as other Newspack plugins.

## Disclaimer

This plugin is provided as-is without any warranty or support. Use at your own risk. The authors and contributors are not responsible for any data loss or any kind of damage caused by the use of this plugin.
