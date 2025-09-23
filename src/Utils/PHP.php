<?php
/**
 * PHP utilities class.
 * 
 * @package Newspack_Content_Diff_Migrator
 */

namespace Newspack\ContentDiffMigrator\Utils;

/**
 * Low level PHP utilities class.
 */
class PHP {

	/**
	 * Taken from class-wp-cli.php function confirm() and simplified.
	 *
	 * @param string $question   Question to display before the prompt.
	 * @param array  $assoc_args Skips prompt if 'yes' is provided.
	 *
	 * @return string CLI user input.
	 */
	public static function readline( $question, $assoc_args = [] ) { // phpcs:ignore -- Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed.
		fwrite( STDOUT, $question ); // phpcs:ignore -- WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fwrite.
		$answer = rtrim( fgets( STDIN ), "\t\n\r\0\x0B" );

		return $answer;
	}

	/**
	 * Outputs a string directly to STDOUT. This lets you keep outputting to the same line. To break lines, add explicit "\n" to $str.
	 *
	 * @param string $str String to output.
	 *
	 * @return void
	 */
	public static function echo_stdout( string $str ) {
		fwrite( STDOUT, $str ); // phpcs:ignore -- WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fwrite.
	}
}
