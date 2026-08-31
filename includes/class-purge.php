<?php
/**
 * Invalidation: when a stored page stops being true.
 *
 * @package BricksCache
 */

namespace BricksCache;

use BricksCache\Store\Store_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Storing pages is the easy half. In a shop the hard half is throwing them
 * away at the right moment: a cached product page that says "en stock" after
 * the last unit sold costs a customer, not a millisecond.
 *
 * Every invalidation goes through this class. Callers queue what they touched
 * and the queue is flushed once, on shutdown, so saving a product with twelve
 * hooks attached deletes each directory once instead of twelve times.
 */
final class Purge {

	/**
	 * Settings service.
	 */
	private Settings $settings;

	/**
	 * Logger service.
	 */
	private Logger $logger;

	/**
	 * Active backend.
	 */
	private Store_Interface $store;

	/**
	 * URLs waiting to be purged, as url => recursive.
	 *
	 * @var array<string,bool>
	 */
	private array $queue = [];

	/**
	 * Whether a full purge was requested during this request.
	 */
	private bool $purge_everything = false;

	/**
	 * Reason of the queued full purge.
	 */
	private string $purge_reason = '';

	/**
	 * @param Settings        $settings Settings service.
	 * @param Logger          $logger   Logger service.
	 * @param Store_Interface $store    Active backend.
	 */
	public function __construct( Settings $settings, Logger $logger, Store_Interface $store ) {
		$this->settings = $settings;
		$this->logger   = $logger;
		$this->store    = $store;
	}

	/**
	 * Register the invalidation hooks the settings ask for.
	 */
	public function boot(): void {
		add_action( 'shutdown', [ $this, 'flush_queue' ], 100 );

		if ( $this->settings->on( 'invalidation.on_content_save' ) ) {
			add_action( 'save_post', [ $this, 'on_save_post' ], 10, 2 );
			add_action( 'wp_trash_post', [ $this, 'queue_post' ] );
			add_action( 'delete_post', [ $this, 'queue_post' ] );
			add_action( 'edited_term', [ $this, 'on_edited_term' ], 10, 3 );
			add_action( 'wp_update_nav_menu', [ $this, 'queue_everything_from_hook' ] );
		}

		if ( $this->settings->on( 'invalidation.on_comment' ) ) {
			add_action( 'comment_post', [ $this, 'on_comment_post' ], 10, 2 );
			add_action( 'transition_comment_status', [ $this, 'on_comment_status' ], 10, 3 );
		}

		// Structural changes: anything can look different afterwards.
		foreach ( [ 'switch_theme', 'customize_save_after', 'activated_plugin', 'deactivated_plugin', 'upgrader_process_complete', 'update_option_permalink_structure', 'update_option_home', 'update_option_siteurl', 'update_option_blogname' ] as $hook ) {
			add_action( $hook, [ $this, 'queue_everything_from_hook' ], 10, 0 );
		}

		add_action( 'bricks_cache_cleanup', [ $this, 'cleanup_expired' ] );
	}

	/**
	 * Delete every stored page.
	 *
	 * @param string $reason Free text for the log.
	 */
	public function all( string $reason = 'manual' ): bool {
		$done = $this->store->flush();

		$this->logger->info( 'Full purge.', [ 'reason' => $reason ] );

		/**
		 * Fires after every page has been dropped.
		 *
		 * @param string $reason Why.
		 */
		do_action( 'bricks_cache_purged_all', $reason );

		return $done;
	}

	/**
	 * Delete every variant stored for one URL.
	 *
	 * @param string $url       Absolute URL.
	 * @param bool   $recursive Also delete everything below it, which is how
	 *                          paginated archives are cleared.
	 */
	public function url( string $url, bool $recursive = false ): bool {
		$directory = $this->directory_for( $url );

		if ( null === $directory ) {
			return false;
		}

		$done = $recursive ? Filesystem::delete( $directory ) : $this->store->forget( $directory );

		$this->logger->debug( 'URL purged.', [ 'url' => $url ] );

		/**
		 * Fires after one URL has been dropped.
		 *
		 * @param string $url URL.
		 */
		do_action( 'bricks_cache_purged_url', $url );

		return (bool) $done;
	}

	/**
	 * Queue a URL for the end of the request.
	 *
	 * @param string $url       Absolute URL.
	 * @param bool   $recursive Include everything below the URL.
	 */
	public function queue_url( string $url, bool $recursive = false ): void {
		if ( '' === $url ) {
			return;
		}

		$this->queue[ $url ] = ( $this->queue[ $url ] ?? false ) || $recursive;
	}

	/**
	 * Queue a full purge.
	 *
	 * @param string $reason Free text for the log.
	 */
	public function queue_everything( string $reason = 'manual' ): void {
		$this->purge_everything = true;
		$this->purge_reason     = '' === $this->purge_reason ? $reason : $this->purge_reason;
	}

	/**
	 * Hook-friendly wrapper, so add_action() can call it with any arguments.
	 */
	public function queue_everything_from_hook(): void {
		$this->queue_everything( current_action() );
	}

	/**
	 * Purge a post and everything that lists it.
	 *
	 * @param int $post_id Post ID.
	 */
	public function post( int $post_id ): bool {
		foreach ( $this->urls_for_post( $post_id ) as $url => $recursive ) {
			$this->url( (string) $url, (bool) $recursive );
		}

		return true;
	}

	/**
	 * Queue a post and everything that lists it.
	 *
	 * @param int $post_id Post ID.
	 */
	public function queue_post( int $post_id ): void {
		foreach ( $this->urls_for_post( $post_id ) as $url => $recursive ) {
			$this->queue_url( (string) $url, (bool) $recursive );
		}
	}

	/**
	 * Every URL that shows a given post: itself, its archive, its terms and
	 * the front page.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return array<string,bool> URL => purge everything below it.
	 */
	public function urls_for_post( int $post_id ): array {
		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post || 'revision' === $post->post_type ) {
			return [];
		}

		$urls = [];

		$permalink = get_permalink( $post );

		if ( is_string( $permalink ) ) {
			$urls[ $permalink ] = false;
		}

		if ( $this->settings->on( 'invalidation.purge_home' ) ) {
			$urls[ home_url( '/' ) ] = true;
		}

		$archive = get_post_type_archive_link( $post->post_type );

		if ( is_string( $archive ) ) {
			$urls[ $archive ] = true;
		}

		foreach ( get_object_taxonomies( $post->post_type, 'names' ) as $taxonomy ) {
			$terms = get_the_terms( $post, $taxonomy );

			if ( ! is_array( $terms ) ) {
				continue;
			}

			foreach ( array_slice( $terms, 0, 20 ) as $term ) {
				$link = get_term_link( $term );

				if ( is_string( $link ) ) {
					$urls[ $link ] = true;
				}
			}
		}

		/**
		 * Filter the URLs purged together with a post.
		 *
		 * @param array<string,bool> $urls    URL => recursive.
		 * @param int                $post_id Post ID.
		 */
		return (array) apply_filters( 'bricks_cache_urls_for_post', $urls, $post_id );
	}

	/**
	 * Queue on save, ignoring the noise WordPress generates while editing.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 */
	public function on_save_post( int $post_id, \WP_Post $post ): void {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( 'auto-draft' === $post->post_status ) {
			return;
		}

		if ( ! is_post_type_viewable( $post->post_type ) ) {
			// Templates, forms and other invisible post types can change the
			// look of the whole site, so there is nothing narrower to purge.
			$this->queue_everything( 'save_' . $post->post_type );

			return;
		}

		$this->queue_post( $post_id );
	}

	/**
	 * Queue a term archive when the term changes.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy name.
	 */
	public function on_edited_term( int $term_id, int $tt_id, string $taxonomy ): void {
		$link = get_term_link( $term_id, $taxonomy );

		if ( is_string( $link ) ) {
			$this->queue_url( $link, true );
		}
	}

	/**
	 * Queue the commented post when a comment lands approved.
	 *
	 * @param int $comment_id Comment ID.
	 * @param int $approved   1 when published.
	 */
	public function on_comment_post( int $comment_id, $approved ): void {
		if ( 1 !== (int) $approved ) {
			return;
		}

		$comment = get_comment( $comment_id );

		if ( $comment instanceof \WP_Comment ) {
			$this->queue_post( (int) $comment->comment_post_ID );
		}
	}

	/**
	 * Queue the commented post when a comment is approved or unapproved later.
	 *
	 * @param string      $new_status New status.
	 * @param string      $old_status Old status.
	 * @param \WP_Comment $comment    Comment object.
	 */
	public function on_comment_status( $new_status, $old_status, $comment ): void {
		if ( $comment instanceof \WP_Comment ) {
			$this->queue_post( (int) $comment->comment_post_ID );
		}
	}

	/**
	 * Run everything queued during this request, once.
	 */
	public function flush_queue(): void {
		if ( $this->purge_everything ) {
			$this->all( $this->purge_reason );

			$this->queue            = [];
			$this->purge_everything = false;

			return;
		}

		if ( [] === $this->queue ) {
			return;
		}

		foreach ( $this->queue as $url => $recursive ) {
			$this->url( (string) $url, (bool) $recursive );
		}

		$this->logger->info( 'Queued purges done.', [ 'count' => count( $this->queue ) ] );

		$this->queue = [];
	}

	/**
	 * Scheduled cleanup of pages nobody can be served any more.
	 */
	public function cleanup_expired(): void {
		if ( ! $this->settings->on( 'invalidation.cleanup_expired' ) ) {
			return;
		}

		$ttl = (int) $this->settings->get( 'page_cache.ttl', 43200 );

		if ( $ttl <= 0 ) {
			return;
		}

		$removed = $this->store->purge_expired( $ttl );

		if ( $removed > 0 ) {
			$this->logger->info( 'Expired pages removed.', [ 'count' => $removed ] );
		}
	}

	/**
	 * Cache directory that holds the variants of a URL.
	 *
	 * @param string $url Absolute URL.
	 */
	private function directory_for( string $url ): ?string {
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return null;
		}

		$config = Config::read();

		if ( empty( $config['page_dir'] ) ) {
			return null;
		}

		$directory = Key::directory(
			$config,
			[
				'HTTP_HOST'   => $parts['host'],
				'REQUEST_URI' => ( $parts['path'] ?? '/' ),
			]
		);

		return Filesystem::is_inside_root( $directory ) ? $directory : null;
	}
}
