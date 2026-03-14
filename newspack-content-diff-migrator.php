<?php
/**
 * Plugin Name: Newspack Content Diff Migrator
 * Description: Migrates the content differential from a remote site on top of the local site while keeping the existing local content.
 * Plugin URI:  https://newspack.com
 * Author:      Automattic
 * Author URI:  https://newspack.com
 * Version:     2.0.0
 *
 * @package  Newspack_Content_Diff_Migrator
 */

namespace Newspack\ContentDiffMigrator;

use Newspack\ContentDiffMigrator\PluginSetup;
use Newspack\ContentDiffMigrator\Command\ContentDiffMigrator;
use Newspack\ContentDiffMigrator\Command\ContentDiffMigratorIndex;
use Newspack\ContentDiffMigrator\Utils\Logger;

// Don't do anything outside WP CLI.
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

require __DIR__ . '/vendor/autoload.php';

// Use the same system configuration as the Newspack Custom Content Migrator plugin.
$error_reporting_level = false !== defined( 'NEWSPACK_CUSTOM_CONTENT_MIGRATOR_ERROR_REPORTING_LEVEL' ) ? NEWSPACK_CUSTOM_CONTENT_MIGRATOR_ERROR_REPORTING_LEVEL : 'dev';
PluginSetup::configure_error_reporting( $error_reporting_level );
PluginSetup::register_ticker();

Logger::configure( true );

ContentDiffMigrator::register_commands();
ContentDiffMigratorIndex::register_command();
