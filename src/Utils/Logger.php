<?php
/**
 * Logger uses newspack-migration-tools' CliLog and FileLog with custom formatters.
 *
 * @package Newspack_Content_Diff_Migrator
 */

namespace Newspack\ContentDiffMigrator\Utils;

use Bramus\Monolog\Formatter\ColoredLineFormatter;
use Monolog\Formatter\LineFormatter;
use Newspack\MigrationTools\Util\Log\CliLog;
use Newspack\MigrationTools\Util\Log\FileLog;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;

/**
 * Logger with output routing.
 *
 * Usage:
 *   // Bootstrap once (in plugin init or test setup):
 *   Logger::configure( true );  // or false for testing
 *
 *   // In commands, initialize for the specific logger name, i.e. specific log file name:
 *   Logger::instance()->init( 'my-log-file-name-slug' );
 *
 *   // Log from anywhere:
 *   Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::INFO, 'message' );
 */
class Logger {

	public const OUTPUT_CLI  = 'cli';
	public const OUTPUT_FILE = 'file';
	public const OUTPUT_BOTH = 'cli_and_file';

	/**
	 * Singleton instance.
	 *
	 * @var Logger|null
	 */
	private static ?Logger $instance = null;

	/**
	 * Whether logging is enabled.
	 *
	 * @var bool
	 */
	private bool $enabled;

	/**
	 * CLI logger.
	 *
	 * @var LoggerInterface
	 */
	private LoggerInterface $logger_cli;

	/**
	 * File logger.
	 *
	 * @var LoggerInterface
	 */
	private LoggerInterface $logger_file;

	/**
	 * Logger slug/name.
	 *
	 * @var string|null
	 */
	private ?string $slug = null;

	/**
	 * Private singleton constructor.
	 *
	 * @param bool $enabled Whether logging is enabled. False for testing environment.
	 */
	private function __construct( bool $enabled ) {
		$this->enabled     = $enabled;
		$this->logger_cli  = new NullLogger();
		$this->logger_file = new NullLogger();
	}

	/**
	 * Configure the singleton logger instance.
	 *
	 * Call this once at plugin bootstrap or test setup.
	 *
	 * @param bool $enabled Whether logging is enabled (should be false for testing environment).
	 */
	public static function configure( bool $enabled = true ): void {
		self::$instance = new self( $enabled );
	}

	/**
	 * Get the singleton logger instance.
	 *
	 * Falls back to a disabled logger if not configured.
	 *
	 * @return Logger
	 */
	public static function instance(): Logger {
		if ( null === self::$instance ) {
			self::$instance = new self( false );
		}
		return self::$instance;
	}

	/**
	 * Initialize loggers for a specific command/context.
	 *
	 * @param string $slug Logger name used for file name and logger identification.
	 */
	public function init( string $slug ): void {
		if ( ! $this->enabled ) {
			return;
		}

		$this->slug = $slug;

		// Enable NMT logging filters for this request.
		add_filter( 'newspack_migration_tools_enable_cli_log', '__return_true' );
		add_filter( 'newspack_migration_tools_enable_file_log', '__return_true' );

		// CLI logger: no timestamp, just message.
		$cli_formatter    = new ColoredLineFormatter( null, '%message% %context%' . PHP_EOL, null, true, true );
		$this->logger_cli = CliLog::get_logger( $slug, $cli_formatter );

		// File logger: with timestamp and level.
		$file_formatter    = new LineFormatter( '[%datetime%] %level_name%: %message% %context%' . PHP_EOL, 'Y-m-d H:i:s.u', true, true );
		$this->logger_file = FileLog::get_logger( $slug, $slug . '.log', $file_formatter );
	}

	/**
	 * Get the log file name.
	 *
	 * @return string|null Log file name, or null if not initialized.
	 */
	public function get_log_file_name(): ?string {
		$file_name = $this->slug ? $this->slug . '.log' : null;
		
		return $file_name;
	}

	/**
	 * Logs to both CLI and FILE, sends a brief output to CLI (just message, no context)
	 * and a full output to FILE (message, with context data).
	 * The CLI message gets "; see debug log for context." appended.
	 *
	 * @param string $level   PSR-3 log level (debug, info, warning, error, etc).
	 * @param string $message Log message.
	 * @param array  $context Log context (only gets logged to file).
	 */
	public function log_both_brief_and_verbose( string $level, string $message, array $context ): void {
		$this->log( self::OUTPUT_FILE, $level, $message, $context );
		$this->log( self::OUTPUT_CLI, $level, $message . '; see debug log for context.' );
	}

	/**
	 * Log to CLI, FILE, or both.
	 *
	 * @param string $output  One of the self::OUTOUT_* constants: OUTPUT_CLI, OUTPUT_FILE, or OUTPUT_BOTH.
	 * @param string $level   PSR-3 log level (debug, info, warning, error, etc).
	 * @param string $message Log message.
	 * @param array  $context Log context.
	 */
	public function log( string $output, string $level, string $message, array $context = [] ): void {
		// Prepend level for non-basic levels on CLI.
		$is_level_basic = in_array( $level, [ LogLevel::NOTICE, LogLevel::INFO, LogLevel::DEBUG ], true );
		$cli_message    = $is_level_basic ? $message : strtoupper( $level ) . ': ' . $message;

		switch ( $output ) {
			case self::OUTPUT_CLI:
				$this->logger_cli->$level( $cli_message, $context );
				break;
			case self::OUTPUT_FILE:
				$this->logger_file->$level( $message, $context );
				break;
			case self::OUTPUT_BOTH:
				$this->logger_cli->$level( $cli_message, $context );
				$this->logger_file->$level( $message, $context );
				break;
		}
	}
}
