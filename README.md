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
3. **Attribute Initial Content** (if local site was cloned from source): Create source-specific metadata for existing content
```bash
wp newspack-content-migrator content-diff-attribute-initial-content \
    --live-table-prefix=eg1_ \
    --source-site=www.example-1.com
```
4. **Search for New Content**: Identify new or modified content on the live site
```bash
wp newspack-content-migrator content-diff-search-new-content-on-live \
    --live-table-prefix=eg1_ \
    --source-site=www.example-1.com \
    --export-dir=/tmp/cdiff_data \
    [--post-types-csv=post,page,attachment,custom_cpt]
```
5. **Migrate Content**: Import the identified content differential to the local site
```bash
wp newspack-content-migrator content-diff-migrate-live-content \
    --live-table-prefix=eg1_ \
    --source-site=www.example-1.com \
    --import-dir=/tmp/cdiff_data \
    [--custom-taxonomies-csv=category,post_tag,author,custom_taxonomy]
```

## Best Practices

1. **Backup First**: Always backup your local staging site before running migrations
2. **Test with Dry Run**: Use the `--dry-run` parameter to test migrations safely
3. **Monitor Logs**: Check log files for any issues or warnings
4. **Batch Processing**: Use appropriate batch sizes for large migrations
5. **Memory Limits**: Ensure sufficient PHP memory limits for large content sets, coupled with smaller batches

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

## Multiple Source Sites

This plugin supports importing content from multiple source sites. Each source site is identified by a unique `--source-site` parameter (the full hostname) that must be provided to all commands. This allows you to:

- Import content from Site A with `--source-site=www.example-1.com`
- Import content from Site B with `--source-site=www.example-2.com`
- Run multiple refresh cycles for each source without conflicts

The source site hostname is used to namespace the "old ID" metadata, ensuring that old IDs from different sources don't clash.

### List Imported Source Sites

To see which source sites (hostnames) have already been imported:

```bash
wp newspack-content-migrator content-diff-list-source-sites
```

### Workflow for Multiple Sources

```bash
# Import from Site A (www.example-1.com)
wp newspack-content-migrator content-diff-search-new-content-on-live \
    --live-table-prefix=eg1_ \
    --source-site=www.example-1.com \
    --export-dir=/tmp/cdiff_eg1

wp newspack-content-migrator content-diff-migrate-live-content \
    --live-table-prefix=eg1_ \
    --source-site=www.example-1.com \
    --import-dir=/tmp/cdiff_eg1

# Import from Site B (www.example-2.com)
wp newspack-content-migrator content-diff-search-new-content-on-live \
    --live-table-prefix=eg2_ \
    --source-site=www.example-2.com \
    --export-dir=/tmp/cdiff_eg2

wp newspack-content-migrator content-diff-migrate-live-content \
    --live-table-prefix=eg2_ \
    --source-site=www.example-2.com \
    --import-dir=/tmp/cdiff_eg2
```

### Attributing Initial Content

If your local site was initially cloned from a source site, you should attribute existing content before running content-diff. This creates the necessary source-specific metadata for proper tracking:

```bash
wp newspack-content-migrator content-diff-attribute-initial-content \
    --live-table-prefix=eg1_ \
    --source-site=www.example-1.com
```

---

## Legacy Migration (Pre-Multiple-Source Installs)

If you have previously used this plugin **before** the multiple source sites feature was introduced, you need to clean up legacy metadata before using the new functionality.

### What Changed

Previous versions stored old IDs using a single meta key:
- `newspackcontentdiff_live_id` (for posts, attachments, and users)

The new version uses source-namespaced meta keys:
- `newspackcontentdiff_live_id_{source-site}` (e.g., `newspackcontentdiff_live_id_www.example-1.com`)

The old meta keys are no longer recognized and must be removed before running attribution.

### Step 1: Delete Legacy Metadata

Run these SQL commands to remove all legacy Content Diff metadata. **Always backup your database first.**

```sql
-- Delete legacy post/attachment metadata
DELETE FROM wp_postmeta WHERE meta_key = 'newspackcontentdiff_live_id';

-- Delete legacy user metadata
DELETE FROM wp_usermeta WHERE meta_key = 'newspackcontentdiff_live_id';
```

**Note:** If necessary update a custom installation custom table prefix other than `wp_`.

### Step 2: Attribute Existing Content

After cleaning legacy metadata, attribute your existing content to its source site with new metas:

```bash
wp newspack-content-migrator content-diff-attribute-initial-content \
    --live-table-prefix=eg1_ \
    --source-site=www.example-1.com
```

This command will:
1. Compare existing local content with the source site's database tables
2. Match existing posts and create new source-specific metadata

### Step 3: Resume Normal Operations

You can now use the content-diff commands with the `--source-site` parameter as documented above.

---

## License

This plugin is part of the Newspack ecosystem and follows the same licensing terms as other Newspack plugins.

## Disclaimer

This plugin is provided as-is without any warranty or support. Use at your own risk. The authors and contributors are not responsible for any data loss or damage caused by the use of this plugin.
