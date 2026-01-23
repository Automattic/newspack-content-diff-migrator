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
 *   // In commands, initialize with a log file path, either absolute or relative to current dir:
 *   Logger::instance()->init( '/path/to/debug.log' );  // Absolute path.
 *   Logger::instance()->init( 'debug.log' );           // Relative to current dir.
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
	 * Full path to the log file (resolved at init time).
	 *
	 * @var string|null
	 */
	private ?string $log_file_path = null;

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
	 * @param bool $enabled Whether logging is enabled (false in testing environment).
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
	 * Only the first call takes effect; subsequent calls are ignored. This ensures that when a
	 * command calls other commands internally, all logging goes to the same file.
	 *
	 * @param string $log_file Log file path. Can be relative ("debug.log") or absolute ("/tmp/debug.log").
	 */
	public function init( string $log_file ): void {
		if ( ! $this->enabled || null !== $this->log_file_path ) {
			return;
		}

		// Resolve to absolute path if relative.
		$this->log_file_path = $this->is_absolute_path( $log_file ) ? $log_file : getcwd() . '/' . $log_file;

		$log_dir       = dirname( $this->log_file_path );
		$log_file_name = basename( $this->log_file_path );
		$logger_name   = pathinfo( $log_file_name, PATHINFO_FILENAME );

		// Ensure log directory exists.
		if ( ! is_dir( $log_dir ) ) {
			wp_mkdir_p( $log_dir );
		}

		// Enable NMT logging filters.
		add_filter( 'newspack_migration_tools_enable_cli_log', '__return_true' );
		add_filter( 'newspack_migration_tools_enable_file_log', '__return_true' );

		// Temporarily override log directory for FileLog.
		$log_dir_filter = static fn() => $log_dir;
		add_filter( 'newspack_migration_tools_log_dir', $log_dir_filter, 9999 );

		try {
			// CLI does not need crowding with the timestamp (it will go to file), just the message and context.
			$cli_formatter    = new ColoredLineFormatter( null, '%message% %context%' . PHP_EOL, null, true, true );
			$this->logger_cli = CliLog::get_logger( $logger_name, $cli_formatter );
			// File logger is full and complete.
			$file_formatter    = new LineFormatter( '[%datetime%] %level_name%: %message% %context%' . PHP_EOL, 'Y-m-d H:i:s.u', true, true );
			$this->logger_file = FileLog::get_logger( $logger_name, $log_file_name, $file_formatter );
		} finally {
			remove_filter( 'newspack_migration_tools_log_dir', $log_dir_filter, 9999 );
		}
	}

	/**
	 * Check if a path is absolute.
	 */
	private function is_absolute_path( string $path ): bool {
		// Unix absolute or Windows absolute (e.g., C:\).
		return str_starts_with( $path, '/' ) || preg_match( '/^[A-Za-z]:[\\\\\/]/', $path );
	}

	/**
	 * Get the full log file path.
	 *
	 * @return string|null Full path to the log file, or null if not initialized.
	 */
	public function get_log_file_path(): ?string {
		return $this->log_file_path;
	}

	/**
	 * Logs to both CLI and FILE, sends a brief output to CLI (just message, no context)
	 * and a full output to FILE (message, with context data).
	 * The CLI message gets " (see debug log for context)." appended.
	 *
	 * @param string $level   PSR-3 log level (debug, info, warning, error, etc).
	 * @param string $message Log message.
	 * @param array  $context Log context (only gets logged to file).
	 */
	public function log_brief_and_verbose( string $level, string $message, array $context ): void {
		$this->log( self::OUTPUT_FILE, $level, $message, $context );
		$this->log( self::OUTPUT_CLI, $level, $message . ' (see ' . ( $this->log_file_path ?? 'debug log' ) . ' for full context).' );
	}

	/**
	 * Log to CLI, FILE, or both.
	 *
	 * @param string $output  One of the self::OUTPUT_* constants: OUTPUT_CLI, OUTPUT_FILE, or OUTPUT_BOTH.
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
