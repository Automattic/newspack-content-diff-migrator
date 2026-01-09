# Newspack Content Diff Migrator

**Version 2.0.0** — See [Legacy Migration](#legacy-migration-pre-multiple-source-installs) for upgrade instructions from v1.x.

This plugin is a content migration tool that migrates the content differential from one or more remote sites on top of your local site while keeping the existing local content intact.

## Overview

The Newspack Content Diff Migrator is designed to synchronize content between a remote site (also addressed as "live site" after Newspack's own migration workflow) and a local site (also addressed as "staging site") by importing only the new or modified content from the remote site. This is particularly useful for maintaining staging environments that need to stay current with production content without overwriting staging-specific changes.

It migrates the database content, however files synchronization should be done additionally.

## Features

- **Selective Import**: Only imports new or modified content from the live site
- **Incremental Migration**: Can be run multiple times to migrate the entire content differential, and the migration resumes from the last successful step
- **Preserves Local Content**: Keeps existing local content intact during migration
- **Comprehensive Coverage**: Handles posts, pages, attachments, users, comments, and basic taxonomies (category,post_tag,author) as well as custom taxonomies and custom post types
- **Side-by-side Tables**: Works with remote site's database tables alongside local tables with a different table prefix
- **Error Handling**: Comprehensive error logging and recovery mechanisms
- **Detailed Logging**: Provides extensive logging for troubleshooting
- **Graceful Degradation**: Continues processing even when individual items fail

## Installation

1. Clone or download the plugin to your WordPress plugins directory
2. Navigate to the plugin directory
3. Run `composer install` to install dependencies

```bash
cd wp-content/plugins/newspack-content-diff-migrator
composer install
```

## Usage

This plugin operates exclusively through WP-CLI commands. It's designed to be run on staging sites to import content differentials from live sites.

1. **Prepare Live Site Data**: Ensure you have access to the live site's database
2. **Import Live Tables**: Import live site database tables with a specific prefix
3. **Attribute Existing Content** (e.g. if local site was cloned from source): Create source-specific metadata for this content so that it can be matched (properly compared) during subsequent migration runs
```bash
wp newspack-content-diff-migrator attribute-existing-content-to-hostname \
    --live-table-prefix=eg1_ \
    --source-hostname=www.example-1.com
```
4. **Search for New Content**: Identify new or modified content on the live site
```bash
wp newspack-content-diff-migrator search-new-content-on-live \
    --live-table-prefix=eg1_ \
    --source-hostname=www.example-1.com \
    --data-dir=/tmp/cdiff_data \
    [--post-types-csv=post,page,attachment,custom_cpt1,custom_cpt2,guest-author]
```
5. **Migrate Content**: Import the identified content differential to the local site
```bash
wp newspack-content-diff-migrator migrate-live-content \
    --live-table-prefix=eg1_ \
    --source-hostname=www.example-1.com \
    --data-dir=/tmp/cdiff_data \
    [--custom-taxonomies-csv=category,post_tag,author,custom_taxonomy]
```

## Understanding the Data Directory (`--data-dir`)

The `--data-dir` parameter stores temporary run-state data in the `run-state` subfolder. It contains information about the migration progress and the IDs of the content that has been migrated, so that if a migration is interrupted, it can be resumed from the last known state.

### Resuming an Interrupted Migration

If a migration command is interrupted (e.g., timeout, crash), you can resume it by running the same command with the **same `--data-dir`**. The migration will pick up from where it left off.

```bash
# If this gets interrupted...
wp newspack-content-diff-migrator migrate-live-content \
    --data-dir=/tmp/cdiff_data ...

# ...just run the same command again to resume
wp newspack-content-diff-migrator migrate-live-content \
    --data-dir=/tmp/cdiff_data ...
```

### Starting a New Migration

Once a migration completes successfully, you can start a fresh migration at any time. The search command always queries the **database directly** to determine what content has already been migrated, and it does not rely on previous run-state files.

It is recommended to start a new migration with a new `--data-dir`, to keep separate migration records for each migration run.

However, it is also possible to safely reuse the same `--data-dir` for a grand new migration run -- in that case, the search command will overwrite the previous run-state files with fresh migration data, based on the current state of the database.

> **⚠️ Important:** Do not run the `migrate-live-content` command without first running `search-new-content-on-live` for a new migration cycle. The migrate command relies on the run-state files created by the search command.

## Migration Data Consistency Standard

The Newspack's own Migration Data Consistency Standard (see [internal P2](https://newspackp2.wordpress.com/2025/09/24/migration-data-consistency-standard/) for more details) defines how new and modified content is handled, and which fields get updated on subsequent migration runs. It ensures that changes made on the live site are reflected on the local site with a curated set of rules which match Newspack's optimal migration workflow.

### How It Works

The plugin uses two complementary update strategies, depending on the object type:

| Object Type | Detection | Update Method |
|-------------|-----------|---------------|
| **Posts** | Checked on each run | Full reimport (delete + reimport) |
| **Pages** | Not checked | Import once only (first run) |
| **Attachments** | Checked on each run | Individual field updates |
| **Users** | Checked on each run | Individual field updates |
| **Terms** (categories, tags) | Checked on each run | Individual field updates |
| **Custom Post Types** | Same fields as posts | Full reimport (delete + reimport) |

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
2. **Test with Dry Run**: Use the `--dry-run` parameter to test migrations safely
3. **Monitor Logs**: Check log files for any issues or warnings
4. **Batch Processing**: Use appropriate batch sizes for large migrations
5. **Memory Limits**: Ensure sufficient PHP memory limits for large content sets, coupled with smaller batches
6. **Always Run Search Before Migrate**: For each new migration cycle, always run `search-new-content-on-live` before `migrate-live-content` to ensure fresh run-state data

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
- Check the log files in the `cdiff_logs/` directory
- Use the `--dry-run` parameter to test without making changes
- Review the error log for specific error messages

## Multiple Source Hostnames

This plugin supports importing content from multiple source hostnames. Each source is identified by a unique `--source-hostname` parameter (the full hostname) that must be provided to all commands. This allows you to:

- Import content from Site A with `--source-hostname=www.example-1.com`
- Import content from Site B with `--source-hostname=www.example-2.com`
- Run multiple refresh cycles for each source without conflicts

The source hostname is used to namespace the "old ID" metadata, ensuring that old IDs from different sources don't clash.

### List Imported Source Hostnames

To see which source hostnames have already been imported:

```bash
wp newspack-content-diff-migrator list-previously-migrated-source-hostnames
```

### Workflow for Multiple Sources

```bash
# Import from Hostname A (www.example-1.com)
wp newspack-content-diff-migrator search-new-content-on-live \
    --live-table-prefix=eg1_ \
    --source-hostname=www.example-1.com \
    --data-dir=/tmp/cdiff_eg1

wp newspack-content-diff-migrator migrate-live-content \
    --live-table-prefix=eg1_ \
    --source-hostname=www.example-1.com \
    --data-dir=/tmp/cdiff_eg1

# Import from Hostname B (www.example-2.com)
wp newspack-content-diff-migrator search-new-content-on-live \
    --live-table-prefix=eg2_ \
    --source-hostname=www.example-2.com \
    --data-dir=/tmp/cdiff_eg2

wp newspack-content-diff-migrator migrate-live-content \
    --live-table-prefix=eg2_ \
    --source-hostname=www.example-2.com \
    --data-dir=/tmp/cdiff_eg2
```

### Attributing Initial Content

If your local site was initially cloned from a source hostname, since the content will not have the specific source-hostname metadata telling Content Diff Migrator which source site it belongs to, you should first attribute that existing content to a source hostname before running the content-diff commands.

This creates the necessary source-specific metadata for proper tracking, so that the content can be matched (properly compared) during subsequent migration runs:

```bash
wp newspack-content-diff-migrator attribute-existing-content-to-hostname \
    --live-table-prefix=eg1_ \
    --source-hostname=www.example-1.com
```

---

## Legacy Migration (Pre-Multiple-Source-Hostname Installs)

If you have previously used this plugin **before** the multiple source hostname feature was introduced, you need to clean up legacy metadata before using the new functionality.

### What Changed in v2.0.0

Previous versions stored old IDs using a single meta key:
- `newspackcontentdiff_live_id` (for posts, attachments, and users)

The new version uses source-namespaced meta keys:
- `newspackcontentdiff_oldid_{source-hostname}` (e.g., `newspackcontentdiff_oldid_www.example-1.com`)

The old meta keys are no longer recognized and must be removed before running attribution.

### Step 1: Delete Legacy Metadata

*Note: This step is only necessary if you have used the plugin version < 2.0.0 on your site, and are upgrading to v2.0.0 or later.*

Run these SQL commands to remove all legacy Content Diff metadata. **Always backup your database first.**

```sql
-- Delete legacy post/attachment metadata
DELETE FROM wp_postmeta WHERE meta_key = 'newspackcontentdiff_live_id';

-- Delete legacy user metadata
DELETE FROM wp_usermeta WHERE meta_key = 'newspackcontentdiff_live_id';
```

**Note:** If necessary update a custom installation custom table prefix other than `wp_`.

### Step 2: Attribute Existing Content

After cleaning legacy metadata, attribute the existing content on site the source hostname you are migrating:

```bash
wp newspack-content-diff-migrator attribute-existing-content-to-hostname \
    --live-table-prefix=eg1_ \
    --source-hostname=www.example-1.com
```

This command will:
1. Compare existing local content with the source hostname's database tables
2. Match existing posts and create new source-specific metadata

### Step 3: Resume Normal Operations

You can now use the content-diff commands with the `--source-hostname` parameter as documented above.

---

## License

This plugin is part of the Newspack ecosystem and follows the same licensing terms as other Newspack plugins.

## Disclaimer

This plugin is provided as-is without any warranty or support. Use at your own risk. The authors and contributors are not responsible for any data loss or damage caused by the use of this plugin.
