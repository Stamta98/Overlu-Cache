<?php
/**
 * Plain text log with rotation.
 *
 * @package BricksCache
 */

namespace BricksCache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A cache bug is invisible by definition: the page looks fine, it is just old.
 * The log is how a purge that did not happen becomes visible after the fact.
 */
final class Logger {

	private const LEVELS = [
		'error'   => 40,
		'warning' => 30,
		'info'    => 20,
		'debug'   => 10,
	];

	/** Rotate once the file passes this size. */
	private const MAX_BYTES = 2097152;

	/**
	 * Settings instance.
	 */
	private Settings $settings;

	/**
	 * @param Settings $settings Settings instance.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Absolute path of the log file.
	 */
	public function file(): string {
		return Filesystem::dir( 'logs' ) . '/bricks-cache.log';
	}

	/**
	 * Write one line if the configured level allows it.
	 *
	 * @param string              $level   error|warning|info|debug.
	 * @param string              $message Message, in English: this is a developer log.
	 * @param array<string,mixed> $context Extra data appended as JSON.
	 */
	public function log( string $level, string $message, array $context = [] ): void {
		if ( ! $this->settings->on( 'logging.enabled' ) ) {
			return;
		}

		$threshold = self::LEVELS[ (string) $this->settings->get( 'logging.level', 'warning' ) ] ?? 30;

		if ( ( self::LEVELS[ $level ] ?? 0 ) < $threshold ) {
			return;
		}

		$this->rotate();

		$line = sprintf(
			"[%s] %-7s %s%s\n",
			gmdate( 'Y-m-d H:i:s' ),
			strtoupper( $level ),
			$message,
			$context ? ' ' . wp_json_encode( $context ) : ''
		);

		Filesystem::append( $this->file(), $line );
	}

	/**
	 * Shorthand for an error entry.
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Extra data.
	 */
	public function error( string $message, array $context = [] ): void {
		$this->log( 'error', $message, $context );
	}

	/**
	 * Shorthand for a warning entry.
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Extra data.
	 */
	public function warning( string $message, array $context = [] ): void {
		$this->log( 'warning', $message, $context );
	}

	/**
	 * Shorthand for an informational entry.
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Extra data.
	 */
	public function info( string $message, array $context = [] ): void {
		$this->log( 'info', $message, $context );
	}

	/**
	 * Shorthand for a debug entry.
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Extra data.
	 */
	public function debug( string $message, array $context = [] ): void {
		$this->log( 'debug', $message, $context );
	}

	/**
	 * Last lines of the log, newest first.
	 *
	 * @param int $lines How many lines to return.
	 *
	 * @return string[]
	 */
	public function tail( int $lines = 200 ): array {
		$file = $this->file();

		if ( ! is_readable( $file ) ) {
			return [];
		}

		$content = (string) @file_get_contents( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$all     = array_values( array_filter( explode( "\n", $content ), static fn( $line ) => '' !== trim( $line ) ) );

		return array_reverse( array_slice( $all, -$lines ) );
	}

	/**
	 * Delete the log and its rotated copy.
	 */
	public function clear(): void {
		Filesystem::delete( $this->file() );
		Filesystem::delete( $this->file() . '.1' );
	}

	/**
	 * Move the current file aside once it grows past the limit.
	 */
	private function rotate(): void {
		$file = $this->file();

		if ( ! is_file( $file ) || filesize( $file ) < self::MAX_BYTES ) {
			return;
		}

		Filesystem::delete( $file . '.1' );
		@rename( $file, $file . '.1' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}
}
