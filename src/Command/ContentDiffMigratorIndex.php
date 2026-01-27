<?php
/**
 * Interactive command index for newspack-content-diff-migrator.
 *
 * Provides a user-friendly interactive menu for selecting and executing commands
 * with guided argument input.
 *
 * @package Newspack_Content_Diff_Migrator
 */

namespace Newspack\ContentDiffMigrator\Command;

use WP_CLI;

/**
 * Interactive command index class.
 */
class ContentDiffMigratorIndex {

	/**
	 * Register the index command.
	 */
	public static function register_command(): void {
		WP_CLI::add_command(
			'newspack-content-diff-migrator index',
			[ new self(), 'cmd_index' ],
			[
				'shortdesc' => 'Interactive command selector with guided argument input.',
				'longdesc'  => 'Displays an interactive menu to select and execute any newspack-content-diff-migrator command with step-by-step prompts for all required and optional arguments.',
			]
		);
	}

	/**
	 * Main interactive index command.
	 *
	 * @param array $pos_args   Positional CLI args (unused).
	 * @param array $assoc_args Associative CLI args (unused).
	 */
	public function cmd_index( array $pos_args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		// Get command definitions from ContentDiffMigrator.
		$commands = ContentDiffMigrator::get_commands();

		// Display welcome header.
		$this->display_header();

		// Interactive flow.
		while ( true ) {
			// Display menu and get selection.
			$selected = $this->display_menu_and_select( $commands );

			// Handle quit.
			if ( false === $selected ) {
				WP_CLI::line( '' );
				WP_CLI::success( 'Bye! 👋' );
				return;
			}

			// Show full description.
			$this->show_full_description( $selected, $commands );

			// Prompt for arguments.
			$args = $this->prompt_for_arguments( $selected, $commands );

			// Build command string.
			$full_command = $this->build_command_string( $selected, $args );

			// Confirm execution.
			if ( ! $this->confirm_execution( $full_command ) ) {
				WP_CLI::line( WP_CLI::colorize( '%yCommand cancelled. Returning to menu...%n' ) );
				WP_CLI::line( '' );
				continue;
			}

			// Execute command.
			$this->execute_command( $full_command );

			// Ask if user wants to run another command.
			WP_CLI::line( '' );
			WP_CLI::line( str_repeat( '─', 60 ) );
			$run_another = \cli\prompt( 'Run another command? [y/n]' );
			if ( 'y' !== strtolower( $run_another ) ) {
				WP_CLI::line( '' );
				WP_CLI::success( 'Bye! 👋' );
				return;
			}

			WP_CLI::line( '' );
		}
	}

	/**
	 * Display welcome header.
	 */
	private function display_header(): void {
		WP_CLI::line( '' );
		WP_CLI::line( WP_CLI::colorize( '%B┌─────────────────────────────────────────────────────────┐%n' ) );
		WP_CLI::line( WP_CLI::colorize( '%B│  Newspack Content Diff Migrator - Interactive Index     │%n' ) );
		WP_CLI::line( WP_CLI::colorize( '%B└─────────────────────────────────────────────────────────┘%n' ) );
		WP_CLI::line( '' );
	}

	/**
	 * Display command menu and get user selection.
	 *
	 * @param array $commands Command definitions organized by category.
	 * @return string|false Selected command name, or false if quit.
	 */
	private function display_menu_and_select( array $commands ) {
		$options    = [];
		$cmd_map    = [];
		$option_num = 1;

		// Build options list.
		foreach ( $commands as $category => $cmds ) {
			// Category header.
			$category_label = 'main' === $category ? 'MAIN MIGRATION COMMANDS' : 'UTILITY COMMANDS';
			WP_CLI::line( WP_CLI::colorize( '%G' . $category_label . ':%n' ) );
			WP_CLI::line( '' );

			foreach ( $cmds as $cmd_name => $cmd_config ) {
				$color = 'main' === $category ? '%C' : '%B';
				WP_CLI::line(
					WP_CLI::colorize(
						sprintf(
							'  %s%2d.%%n %s',
							$color,
							$option_num,
							$cmd_name
						) 
					) 
				);
				WP_CLI::line(
					sprintf(
						'     %s',
						$cmd_config['shortdesc']
					) 
				);
				WP_CLI::line( '' );

				$options[ $option_num ] = $cmd_name;
				$cmd_map[ $option_num ] = [ $category, $cmd_name ];
				++$option_num;
			}
		}

		// Prompt for selection.
		WP_CLI::line( str_repeat( '─', 60 ) );
		$selection = \cli\prompt(
			sprintf( 'Select command (1-%d) or "q" to quit', count( $options ) )
		);

		// Handle quit.
		if ( 'q' === strtolower( $selection ) ) {
			return false;
		}

		// Validate selection.
		$selection = (int) $selection;
		if ( ! isset( $options[ $selection ] ) ) {
			WP_CLI::warning( 'Invalid selection. Please try again.' );
			WP_CLI::line( '' );
			return $this->display_menu_and_select( $commands );
		}

		return $options[ $selection ];
	}

	/**
	 * Show full command description.
	 *
	 * @param string $cmd_name Command name.
	 * @param array  $commands Command definitions.
	 */
	private function show_full_description( string $cmd_name, array $commands ): void {
		$cmd_config = $this->get_command_config( $cmd_name, $commands );

		WP_CLI::line( '' );
		WP_CLI::line( str_repeat( '─', 60 ) );
		WP_CLI::line( WP_CLI::colorize( '%YCommand:%n ' . $cmd_name ) );
		WP_CLI::line( str_repeat( '─', 60 ) );
		WP_CLI::line( '' );
		WP_CLI::line( $cmd_config['shortdesc'] );

		if ( ! empty( $cmd_config['longdesc'] ) ) {
			WP_CLI::line( '' );
			WP_CLI::line( $cmd_config['longdesc'] );
		}

		WP_CLI::line( '' );
	}

	/**
	 * Prompt user for command arguments.
	 *
	 * @param string $cmd_name Command name.
	 * @param array  $commands Command definitions.
	 * @return array Collected argument values.
	 */
	private function prompt_for_arguments( string $cmd_name, array $commands ): array {
		$cmd_config = $this->get_command_config( $cmd_name, $commands );
		$synopsis   = $cmd_config['synopsis'] ?? [];
		$args       = [];

		if ( empty( $synopsis ) ) {
			WP_CLI::line( WP_CLI::colorize( '%GThis command requires no arguments.%n' ) );
			WP_CLI::line( '' );
			return $args;
		}

		// Group arguments by required/optional.
		$required = [];
		$optional = [];
		foreach ( $synopsis as $arg ) {
			if ( false === ( $arg['optional'] ?? true ) ) {
				$required[] = $arg;
			} else {
				$optional[] = $arg;
			}
		}

		// Prompt for required arguments.
		if ( ! empty( $required ) ) {
			WP_CLI::line( WP_CLI::colorize( '%YREQUIRED ARGUMENTS:%n' ) );
			WP_CLI::line( '' );
			foreach ( $required as $arg ) {
				$args[ $arg['name'] ] = $this->prompt_for_argument( $arg, true );
			}
			WP_CLI::line( '' );
		}

		// Prompt for optional arguments.
		if ( ! empty( $optional ) ) {
			WP_CLI::line( WP_CLI::colorize( '%YOPTIONAL ARGUMENTS:%n' ) );
			WP_CLI::line( '' );
			foreach ( $optional as $arg ) {
				$value = $this->prompt_for_argument( $arg, false );
				if ( '' !== $value ) {
					$args[ $arg['name'] ] = $value;
				}
			}
			WP_CLI::line( '' );
		}

		return $args;
	}

	/**
	 * Prompt for a single argument value.
	 *
	 * @param array $arg      Argument definition.
	 * @param bool  $required Whether the argument is required.
	 * @return string Argument value.
	 */
	private function prompt_for_argument( array $arg, bool $required ): string {
		$arg_name = $arg['name'];
		$desc     = $arg['description'];
		$type     = $arg['type'] ?? 'assoc';

		// Display argument info.
		WP_CLI::line( WP_CLI::colorize( '%G--' . $arg_name . ':%n' ) );
		WP_CLI::line( '  ' . $desc );

		// Handle flag type (boolean).
		if ( 'flag' === $type ) {
			$value = \cli\prompt( '  Include this flag? [y/n]' );
			return 'y' === strtolower( $value ) ? 'true' : '';
		}

		// Prompt for value.
		$prompt_text = $required ? '  Enter value' : '  Enter value (or press Enter to skip)';
		
		while ( true ) {
			// For optional fields, allow empty input by providing a default value.
			$value = $required ? \cli\prompt( $prompt_text ) : \cli\prompt( $prompt_text, '' );

			// Validate required fields.
			if ( $required && '' === trim( $value ) ) {
				WP_CLI::warning( '  This argument is required. Please provide a value.' );
				continue;
			}

			break;
		}

		return $value;
	}

	/**
	 * Build the full command string.
	 *
	 * @param string $cmd_name Command name.
	 * @param array  $args     Collected argument values.
	 * @return string Full command string.
	 */
	private function build_command_string( string $cmd_name, array $args ): string {
		$cmd_parts = [ 'wp', 'newspack-content-diff-migrator', $cmd_name ];

		foreach ( $args as $key => $value ) {
			// Handle flags.
			if ( 'true' === $value ) {
				$cmd_parts[] = '--' . $key;
			} else {
				$cmd_parts[] = '--' . $key . '=' . $value;
			}
		}

		return implode( ' ', $cmd_parts );
	}

	/**
	 * Confirm command execution.
	 *
	 * @param string $full_command Full command string to display.
	 * @return bool True if confirmed, false otherwise.
	 */
	private function confirm_execution( string $full_command ): bool {
		WP_CLI::line( str_repeat( '─', 60 ) );
		WP_CLI::line( $full_command );
		WP_CLI::line( '' );
		$response = \cli\prompt(
			'Execute this command? [y/n]'
		);
		return 'y' === strtolower( $response );
	}

	/**
	 * Execute the selected command with collected arguments.
	 *
	 * @param string $full_command Full command string to execute.
	 */
	private function execute_command( string $full_command ): void {
		WP_CLI::line( '' );
		WP_CLI::line( WP_CLI::colorize( '%GExecuting command...%n' ) );
		WP_CLI::line( str_repeat( '═', 60 ) );
		WP_CLI::line( '' );

		// Remove 'wp ' prefix for WP_CLI::runcommand.
		$command_without_wp = preg_replace( '/^wp\s+/', '', $full_command );

		// Execute command.
		try {
			WP_CLI::runcommand(
				$command_without_wp,
				[
					'launch'     => false,
					'exit_error' => false,
				] 
			);
		} catch ( \Exception $e ) {
			// Don't use WP_CLI::error() as it throws another exception.
			WP_CLI::warning( 'Command execution failed: ' . $e->getMessage() );
		}

		WP_CLI::line( '' );
		WP_CLI::line( str_repeat( '═', 60 ) );
	}

	/**
	 * Get command configuration by name.
	 *
	 * @param string $cmd_name Command name.
	 * @param array  $commands Command definitions.
	 * @return array Command configuration.
	 */
	private function get_command_config( string $cmd_name, array $commands ): array {
		foreach ( $commands as $category => $cmds ) {
			if ( isset( $cmds[ $cmd_name ] ) ) {
				return $cmds[ $cmd_name ];
			}
		}
		return [];
	}
}
