<?php
/**
 * WP-CLI commands.
 *
 * @package Linkyar
 */

defined( 'ABSPATH' ) || exit;

/**
 * Manage the internal link map.
 */
class Linkyar_CLI {

	/**
	 * Rebuild the target index and the internal link map.
	 *
	 * ## EXAMPLES
	 *
	 *     wp linkyar rebuild
	 */
	public function rebuild() {
		$r        = Linkyar_Links::rebuild_batch( 0, 200 );
		$progress = \WP_CLI\Utils\make_progress_bar( 'Reading posts', $r['total'] );
		$progress->tick( $r['processed'] );
		while ( ! $r['done'] ) {
			$before = $r['processed'];
			$r      = Linkyar_Links::rebuild_batch( $r['processed'], 200 );
			$progress->tick( $r['processed'] - $before );
		}
		$progress->finish();
		\WP_CLI::success( sprintf( '%d pages indexed, %d internal links found.', $r['total'], array_sum( Linkyar_Links::outbound_counts() ) ) );
	}

	/**
	 * List pages with no inbound internal links.
	 *
	 * ## OPTIONS
	 *
	 * [--post_type=<type>]
	 * : Only this post type.
	 *
	 * [--format=<format>]
	 * : table, csv, json, ids, count.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp linkyar orphans --post_type=product --format=csv > orphans.csv
	 *
	 * @param array $args       Positional.
	 * @param array $assoc_args Flags.
	 */
	public function orphans( $args, $assoc_args ) {
		$inbound = Linkyar_Links::inbound_counts();
		$type    = isset( $assoc_args['post_type'] ) ? $assoc_args['post_type'] : '';
		$items   = array();
		foreach ( Linkyar_Index::get()['targets'] as $id => $t ) {
			if ( empty( $inbound[ $id ] ) && ( ! $type || $t['type'] === $type ) ) {
				$items[] = array(
					'ID'    => $id,
					'type'  => $t['type'],
					'title' => $t['title'],
					'url'   => $t['url'],
				);
			}
		}
		$format = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );
		if ( 'ids' === $format ) {
			\WP_CLI::log( implode( ' ', wp_list_pluck( $items, 'ID' ) ) );
			return;
		}
		if ( 'count' === $format ) {
			\WP_CLI::log( (string) count( $items ) );
			return;
		}
		\WP_CLI\Utils\format_items( $format, $items, array( 'ID', 'type', 'title', 'url' ) );
	}

	/**
	 * Show link suggestions for one post.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Post ID.
	 *
	 * @param array $args Positional.
	 */
	public function suggest( $args ) {
		$post = get_post( (int) $args[0] );
		if ( ! $post ) {
			\WP_CLI::error( 'Post not found.' );
		}
		$blocks = array();
		foreach ( preg_split( '~</p>|<br\s*/?>|\n\s*\n~i', preg_replace( '~<(h[1-6]|a)\b[^>]*>.*?</\1>~is', ' ', $post->post_content ) ) as $i => $p ) {
			$blocks[] = array( 'id' => (string) $i, 'text' => Linkyar_Text::plain( $p ) );
		}
		$rows = array();
		foreach ( Linkyar_Suggest::find( $post->ID, $blocks, Linkyar_Index::linked_ids( $post->post_content ) ) as $s ) {
			$rows[] = array(
				'phrase'  => $s['phrase'],
				'target'  => $s['target']['title'],
				'inbound' => $s['target']['inbound'],
				'source'  => $s['source'],
				'score'   => $s['score'],
			);
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'phrase', 'target', 'inbound', 'source', 'score' ) );
	}
}
