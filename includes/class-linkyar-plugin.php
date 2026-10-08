<?php
/**
 * Hooks, REST API and editor integration.
 *
 * @package Linkyar
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main controller.
 */
class Linkyar_Plugin {

	const REST_NS = 'linkyar/v1';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'save_post', array( __CLASS__, 'on_save' ), 20, 2 );
		add_action( 'deleted_post', array( __CLASS__, 'on_delete' ) );
		add_action( 'trashed_post', array( __CLASS__, 'on_delete' ) );
		add_action( 'updated_post_meta', array( __CLASS__, 'on_meta' ), 10, 3 );
		add_action( 'added_post_meta', array( __CLASS__, 'on_meta' ), 10, 3 );
		add_action( 'update_option_permalink_structure', array( 'Linkyar_Index', 'flush' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'block_editor_assets' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'classic_metabox' ), 10, 2 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_upgrade' ) );
	}

	/**
	 * Create the table if the plugin was updated without re-activation.
	 */
	public static function maybe_upgrade() {
		if ( Linkyar_Links::DB_VERSION !== get_option( 'linkyar_db_version' ) ) {
			Linkyar_Links::install();
		}
	}

	/**
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 */
	public static function on_save( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ! in_array( $post->post_type, Linkyar_Index::post_types(), true ) ) {
			return;
		}
		Linkyar_Index::refresh_post( $post_id );
		Linkyar_Links::update_post( $post_id );
	}

	/**
	 * @param int $post_id Post ID.
	 */
	public static function on_delete( $post_id ) {
		Linkyar_Index::refresh_post( $post_id );
		Linkyar_Links::forget_post( $post_id );
	}

	/**
	 * Re-index when focus keywords change (Yoast / Rank Math save them after the post itself).
	 *
	 * @param int    $meta_id  Meta ID.
	 * @param int    $post_id  Post ID.
	 * @param string $meta_key Key.
	 */
	public static function on_meta( $meta_id, $post_id, $meta_key ) {
		if ( in_array( $meta_key, array( '_yoast_wpseo_focuskw', 'rank_math_focus_keyword', Linkyar_Index::META_KEY ), true ) ) {
			Linkyar_Index::refresh_post( $post_id );
		}
	}

	/**
	 * REST routes.
	 */
	public static function routes() {
		$can_edit = static function ( WP_REST_Request $r ) {
			$id = (int) $r['post_id'];
			return $id ? current_user_can( 'edit_post', $id ) : current_user_can( 'edit_posts' );
		};

		register_rest_route(
			self::REST_NS,
			'/suggest',
			array(
				'methods'             => 'POST',
				'permission_callback' => $can_edit,
				'callback'            => array( __CLASS__, 'rest_suggest' ),
				'args'                => array(
					'post_id' => array( 'type' => 'integer', 'default' => 0 ),
					'blocks'  => array( 'type' => 'array', 'default' => array() ),
					'links'   => array( 'type' => 'array', 'default' => array() ),
					'html'    => array( 'type' => 'string', 'default' => '' ),
				),
			)
		);
		register_rest_route(
			self::REST_NS,
			'/keywords',
			array(
				'methods'             => 'POST',
				'permission_callback' => $can_edit,
				'callback'            => array( __CLASS__, 'rest_keywords' ),
				'args'                => array(
					'post_id'  => array( 'type' => 'integer', 'required' => true ),
					'keywords' => array( 'type' => 'string', 'default' => '' ),
				),
			)
		);
		register_rest_route(
			self::REST_NS,
			'/rebuild',
			array(
				'methods'             => 'POST',
				'permission_callback' => static function () {
					return current_user_can( 'manage_options' );
				},
				'callback'            => static function ( WP_REST_Request $r ) {
					return Linkyar_Links::rebuild_batch( (int) $r['offset'] );
				},
				'args'                => array( 'offset' => array( 'type' => 'integer', 'default' => 0 ) ),
			)
		);
	}

	/**
	 * POST /suggest — either `blocks` (block editor: [{id,text}] + `links` hrefs) or `html` (classic editor).
	 *
	 * @param WP_REST_Request $r Request.
	 * @return array
	 */
	public static function rest_suggest( WP_REST_Request $r ) {
		$post_id = (int) $r['post_id'];
		$blocks  = array();
		$linked  = array();

		if ( '' !== $r['html'] ) {
			$html = (string) $r['html'];
			$linked = Linkyar_Index::linked_ids( $html );
			// Split on paragraphs so suggestions spread across the text; skip headings and existing links.
			$html  = preg_replace( '~<(h[1-6]|a|script|style|pre|code)\b[^>]*>.*?</\1>~is', ' ', $html );
			$parts = preg_split( '~</p>|<br\s*/?>|\n\s*\n~i', $html );
			foreach ( $parts as $i => $p ) {
				$blocks[] = array( 'id' => 'p' . $i, 'text' => Linkyar_Text::plain( $p ) );
			}
		} else {
			foreach ( (array) $r['blocks'] as $b ) {
				if ( is_array( $b ) && isset( $b['text'] ) ) {
					$blocks[] = array(
						'id'   => isset( $b['id'] ) ? (string) $b['id'] : '',
						'text' => mb_substr( (string) $b['text'], 0, 20000 ),
					);
				}
			}
			foreach ( (array) $r['links'] as $href ) {
				$id = Linkyar_Index::resolve( (string) $href );
				if ( $id ) {
					$linked[] = $id;
				}
			}
		}

		$inbound = Linkyar_Links::inbound_counts();
		return array(
			'suggestions' => Linkyar_Suggest::find( $post_id, array_slice( $blocks, 0, 500 ), $linked ),
			'keywords'    => $post_id ? (string) get_post_meta( $post_id, Linkyar_Index::META_KEY, true ) : '',
			'inbound'     => $post_id && isset( $inbound[ $post_id ] ) ? $inbound[ $post_id ] : 0,
			'built'       => Linkyar_Links::is_built(),
		);
	}

	/**
	 * POST /keywords — save this post's own link phrases.
	 *
	 * @param WP_REST_Request $r Request.
	 * @return array
	 */
	public static function rest_keywords( WP_REST_Request $r ) {
		$id  = (int) $r['post_id'];
		$kws = sanitize_textarea_field( (string) $r['keywords'] );
		if ( '' === $kws ) {
			delete_post_meta( $id, Linkyar_Index::META_KEY );
		} else {
			update_post_meta( $id, Linkyar_Index::META_KEY, $kws );
		}
		Linkyar_Index::refresh_post( $id );
		return array( 'keywords' => $kws );
	}

	/**
	 * Strings shared by both editor scripts.
	 *
	 * @return array
	 */
	private static function i18n() {
		return array(
			'title'        => __( 'Linkyar', 'linkyar' ),
			'menu'         => __( 'Linkyar – internal links', 'linkyar' ),
			'find'         => __( 'Find link opportunities', 'linkyar' ),
			'refresh'      => __( 'Scan again', 'linkyar' ),
			'searching'    => __( 'Reading your text…', 'linkyar' ),
			'none'         => __( 'No new opportunities. Add more text, or add keywords to your other posts.', 'linkyar' ),
			'insert'       => __( 'Insert link', 'linkyar' ),
			'inserted'     => __( 'Linked', 'linkyar' ),
			'skip'         => __( 'Skip', 'linkyar' ),
			'copy'         => __( 'Copy link', 'linkyar' ),
			'copied'       => __( 'Copied', 'linkyar' ),
			'notFound'     => __( 'The text changed; scan again.', 'linkyar' ),
			'orphan'       => __( 'Orphan', 'linkyar' ),
			'inboundN'     => __( '%d inbound', 'linkyar' ),
			'thisInbound'  => __( 'Internal links pointing to this post: %d', 'linkyar' ),
			'thisOrphan'   => __( 'No other post links here yet — this post is an orphan.', 'linkyar' ),
			'keywords'     => __( 'Phrases that should link to this post', 'linkyar' ),
			'keywordsHelp' => __( 'Comma-separated. Your Yoast / Rank Math focus keyword and the title are used automatically.', 'linkyar' ),
			'save'         => __( 'Save phrases', 'linkyar' ),
			'saved'        => __( 'Saved', 'linkyar' ),
			'notBuilt'     => __( 'Tip: build the link map once under Tools → Linkyar so orphan pages get priority.', 'linkyar' ),
			'error'        => __( 'Something went wrong. Save the post and try again.', 'linkyar' ),
			'source'       => array(
				'custom' => __( 'your phrase', 'linkyar' ),
				'focus'  => __( 'focus keyword', 'linkyar' ),
				'title'  => __( 'title', 'linkyar' ),
			),
		);
	}

	/**
	 * Block editor sidebar.
	 */
	public static function block_editor_assets() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && $screen->post_type && ! in_array( $screen->post_type, Linkyar_Index::post_types(), true ) ) {
			return;
		}
		wp_enqueue_script(
			'linkyar-editor',
			plugins_url( 'assets/editor.js', LINKYAR_FILE ),
			array( 'wp-plugins', 'wp-element', 'wp-components', 'wp-data', 'wp-rich-text', 'wp-api-fetch', 'wp-editor', 'wp-edit-post', 'wp-block-editor' ),
			LINKYAR_VERSION,
			true
		);
		wp_enqueue_style( 'linkyar-editor', plugins_url( 'assets/editor.css', LINKYAR_FILE ), array(), LINKYAR_VERSION );
		wp_localize_script( 'linkyar-editor', 'LINKYAR', array( 'i18n' => self::i18n() ) );
	}

	/**
	 * Classic editor (e.g. WooCommerce products) gets a metabox.
	 *
	 * @param string  $post_type Post type.
	 * @param WP_Post $post      Post.
	 */
	public static function classic_metabox( $post_type, $post ) {
		if ( ! in_array( $post_type, Linkyar_Index::post_types(), true ) ) {
			return;
		}
		if ( function_exists( 'use_block_editor_for_post' ) && $post instanceof WP_Post && use_block_editor_for_post( $post ) ) {
			return;
		}
		add_meta_box( 'linkyar', __( 'Linkyar – internal links', 'linkyar' ), array( __CLASS__, 'render_metabox' ), $post_type, 'side', 'default' );
		wp_enqueue_script( 'linkyar-classic', plugins_url( 'assets/classic.js', LINKYAR_FILE ), array( 'wp-api-fetch' ), LINKYAR_VERSION, true );
		wp_enqueue_style( 'linkyar-editor', plugins_url( 'assets/editor.css', LINKYAR_FILE ), array(), LINKYAR_VERSION );
		wp_localize_script(
			'linkyar-classic',
			'LINKYAR',
			array(
				'i18n'   => self::i18n(),
				'postId' => $post instanceof WP_Post ? $post->ID : 0,
			)
		);
	}

	/**
	 * Metabox markup (filled by classic.js).
	 *
	 * @param WP_Post $post Post.
	 */
	public static function render_metabox( $post ) {
		$kws = (string) get_post_meta( $post->ID, Linkyar_Index::META_KEY, true );
		?>
		<div class="linkyar-classic">
			<p class="linkyar-stat" data-linkyar-stat></p>
			<p><button type="button" class="button button-primary" data-linkyar-find><?php esc_html_e( 'Find link opportunities', 'linkyar' ); ?></button></p>
			<div data-linkyar-list></div>
			<hr>
			<label for="linkyar-kw"><strong><?php esc_html_e( 'Phrases that should link to this post', 'linkyar' ); ?></strong></label>
			<textarea id="linkyar-kw" rows="2" class="widefat" data-linkyar-kw><?php echo esc_textarea( $kws ); ?></textarea>
			<p class="description"><?php esc_html_e( 'Comma-separated. Your Yoast / Rank Math focus keyword and the title are used automatically.', 'linkyar' ); ?></p>
			<p><button type="button" class="button" data-linkyar-save><?php esc_html_e( 'Save phrases', 'linkyar' ); ?></button></p>
		</div>
		<?php
	}
}
