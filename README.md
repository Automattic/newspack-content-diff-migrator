# Newspack Content Diff Migrator

This plugin is a content migration tool that migrates the content differential from a remote site on top of your local site while keeping the existing local content intact.

## Overview

The Newspack Content Diff Migrator is designed to synchronize content between a live site and a staging site by importing only the new or modified content from the live site. This is particularly useful for maintaining staging environments that need to stay current with production content without overwriting staging-specific changes.

It migrates the database content, however files synchronization should be done additionally.

## Features

- **Selective Import**: Only imports new or modified content from the live site
- **Incremental Migration**: Can be run multiple times to migrate the entire content differential, and the migration resumes from the last successful step
- **Preserves Local Content**: Keeps existing local content intact during migration
- **Comprehensive Coverage**: Handles posts, pages, attachments, users, comments, and basic taxonomies (category and post_tag) as well as custom taxonomies and custom post types.
- **Side-by-side Tables**: Works with source site's database tables alongside local tables with a different table prefix.
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
3. **Search for New Content**: Identify new or modified content on the live site
```
wp newspack-content-diff-migrator search-new-content \
--live-table-prefix=live_ \
--export-dir=./cdiff_data \
[--post-types-csv=post,page,attachment,custom_cpt]
```
4. **Migrate Content**: Import the identified content differential to the local site
```
wp newspack-content-diff-migrator content-diff-migrate-live-content \
--live-table-prefix=live_ \
--import-dir=./cdiff_data \
[--custom-taxonomies-csv=post_tag,category,custom_taxonomy]
```

## Best Practices

1. **Backup First**: Always backup your staging site before running migrations
2. **Test with Dry Run**: Use the `--dry-run` parameter to test migrations safely
3. **Monitor Logs**: Check log files for any issues or warnings
4. **Batch Processing**: Use appropriate batch sizes for large migrations
5. **Memory Limits**: Ensure sufficient PHP memory limits for large content sets

## Development

### Code Standards

Run `./vendor/bin/phpcs --standard=phpcs.xml {File}` to check for coding standards issues.
Run `./vendor/bin/phpcbf --standard=phpcs.xml {File}` to apply automatic fixes.

### Working with the NMT dependency (newspack-migration-tools)

This plugin points to the `dev-trunk` branch. Whenever code has been merged to trunk in the NMT, run `composer update automattic/newspack-migration-tools` to update the lockfile and get the latest from the NMT. If nothing happens when you update, then run `composer clear-cache` and try again.

Here is a oneliner (well – there are multiple lines for readability) that is safe to use even if you have the NMT symlinked into the `vendor` directory. From your PR's branch run:

```bash
rm -rf vendor/automattic/newspack-migration-tools && \
composer update automattic/newspack-migration-tools && \
git add composer.lock && \
git commit -m 'Updating NMT composer pointer' && \
git push origin $(git symbolic-ref --short HEAD)
```

## Troubleshooting and Common Issues

- Memory Exhaustion: Increase PHP memory limits or reduce batch sizes
- CLI Timeout Issues: Consider running migrations in smaller batches, or simply resume the migration from the last successful step
- Check the log files in the `cdiff_logs/` directory
- Use the `--dry-run` parameter to test without making changes
- Review the error log for specific error messages

## License

This plugin is part of the Newspack ecosystem and follows the same licensing terms as other Newspack plugins.

## Disclaimer

This plugin is provided as-is without any warranty or support. Use at your own risk. The authors and contributors are not responsible for any data loss or damage caused by the use of this plugin.
