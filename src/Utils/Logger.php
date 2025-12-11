<?php
/**
 * Shared logger utility for Content Diff migrator.
 *
 * @package Newspack_Content_Diff_Migrator
 */

namespace Newspack\ContentDiffMigrator\Utils;

use Bramus\Monolog\Formatter\ColoredLineFormatter;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Psr\Log\LoggerInterface;
use Monolog\Logger as MonologLogger;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;

/**
 * Encapsulates logger setup and output handling.
 */
class Logger {

	/**
	 * Log outputs.
	 */
	public const OUTPUT_CLI  = 'cli';
	public const OUTPUT_FILE = 'file';
	public const OUTPUT_BOTH = 'cli_and_file';

	/**
	 * Whether logging is enabled. Useful for testing environment -- if disabled the loggers are NullLogger instances.
	 *
	 * @var bool
	 */
	private bool $enable_logging = true;

	/**
	 * CLI logger.
	 *
	 * @var LoggerInterface|NullLogger|null NullLogger if logging is disabled.
	 */
	private ?LoggerInterface $logger_cli = null;

	/**
	 * File logger.
	 *
	 * @var LoggerInterface|NullLogger|null NullLogger if logging is disabled.
	 */
	private ?LoggerInterface $logger_file = null;

	/**
	 * Logger slug.
	 *
	 * @var string|null Logger slug. If null, no loggers are initialized (useful for testing environment).
	 */
	private ?string $logger_slug = null;

	/**
	 * Constructor.
	 *
	 * @param bool $enable_logging Whether to enable logging.
	 */
	public function __construct( bool $enable_logging = true ) {
		$this->enable_logging = $enable_logging;

		if ( false === $this->enable_logging ) {
			$this->logger_cli  = new NullLogger();
			$this->logger_file = new NullLogger();
		}
	}

	/**
	 * Sets up the loggers.
	 *
	 * @param string|null $logger_slug CLI and file logger slug. If null, no loggers are initialized (useful for testing environment).
	 *
	 * @return void
	 */
	public function init_loggers( ?string $logger_slug = null ): void {
		if ( false === $this->enable_logging ) {
			return;
		}
		if ( is_null( $logger_slug ) ) {
			return;
		}

		$this->logger_slug = $logger_slug;

		$formatter_cli = new ColoredLineFormatter(
			null,
			'%message% %context%' . PHP_EOL,
			'Y-m-d H:i:s.u',
			true,
			true
		);
		$logger_cli    = new MonologLogger( $logger_slug . '_cli' );
		$handler_cli   = new StreamHandler( 'php://stdout' );
		$handler_cli->setFormatter( $formatter_cli );
		$logger_cli->pushHandler( $handler_cli );
		$this->logger_cli = $logger_cli;

		$formatter_file = new LineFormatter(
			'[%datetime%] %level_name%: %message% %context%' . PHP_EOL,
			'Y-m-d H:i:s.u',
			true,
			true
		);
		$logger_file    = new MonologLogger( $logger_slug . '_file' );
		$handler_file   = new StreamHandler( $logger_slug . '.log' );
		$handler_file->setFormatter( $formatter_file );
		$logger_file->pushHandler( $handler_file );
		$this->logger_file = $logger_file;
	}

	/**
	 * Returns the log file name.
	 *
	 * @return string|null Log file name. Null if logging is disabled.
	 */
	public function get_log_file_name(): ?string {
		if ( is_null( $this->logger_slug ) ) {
			return null;
		}

		return $this->logger_slug . '.log';
	}

	/**
	 * Log to one or both loggers based on parameters.
	 *
	 * @param string $output  Which log output to use, allowed values in self::LOG_OUTPUTS.
	 * @param string $level   \Psr\Log\LogLevel constants: debug, info, notice, warning, error, critical, alert, emergency.
	 * @param string $message Log message.
	 * @param array  $context Log context.
	 *
	 * @return void
	 */
	public function log( string $output, string $level, string $message, array $context = [] ): void {
		$is_level_basic = in_array( $level, [ LogLevel::NOTICE, LogLevel::INFO, LogLevel::DEBUG ], true );

		switch ( $output ) {
			case self::OUTPUT_CLI:
				$this->logger_cli->$level( ( $is_level_basic ? '' : $level . ': ' ) . $message, $context );
				break;
			case self::OUTPUT_FILE:
				$this->logger_file->$level( $message, $context );
				break;
			case self::OUTPUT_BOTH:
				$this->logger_cli->$level( ( $is_level_basic ? '' : $level . ': ' ) . $message, $context );
				$this->logger_file->$level( $message, $context );
				break;
		}
	}
}
