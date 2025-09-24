<?php
declare(ticks=1);

namespace Newspack\ContentDiffMigrator;

/**
 * PluginSetup class.
 */
class PluginSetup {
	/**
	 * Register a tick callback to check the if we exceed the memory limit.
	 */
	public static function register_ticker() {
		register_tick_function(
			function() {
				$memory_usage = memory_get_usage( false );

				if ( $memory_usage > 490000000 ) { // 490 MB in bytes, since the limit on Atomic is 512 MB.
					print_r( 'Exit due to memory usage: ' . $memory_usage );
					exit( 1 );
				}
			}
		);
	}

	/**
	 * Configures all errors and warnings will be output to CLI.
	 * 
	 * @param string $level Error reporting level. 'dev' is default. 'live' will not change error reporting.
	 */
	public static function configure_error_reporting( $level = 'dev' ): void {
		if ( 'dev' === $level ) {
			// phpcs:disable -- Adds extra debugging config options for dev purposes.
			@ini_set( 'display_errors', 1 );
			@ini_set( 'display_startup_errors', 1 );
			error_reporting( E_ALL );
			// phpcs:enable

			// Enable WP_DEBUG mode.
			if ( ! defined( 'WP_DEBUG' ) ) {
				define( 'WP_DEBUG', true );
			}
			// Enable Debug logging to the /wp-content/debug.log file.
			if ( ! defined( 'WP_DEBUG_LOG' ) ) {
				define( 'WP_DEBUG_LOG', true );
			}
			// Enable display of errors and warnings.
			if ( ! defined( 'WP_DEBUG_DISPLAY' ) ) {
				define( 'WP_DEBUG_DISPLAY', true );
			}
		}
	}
}
