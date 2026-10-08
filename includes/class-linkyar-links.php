<?php
/**
 * Internal link graph: which published post links to which. Powers orphan detection and suggestion ranking.
 *
 * @package Linkyar
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stores source → target internal links in a small custom table.
 */
class Linkyar_Links {

	const DB_VERSION = '1';
	const BATCH      = 100;

	/**
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'linkyar_links';
	}

	/**
	 * Create / upgrade the table.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
				source_id bigint(20) unsigned NOT NULL,
				target_id bigint(20) unsigned NOT NULL,
				PRIMARY KEY  (source_id,target_id),
				KEY target_id (target_id)
			) {$charset};"
		);
		update_option( 'linkyar_db_version', self::DB_VERSION, false );
	}

	/**
	 * Re-index one post's outgoing links (removes them if the post is no longer published).
	 *
	 * @param int $post_id Post ID.
	 */
	public static function update_post( $post_id ) {
		global $wpdb;
		$post_id = (int) $post_id;
		$table   = self::table();
		$wpdb->delete( $table, array( 'source_id' => $post_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status || ! in_array( $post->post_type, Linkyar_Index::post_types(), true ) ) {
			return;
		}
		$targets = array_diff( Linkyar_Index::linked_ids( $post->post_content . ' ' . $post->post_excerpt ), array( $post_id ) );
		if ( ! $targets ) {
			return;
		}
		$values = array();
		foreach ( $targets as $t ) {
			$values[] = $wpdb->prepare( '(%d,%d)', $post_id, $t );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "INSERT IGNORE INTO {$table} (source_id,target_id) VALUES " . implode( ',', $values ) );
	}

	/**
	 * Remove every row that mentions a post.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function forget_post( $post_id ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'source_id' => (int) $post_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Rebuild a batch of sources. Call with offset 0 first (which also truncates and rebuilds the index).
	 *
	 * @param int $offset Offset into the indexed targets.
	 * @param int $limit  Batch size.
	 * @return array{processed:int,total:int,done:bool}
	 */
	public static function rebuild_batch( $offset, $limit = self::BATCH ) {
		global $wpdb;
		if ( 0 === (int) $offset ) {
			Linkyar_Index::build();
			$wpdb->query( 'DELETE FROM ' . self::table() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		$ids   = array_keys( Linkyar_Index::get()['targets'] );
		$total = count( $ids );
		$slice = array_slice( $ids, (int) $offset, (int) $limit );
		_prime_post_caches( $slice, false, false );
		foreach ( $slice as $id ) {
			self::update_post( $id );
		}
		$done = ( $offset + count( $slice ) ) >= $total;
		if ( $done ) {
			update_option( 'linkyar_last_rebuild', time(), false );
		}
		return array(
			'processed' => (int) $offset + count( $slice ),
			'total'     => $total,
			'done'      => $done,
		);
	}

	/**
	 * Inbound internal link counts for every target that has at least one.
	 *
	 * @return array<int,int>
	 */
	public static function inbound_counts() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( "SELECT target_id, COUNT(*) AS c FROM {$table} GROUP BY target_id", ARRAY_N );
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$out[ (int) $r[0] ] = (int) $r[1];
		}
		return $out;
	}

	/**
	 * Outbound internal link counts per source.
	 *
	 * @return array<int,int>
	 */
	public static function outbound_counts() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( "SELECT source_id, COUNT(*) AS c FROM {$table} GROUP BY source_id", ARRAY_N );
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$out[ (int) $r[0] ] = (int) $r[1];
		}
		return $out;
	}

	/**
	 * Whether a full rebuild has ever completed.
	 *
	 * @return bool
	 */
	public static function is_built() {
		return (bool) get_option( 'linkyar_last_rebuild' );
	}
}
