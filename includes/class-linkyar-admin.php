<?php
/**
 * Tools → Linkyar: link map, orphan report and rebuild.
 *
 * @package Linkyar
 */

defined( 'ABSPATH' ) || exit;

/**
 * Report screen.
 */
class Linkyar_Admin {

	const SLUG = 'linkyar';
	const ROWS = 200;

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'first_run_notice' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( LINKYAR_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * @param string[] $links Links.
	 * @return string[]
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Link report', 'linkyar' ) . '</a>' );
		return $links;
	}

	/**
	 * @param array $args Query args.
	 * @return string
	 */
	private static function url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'tools.php' ) );
	}

	/**
	 * Menu entry.
	 */
	public static function menu() {
		add_management_page( __( 'Linkyar – internal links', 'linkyar' ), __( 'Linkyar', 'linkyar' ), 'manage_options', self::SLUG, array( __CLASS__, 'render' ) );
	}

	/**
	 * One-time nudge to build the link map.
	 */
	public static function first_run_notice() {
		if ( ! current_user_can( 'manage_options' ) || Linkyar_Links::is_built() ) {
			return;
		}
		$screen = get_current_screen();
		if ( $screen && in_array( $screen->id, array( 'tools_page_' . self::SLUG ), true ) ) {
			return;
		}
		if ( $screen && ! in_array( $screen->id, array( 'dashboard', 'plugins' ), true ) ) {
			return;
		}
		printf(
			'<div class="notice notice-info"><p>%s <a class="button button-primary" href="%s">%s</a></p></div>',
			esc_html__( 'Linkyar is active. Build your internal link map once to find orphan pages and rank suggestions.', 'linkyar' ),
			esc_url( self::url() ),
			esc_html__( 'Build link map', 'linkyar' )
		);
	}

	/**
	 * @param string $hook Screen hook.
	 */
	public static function assets( $hook ) {
		if ( 'tools_page_' . self::SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'linkyar-admin', plugins_url( 'assets/admin.css', LINKYAR_FILE ), array(), LINKYAR_VERSION );
		wp_enqueue_script( 'linkyar-admin', plugins_url( 'assets/admin.js', LINKYAR_FILE ), array( 'wp-api-fetch' ), LINKYAR_VERSION, true );
		wp_localize_script(
			'linkyar-admin',
			'LINKYAR_ADMIN',
			array(
				'i18n' => array(
					'working' => __( 'Reading %1$d of %2$d…', 'linkyar' ),
					'error'   => __( 'The rebuild stopped. Reload the page and try again.', 'linkyar' ),
				),
			)
		);
	}

	/**
	 * Render the report.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$index    = Linkyar_Index::get();
		$targets  = $index['targets'];
		$inbound  = Linkyar_Links::inbound_counts();
		$outbound = Linkyar_Links::outbound_counts();
		$built    = Linkyar_Links::is_built();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$view  = isset( $_GET['view'] ) ? sanitize_key( $_GET['view'] ) : 'orphans';
		$ptype = isset( $_GET['ptype'] ) ? sanitize_key( $_GET['ptype'] ) : '';
		// phpcs:enable

		$rows = array();
		foreach ( $targets as $id => $t ) {
			if ( $ptype && $t['type'] !== $ptype ) {
				continue;
			}
			$t['in']  = isset( $inbound[ $id ] ) ? $inbound[ $id ] : 0;
			$t['out'] = isset( $outbound[ $id ] ) ? $outbound[ $id ] : 0;
			$rows[]   = $t;
		}

		$total_links = array_sum( $outbound );
		$orphans     = count(
			array_filter(
				$rows,
				static function ( $r ) {
					return 0 === $r['in'];
				}
			)
		);
		$no_phrases  = count(
			array_filter(
				$rows,
				static function ( $r ) {
					return empty( $r['phrases'] );
				}
			)
		);

		if ( 'orphans' === $view ) {
			$rows = array_filter(
				$rows,
				static function ( $r ) {
					return 0 === $r['in'];
				}
			);
		} elseif ( 'weak' === $view ) {
			$rows = array_filter(
				$rows,
				static function ( $r ) {
					return $r['in'] > 0 && $r['in'] <= 2;
				}
			);
		} elseif ( 'nophrase' === $view ) {
			$rows = array_filter(
				$rows,
				static function ( $r ) {
					return empty( $r['phrases'] );
				}
			);
		}
		usort(
			$rows,
			'top' === $view
				? static function ( $a, $b ) {
					return $b['in'] <=> $a['in'];
				}
				: static function ( $a, $b ) {
					return $a['in'] <=> $b['in'] ?: $b['id'] <=> $a['id'];
				}
		);
		$shown = array_slice( $rows, 0, self::ROWS );

		$types = array();
		foreach ( Linkyar_Index::post_types() as $pt ) {
			$obj = get_post_type_object( $pt );
			if ( $obj ) {
				$types[ $pt ] = $obj->labels->name;
			}
		}
		$tabs = array(
			'orphans'  => __( 'Orphans (0 inbound)', 'linkyar' ),
			'weak'     => __( 'Weak (1–2 inbound)', 'linkyar' ),
			'top'      => __( 'Most linked', 'linkyar' ),
			'nophrase' => __( 'Without phrases', 'linkyar' ),
			'all'      => __( 'All', 'linkyar' ),
		);
		$last = (int) get_option( 'linkyar_last_rebuild' );
		?>
		<div class="wrap linkyar-wrap">
			<h1><?php esc_html_e( 'Linkyar – internal links', 'linkyar' ); ?></h1>
			<p class="linkyar-lead"><?php esc_html_e( 'Every page should be reachable through internal links. Orphan pages get crawled less, rank worse and pass no authority. Open one, scan, and link to it from related content — the editor sidebar shows orphans first.', 'linkyar' ); ?></p>

			<div class="linkyar-stats">
				<div><strong><?php echo esc_html( number_format_i18n( count( $targets ) ) ); ?></strong><span><?php esc_html_e( 'Indexed pages', 'linkyar' ); ?></span></div>
				<div><strong><?php echo esc_html( number_format_i18n( $total_links ) ); ?></strong><span><?php esc_html_e( 'Internal links', 'linkyar' ); ?></span></div>
				<div class="<?php echo $orphans ? 'is-bad' : 'is-good'; ?>"><strong><?php echo $built ? esc_html( number_format_i18n( $orphans ) ) : '—'; ?></strong><span><?php esc_html_e( 'Orphan pages', 'linkyar' ); ?></span></div>
				<div><strong><?php echo esc_html( count( $targets ) ? number_format_i18n( $total_links / count( $targets ), 1 ) : '0' ); ?></strong><span><?php esc_html_e( 'Avg. inbound links', 'linkyar' ); ?></span></div>
			</div>

			<div class="linkyar-rebuild">
				<button type="button" class="button <?php echo $built ? '' : 'button-primary'; ?>" id="linkyar-rebuild"><?php echo $built ? esc_html__( 'Rebuild link map', 'linkyar' ) : esc_html__( 'Build link map', 'linkyar' ); ?></button>
				<progress id="linkyar-progress" max="100" value="0" hidden></progress>
				<span id="linkyar-status" aria-live="polite">
					<?php
					if ( $last ) {
						/* translators: %s: human time difference */
						echo esc_html( sprintf( __( 'Last full rebuild: %s ago. Posts update automatically when saved.', 'linkyar' ), human_time_diff( $last ) ) );
					} else {
						esc_html_e( 'Not built yet — takes a few seconds for most sites.', 'linkyar' );
					}
					?>
				</span>
			</div>

			<?php if ( $built ) : ?>
				<nav class="nav-tab-wrapper">
					<?php foreach ( $tabs as $key => $label ) : ?>
						<a class="nav-tab <?php echo $view === $key ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( self::url( array( 'view' => $key, 'ptype' => $ptype ) ) ); ?>"><?php echo esc_html( $label ); ?></a>
					<?php endforeach; ?>
				</nav>

				<form method="get" class="linkyar-filter">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
					<input type="hidden" name="view" value="<?php echo esc_attr( $view ); ?>">
					<select name="ptype" onchange="this.form.submit()">
						<option value=""><?php esc_html_e( 'All content types', 'linkyar' ); ?></option>
						<?php foreach ( $types as $pt => $label ) : ?>
							<option value="<?php echo esc_attr( $pt ); ?>" <?php selected( $ptype, $pt ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<span class="linkyar-count">
						<?php
						/* translators: 1: shown rows, 2: total rows */
						echo esc_html( sprintf( __( 'Showing %1$s of %2$s', 'linkyar' ), number_format_i18n( count( $shown ) ), number_format_i18n( count( $rows ) ) ) );
						?>
					</span>
				</form>

				<table class="widefat striped linkyar-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Page', 'linkyar' ); ?></th>
							<th><?php esc_html_e( 'Type', 'linkyar' ); ?></th>
							<th class="num"><?php esc_html_e( 'Inbound', 'linkyar' ); ?></th>
							<th class="num"><?php esc_html_e( 'Outbound', 'linkyar' ); ?></th>
							<th><?php esc_html_e( 'Linked from phrases', 'linkyar' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( ! $shown ) : ?>
							<tr><td colspan="5"><?php esc_html_e( 'Nothing here. Nice work.', 'linkyar' ); ?></td></tr>
						<?php endif; ?>
						<?php foreach ( $shown as $r ) : ?>
							<tr>
								<td>
									<strong><a href="<?php echo esc_url( (string) get_edit_post_link( $r['id'] ) ); ?>"><?php echo esc_html( '' !== $r['title'] ? $r['title'] : '#' . $r['id'] ); ?></a></strong>
									<div class="row-actions"><a href="<?php echo esc_url( $r['url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View', 'linkyar' ); ?></a></div>
								</td>
								<td><?php echo esc_html( isset( $types[ $r['type'] ] ) ? $types[ $r['type'] ] : $r['type'] ); ?></td>
								<td class="num"><span class="linkyar-pill <?php echo 0 === $r['in'] ? 'is-bad' : ( $r['in'] <= 2 ? 'is-warn' : 'is-good' ); ?>"><?php echo esc_html( number_format_i18n( $r['in'] ) ); ?></span></td>
								<td class="num"><?php echo esc_html( number_format_i18n( $r['out'] ) ); ?></td>
								<td class="linkyar-phrases">
									<?php if ( empty( $r['phrases'] ) ) : ?>
										<em><?php esc_html_e( 'None — add a focus keyword or Linkyar phrases so other posts can link here.', 'linkyar' ); ?></em>
									<?php else : ?>
										<?php foreach ( array_slice( $r['phrases'], 0, 4, true ) as $phrase => $src ) : ?>
											<code class="is-<?php echo esc_attr( $src ); ?>"><?php echo esc_html( $phrase ); ?></code>
										<?php endforeach; ?>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php if ( $no_phrases && 'nophrase' !== $view ) : ?>
					<p class="description">
						<?php
						/* translators: %s: number of pages */
						echo esc_html( sprintf( __( '%s pages have no linkable phrase yet (single-word titles and no focus keyword).', 'linkyar' ), number_format_i18n( $no_phrases ) ) );
						?>
					</p>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}
}
