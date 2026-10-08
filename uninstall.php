<?php
/**
 * Remove Linkyar's table, options and post meta.
 *
 * @package Linkyar
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}linkyar_links" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
foreach ( array( 'linkyar_index', 'linkyar_db_version', 'linkyar_last_rebuild' ) as $opt ) {
	delete_option( $opt );
}
delete_post_meta_by_key( '_linkyar_keywords' );
