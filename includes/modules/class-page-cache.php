<?php
/**
 * Page cache: the writing half.
 *
 * @package BricksCache
 */

namespace BricksCache\Modules;

use BricksCache\Key;
use BricksCache\Module;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The drop-in reads; this module writes. It opens an output buffer as late as
 * possible (template_redirect, before anything is echoed) and decides what to
 * do with the page only once it is complete, because half the reasons not to
 * cache a page — a notice, a cookie, a 404 turned into a redirect — only exist
 * at the end of the render.
 */
final class Page_Cache extends Module {

	/**
	 * Whether this request is being buffered.
	 */
	private bool $buffering = false;

	/**
	 * Settings section and identifier.
	 */
	public function id(): string {
		return 'page_cache';
	}

	/**
	 * Name shown in the admin.
	 */
	public function label(): string {
		return __( 'Caché de página', 'bricks-cache' );
	}

	/**
	 * Hook into the render.
	 */
	public function boot(): void {
		add_action( 'template_redirect', [ $this, 'maybe_start_buffer' ], 0 );
	}

	/**
	 * Start buffering when this request is a candidate.
	 */
	public function maybe_start_buffer(): void {
		$reason = $this->plugin->rules()->request_reason();

		if ( null !== $reason ) {
			$this->send_status_header( 'BYPASS', $reason );
			$this->logger()->debug( 'Request not cacheable.', [ 'reason' => $reason ] );

			return;
		}

		$this->send_status_header( 'MISS' );

		$this->buffering = ob_start( [ $this, 'capture' ] );
	}

	/**
	 * Called by PHP with the finished page. Whatever happens here, the visitor
	 * must receive their page: every failure returns the HTML untouched.
	 *
	 * @param string $html Buffered output.
	 */
	public function capture( string $html ): string {
		if ( ! $this->buffering ) {
			return $html;
		}

		$reason = $this->plugin->rules()->response_reason( $html );

		if ( null !== $reason ) {
			$this->logger()->debug( 'Response not stored.', [ 'reason' => $reason ] );

			return $html;
		}

		if ( $this->setting( 'footer_signature', true ) ) {
			$html .= "\n" . $this->signature();
		}

		$config = $this->plugin->rules()->config();
		$base   = Key::file_base( $config, $_SERVER, $_COOKIE ); // phpcs:ignore WordPress.Security.NonceVerification

		$stored = $this->plugin->store()->store(
			$base,
			$html,
			[
				'url'          => $this->current_url(),
				'gzip'         => (bool) $this->setting( 'gzip', true ),
				'ttl'          => (int) $this->setting( 'ttl', 43200 ),
				'content_type' => $this->content_type(),
			]
		);

		if ( $stored ) {
			$this->logger()->info( 'Page stored.', [ 'url' => $this->current_url() ] );
		} else {
			$this->logger()->warning( 'Page could not be stored.', [ 'url' => $this->current_url() ] );
		}

		return $html;
	}

	/**
	 * Invisible marker so the source of a page says when it was generated.
	 */
	private function signature(): string {
		return sprintf(
			'<!-- Bricks Cache · página guardada el %s -->',
			esc_html( wp_date( 'd/m/Y H:i:s' ) )
		);
	}

	/**
	 * Content type this response is sending, stored so the drop-in can repeat
	 * it verbatim on a hit.
	 */
	private function content_type(): string {
		foreach ( headers_list() as $header ) {
			if ( str_starts_with( strtolower( $header ), 'content-type:' ) ) {
				return trim( substr( $header, strlen( 'content-type:' ) ) );
			}
		}

		return 'text/html; charset=' . get_bloginfo( 'charset' );
	}

	/**
	 * Absolute URL of the current request.
	 */
	private function current_url(): string {
		return home_url( add_query_arg( [] ) );
	}

	/**
	 * Tell whoever is debugging why they are not getting a hit.
	 *
	 * @param string $status MISS or BYPASS.
	 * @param string $reason Machine readable reason.
	 */
	private function send_status_header( string $status, string $reason = '' ): void {
		if ( headers_sent() ) {
			return;
		}

		header( 'X-Bricks-Cache: ' . $status );

		if ( '' !== $reason ) {
			header( 'X-Bricks-Cache-Reason: ' . preg_replace( '/[^a-z0-9_\-]/i', '', $reason ) );
		}
	}
}
