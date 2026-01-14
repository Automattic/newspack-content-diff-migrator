<?php
/**
 * Progress utility class for tracking percentage-based progress.
 *
 * @package Newspack_Content_Diff_Migrator
 */

namespace Newspack\ContentDiffMigrator\Utils;

/**
 * Progress tracker that reports milestone percentages.
 */
class Progress {

	/**
	 * Total number of steps.
	 *
	 * @var int
	 */
	private int $total;

	/**
	 * Percentage increment for milestones.
	 *
	 * @var int
	 */
	private int $increment;

	/**
	 * Current milestone percentage.
	 *
	 * @var int
	 */
	private int $current_percent = 0;

	/**
	 * Constructor.
	 *
	 * @param int $total     Total number of steps.
	 * @param int $increment Percentage increment for milestones (default 10 for 10%, 20%, 30%,etc.).
	 */
	public function __construct( int $total, int $increment = 10 ) {
		$this->total     = $total;
		$this->increment = $increment;
	}

	/**
	 * Tick the progress. Returns milestone percentage if one was crossed, null otherwise.
	 *
	 * @param int $current_step Current step number (1-based).
	 *
	 * @return int|null Milestone percentage if crossed, null otherwise.
	 */
	public function tick( int $current_step ): ?int {
		$last_percent = $this->current_percent;

		// Calculate next milestone.
		$next_milestone = min( $this->current_percent + $this->increment, 100 );

		// Calculate actual percentage at this step.
		$actual_percent = $current_step * 100 / $this->total;

		// Check if we've passed the next milestone (or multiple milestones).
		if ( $actual_percent >= $next_milestone ) {
			// Advance to the highest milestone we've crossed.
			while ( ( $this->current_percent + $this->increment ) <= $actual_percent ) {
				$this->current_percent += $this->increment;
			}
			// Cap at 100.
			if ( $actual_percent >= 100 ) {
				$this->current_percent = 100;
			}
		}

		// Return milestone only if it changed.
		return ( $this->current_percent !== $last_percent ) ? $this->current_percent : null;
	}

	/**
	 * Ensure progress completes at 100%. Call after loop to guarantee 100% is reported.
	 *
	 * @return int|null Returns 100 if not already reached, null if already at 100%.
	 */
	public function finish(): ?int {
		if ( $this->current_percent < 100 ) {
			$this->current_percent = 100;
			return 100;
		}
		return null;
	}

	/**
	 * Format milestone for CLI output (e.g., "10%...", "100%.").
	 *
	 * @param int $milestone Milestone percentage.
	 *
	 * @return string Formatted string.
	 */
	public static function format( int $milestone ): string {
		return $milestone . '%' . ( $milestone < 100 ? '...' : '.' );
	}
}
