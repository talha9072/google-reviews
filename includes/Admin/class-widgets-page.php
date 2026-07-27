<?php
/**
 * Widget list and editor.
 *
 * @package GoogleReviews
 */

namespace GoogleReviews\Admin;

use GoogleReviews\Data\WidgetsRepository;
use GoogleReviews\Render\Renderer;
use GoogleReviews\Widget\SettingsSchema;

defined( 'ABSPATH' ) || exit;

/**
 * A form-driven editor with a live preview rendered by the real renderer.
 *
 * The preview is not a mock-up: it calls Renderer::render() exactly as the front
 * end does, inside an iframe so the admin stylesheet cannot influence it. What
 * you see here is what the shortcode outputs.
 */
class WidgetsPage {

	private const CAPABILITY = 'manage_options';

	/**
	 * Register form handlers.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_post_gbrw_create_widget', array( __CLASS__, 'handle_create' ) );
		add_action( 'admin_post_gbrw_save_widget', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_gbrw_delete_widget', array( __CLASS__, 'handle_delete' ) );
	}

	/**
	 * Capability plus nonce check.
	 *
	 * @param string $action Nonce action.
	 * @return void
	 */
	private static function guard( string $action ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage widgets.', 'google-reviews-widget' ) );
		}

		check_admin_referer( $action );
	}

	/**
	 * Create a widget and open its editor.
	 *
	 * @return void
	 */
	public static function handle_create(): void {
		self::guard( 'gbrw_create_widget' );

		$id = WidgetsRepository::create( __( 'My reviews widget', 'google-reviews-widget' ) );

		wp_safe_redirect(
			$id > 0
				? admin_url( 'admin.php?page=gbrw-widgets&edit=' . $id )
				: admin_url( 'admin.php?page=gbrw-widgets' )
		);
		exit;
	}

	/**
	 * Save the draft, and publish it too when asked.
	 *
	 * @return void
	 */
	public static function handle_save(): void {
		self::guard( 'gbrw_save_widget' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in guard().
		$id = isset( $_POST['widget_id'] ) ? (int) $_POST['widget_id'] : 0;

		if ( $id <= 0 || ! WidgetsRepository::find( $id ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=gbrw-widgets' ) );
			exit;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in guard().
		$name = isset( $_POST['widget_name'] ) ? sanitize_text_field( wp_unslash( $_POST['widget_name'] ) ) : '';

		if ( '' === $name ) {
			$name = __( 'Untitled widget', 'google-reviews-widget' );
		}

		WidgetsRepository::save_draft( $id, $name, self::settings_from_post() );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in guard().
		$publish = isset( $_POST['publish'] );

		if ( $publish ) {
			WidgetsRepository::publish( $id );
		}

		wp_safe_redirect(
			add_query_arg(
				$publish ? 'published' : 'saved',
				'1',
				admin_url( 'admin.php?page=gbrw-widgets&edit=' . $id )
			)
		);
		exit;
	}

	/**
	 * Delete a widget.
	 *
	 * @return void
	 */
	public static function handle_delete(): void {
		self::guard( 'gbrw_delete_widget' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in guard().
		$id = isset( $_POST['widget_id'] ) ? (int) $_POST['widget_id'] : 0;

		if ( $id > 0 ) {
			WidgetsRepository::delete( $id );
		}

		wp_safe_redirect( add_query_arg( 'deleted', '1', admin_url( 'admin.php?page=gbrw-widgets' ) ) );
		exit;
	}

	/**
	 * Read submitted settings through the schema, which discards anything unknown.
	 *
	 * @return array<string, mixed>
	 */
	private static function settings_from_post(): array {
		$raw = array();

		foreach ( array_keys( SettingsSchema::defaults() ) as $key ) {
			$fallback = SettingsSchema::defaults()[ $key ];

			if ( is_bool( $fallback ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in guard().
				$raw[ $key ] = isset( $_POST[ $key ] );
				continue;
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in guard().
			if ( isset( $_POST[ $key ] ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in guard().
				$raw[ $key ] = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
			}
		}

		return $raw;
	}

	/**
	 * Route between list and editor.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'google-reviews-widget' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing.
		$edit = isset( $_GET['edit'] ) ? (int) $_GET['edit'] : 0;

		if ( $edit > 0 ) {
			self::render_editor( $edit );
			return;
		}

		self::render_list();
	}

	/**
	 * The widget list.
	 *
	 * @return void
	 */
	private static function render_list(): void {
		$widgets = WidgetsRepository::all();

		echo '<div class="wrap gbrw-wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Widgets', 'google-reviews-widget' ) . '</h1>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline;margin-left:8px;">';
		wp_nonce_field( 'gbrw_create_widget' );
		echo '<input type="hidden" name="action" value="gbrw_create_widget" />';
		echo '<button type="submit" class="page-title-action">' . esc_html__( 'Add widget', 'google-reviews-widget' ) . '</button>';
		echo '</form><hr class="wp-header-end">';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag.
		if ( isset( $_GET['deleted'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Widget deleted.', 'google-reviews-widget' ) . '</p></div>';
		}

		if ( empty( $widgets ) ) {
			echo '<div class="gbrw-panel gbrw-panel--muted">';
			echo '<p><strong>' . esc_html__( 'No widgets yet.', 'google-reviews-widget' ) . '</strong></p>';
			echo '<p>' . esc_html__( 'Create one, choose a layout, publish it, then paste its shortcode into any page.', 'google-reviews-widget' ) . '</p>';
			echo '</div></div>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Name', 'google-reviews-widget' ) . '</th>';
		echo '<th>' . esc_html__( 'Layout', 'google-reviews-widget' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'google-reviews-widget' ) . '</th>';
		echo '<th>' . esc_html__( 'Shortcode', 'google-reviews-widget' ) . '</th>';
		echo '<th></th></tr></thead><tbody>';

		foreach ( $widgets as $widget ) {
			$edit_url = admin_url( 'admin.php?page=gbrw-widgets&edit=' . (int) $widget->id );

			echo '<tr>';
			echo '<td><strong><a href="' . esc_url( $edit_url ) . '">' . esc_html( $widget->name ) . '</a></strong></td>';
			echo '<td>' . esc_html( ucfirst( (string) $widget->layout_type ) ) . '</td>';
			echo '<td>' . esc_html( self::status_label( (string) $widget->status ) ) . '</td>';
			echo '<td><code>[google_reviews_widget id="' . (int) $widget->id . '"]</code></td>';
			echo '<td style="text-align:right"><a class="button" href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Edit', 'google-reviews-widget' ) . '</a></td>';
			echo '</tr>';
		}

		echo '</tbody></table></div>';
	}

	/**
	 * Human label for a status.
	 *
	 * @param string $status Stored status.
	 * @return string
	 */
	private static function status_label( string $status ): string {
		$labels = array(
			'draft'     => __( 'Draft — not visible yet', 'google-reviews-widget' ),
			'published' => __( 'Published', 'google-reviews-widget' ),
			'paused'    => __( 'Paused', 'google-reviews-widget' ),
		);

		return $labels[ $status ] ?? $status;
	}

	/**
	 * The editor.
	 *
	 * @param int $id Widget ID.
	 * @return void
	 */
	private static function render_editor( int $id ): void {
		$widget = WidgetsRepository::find( $id );

		if ( ! $widget ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Widget not found', 'google-reviews-widget' ) . '</h1></div>';
			return;
		}

		$s = WidgetsRepository::draft_settings( $widget );

		echo '<div class="wrap gbrw-wrap">';
		echo '<h1>' . esc_html__( 'Edit widget', 'google-reviews-widget' ) . '</h1>';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag.
		if ( isset( $_GET['published'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Published. This widget is now live wherever its shortcode appears.', 'google-reviews-widget' ) . '</p></div>';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag.
		if ( isset( $_GET['saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Draft saved. Visitors still see the last published version.', 'google-reviews-widget' ) . '</p></div>';
		}

		if ( 'published' !== $widget->status ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'This widget has never been published, so its shortcode will not display anything yet.', 'google-reviews-widget' ) . '</p></div>';
		}

		echo '<div class="gbrw-editor">';

		// --- Settings column ---
		echo '<form class="gbrw-editor__form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'gbrw_save_widget' );
		echo '<input type="hidden" name="action" value="gbrw_save_widget" />';
		echo '<input type="hidden" name="widget_id" value="' . (int) $id . '" />';

		echo '<div class="gbrw-panel"><h2>' . esc_html__( 'Content', 'google-reviews-widget' ) . '</h2><table class="form-table" role="presentation"><tbody>';
		self::text_row( 'widget_name', __( 'Widget name', 'google-reviews-widget' ), (string) $widget->name );
		self::select_row(
			'layout',
			__( 'Layout', 'google-reviews-widget' ),
			$s['layout'],
			array(
				'grid'     => __( 'Grid', 'google-reviews-widget' ),
				'list'     => __( 'List', 'google-reviews-widget' ),
				'carousel' => __( 'Carousel', 'google-reviews-widget' ),
				'badge'    => __( 'Rating badge', 'google-reviews-widget' ),
			)
		);
		self::number_row( 'max_reviews', __( 'Maximum reviews', 'google-reviews-widget' ), (int) $s['max_reviews'], 1, 100 );
		self::select_row(
			'min_rating',
			__( 'Minimum rating', 'google-reviews-widget' ),
			(string) $s['min_rating'],
			array(
				'1' => __( 'Show all ratings', 'google-reviews-widget' ),
				'3' => __( '3 stars and above', 'google-reviews-widget' ),
				'4' => __( '4 stars and above', 'google-reviews-widget' ),
				'5' => __( '5 stars only', 'google-reviews-widget' ),
			)
		);
		self::select_row(
			'order',
			__( 'Order', 'google-reviews-widget' ),
			$s['order'],
			array(
				'newest'  => __( 'Newest first', 'google-reviews-widget' ),
				'oldest'  => __( 'Oldest first', 'google-reviews-widget' ),
				'highest' => __( 'Highest rated first', 'google-reviews-widget' ),
				'longest' => __( 'Longest first', 'google-reviews-widget' ),
				'random'  => __( 'Random', 'google-reviews-widget' ),
			)
		);
		self::check_row( 'require_text', __( 'Only reviews with text', 'google-reviews-widget' ), (bool) $s['require_text'] );
		echo '</tbody></table></div>';

		echo '<div class="gbrw-panel"><h2>' . esc_html__( 'Layout', 'google-reviews-widget' ) . '</h2><table class="form-table" role="presentation"><tbody>';
		self::number_row( 'columns_desktop', __( 'Columns — desktop', 'google-reviews-widget' ), (int) $s['columns_desktop'], 1, 6 );
		self::number_row( 'columns_tablet', __( 'Columns — tablet', 'google-reviews-widget' ), (int) $s['columns_tablet'], 1, 4 );
		self::number_row( 'columns_mobile', __( 'Columns — mobile', 'google-reviews-widget' ), (int) $s['columns_mobile'], 1, 2 );
		self::number_row( 'gap', __( 'Gap between cards (px)', 'google-reviews-widget' ), (int) $s['gap'], 0, 80 );
		self::number_row( 'max_width', __( 'Maximum width (px, 0 = full)', 'google-reviews-widget' ), (int) $s['max_width'], 0, 3000 );
		echo '</tbody></table></div>';

		echo '<div class="gbrw-panel"><h2>' . esc_html__( 'Appearance', 'google-reviews-widget' ) . '</h2><table class="form-table" role="presentation"><tbody>';
		self::color_row( 'card_background', __( 'Card background', 'google-reviews-widget' ), (string) $s['card_background'] );
		self::color_row( 'card_border', __( 'Card border', 'google-reviews-widget' ), (string) $s['card_border'] );
		self::color_row( 'text_color', __( 'Text colour', 'google-reviews-widget' ), (string) $s['text_color'] );
		self::color_row( 'muted_color', __( 'Muted text colour', 'google-reviews-widget' ), (string) $s['muted_color'] );
		self::color_row( 'star_color', __( 'Star colour', 'google-reviews-widget' ), (string) $s['star_color'] );
		self::number_row( 'card_radius', __( 'Corner radius (px)', 'google-reviews-widget' ), (int) $s['card_radius'], 0, 40 );
		self::number_row( 'card_padding', __( 'Card padding (px)', 'google-reviews-widget' ), (int) $s['card_padding'], 0, 60 );
		self::number_row( 'font_size', __( 'Font size (px)', 'google-reviews-widget' ), (int) $s['font_size'], 10, 28 );
		self::check_row( 'card_shadow', __( 'Card shadow', 'google-reviews-widget' ), (bool) $s['card_shadow'] );
		self::check_row( 'inherit_font', __( 'Use the theme\'s font', 'google-reviews-widget' ), (bool) $s['inherit_font'] );
		echo '</tbody></table></div>';

		echo '<div class="gbrw-panel"><h2>' . esc_html__( 'What to show', 'google-reviews-widget' ) . '</h2><table class="form-table" role="presentation"><tbody>';
		self::check_row( 'show_header', __( 'Rating summary header', 'google-reviews-widget' ), (bool) $s['show_header'] );
		self::check_row( 'show_avatar', __( 'Reviewer photo', 'google-reviews-widget' ), (bool) $s['show_avatar'] );
		self::check_row( 'show_rating', __( 'Stars on each review', 'google-reviews-widget' ), (bool) $s['show_rating'] );
		self::check_row( 'show_date', __( 'Review date', 'google-reviews-widget' ), (bool) $s['show_date'] );
		self::check_row( 'show_reply', __( 'Owner replies', 'google-reviews-widget' ), (bool) $s['show_reply'] );
		self::number_row( 'text_limit', __( 'Trim review text after (characters, 0 = never)', 'google-reviews-widget' ), (int) $s['text_limit'], 0, 2000 );
		echo '</tbody></table></div>';

		echo '<div class="gbrw-panel"><h2>' . esc_html__( 'Carousel', 'google-reviews-widget' ) . '</h2><table class="form-table" role="presentation"><tbody>';
		self::check_row( 'autoplay', __( 'Autoplay', 'google-reviews-widget' ), (bool) $s['autoplay'] );
		self::number_row( 'autoplay_interval', __( 'Autoplay interval (ms)', 'google-reviews-widget' ), (int) $s['autoplay_interval'], 1500, 30000 );
		self::check_row( 'show_arrows', __( 'Arrows', 'google-reviews-widget' ), (bool) $s['show_arrows'] );
		self::check_row( 'show_dots', __( 'Dots', 'google-reviews-widget' ), (bool) $s['show_dots'] );
		echo '<tr><td colspan="2"><p class="description">' . esc_html__( 'Autoplay is switched off automatically for visitors who prefer reduced motion.', 'google-reviews-widget' ) . '</p></td></tr>';
		echo '</tbody></table></div>';

		echo '<p class="gbrw-actions">';
		submit_button( __( 'Save draft', 'google-reviews-widget' ), 'secondary', 'save', false );
		echo ' ';
		submit_button( __( 'Publish changes', 'google-reviews-widget' ), 'primary', 'publish', false );
		echo '</p>';
		echo '</form>';

		// --- Preview column ---
		echo '<div class="gbrw-editor__preview">';
		echo '<div class="gbrw-panel">';
		echo '<h2>' . esc_html__( 'Preview', 'google-reviews-widget' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Rendered by the same code as the front end. Save the draft to refresh it.', 'google-reviews-widget' ) . '</p>';
		echo self::preview_iframe( $id, $s ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built and escaped in preview_iframe().
		echo '<h3>' . esc_html__( 'Shortcode', 'google-reviews-widget' ) . '</h3>';
		echo '<p><input type="text" class="large-text code" readonly onfocus="this.select()" value="' . esc_attr( '[google_reviews_widget id="' . $id . '"]' ) . '" /></p>';
		echo '<p class="description">' . esc_html__( 'Paste this into a Divi Code Module, an Elementor Shortcode widget, or a Gutenberg Shortcode block.', 'google-reviews-widget' ) . '</p>';
		echo '</div>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'' . esc_js( __( 'Delete this widget? Any page using its shortcode will stop showing reviews.', 'google-reviews-widget' ) ) . '\');">';
		wp_nonce_field( 'gbrw_delete_widget' );
		echo '<input type="hidden" name="action" value="gbrw_delete_widget" />';
		echo '<input type="hidden" name="widget_id" value="' . (int) $id . '" />';
		echo '<button type="submit" class="button button-link-delete">' . esc_html__( 'Delete widget', 'google-reviews-widget' ) . '</button>';
		echo '</form>';

		echo '</div></div></div>';
	}

	/**
	 * Render the preview into a sandboxed iframe.
	 *
	 * An iframe is used here purely so the admin stylesheet cannot bleed into the
	 * preview; the widget itself is rendered by the production renderer.
	 *
	 * @param int                  $id       Widget ID.
	 * @param array<string, mixed> $settings Draft settings.
	 * @return string
	 */
	private static function preview_iframe( int $id, array $settings ): string {
		$widget_html = Renderer::render( $id, $settings );

		// These tags belong to the self-contained document inside the iframe, not
		// to the admin page, so the enqueue APIs do not apply to them.
		// phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet, WordPress.WP.EnqueuedResources.NonEnqueuedScript
		$doc  = '<!doctype html><html><head><meta charset="utf-8">';
		$doc .= '<link rel="stylesheet" href="' . esc_url( GBRW_PLUGIN_URL . 'assets/css/widget.css?v=' . GBRW_VERSION ) . '">';
		$doc .= '<style>body{margin:0;padding:20px;background:#f6f7f7;font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;}</style>';
		$doc .= '</head><body>' . $widget_html;

		if ( 'carousel' === $settings['layout'] ) {
			$doc .= '<script src="' . esc_url( GBRW_PLUGIN_URL . 'assets/js/carousel.js?v=' . GBRW_VERSION ) . '"></script>';
		}
		// phpcs:enable WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet, WordPress.WP.EnqueuedResources.NonEnqueuedScript

		$doc .= '</body></html>';

		return '<iframe class="gbrw-preview-frame" title="' . esc_attr__( 'Widget preview', 'google-reviews-widget' ) . '" srcdoc="' . esc_attr( $doc ) . '"></iframe>';
	}

	// --- Field helpers -------------------------------------------------------

	/**
	 * Text input row.
	 *
	 * @param string $name  Field name.
	 * @param string $label Row label.
	 * @param string $value Current value.
	 * @return void
	 */
	private static function text_row( string $name, string $label, string $value ): void {
		printf(
			'<tr><th scope="row"><label for="gbrw-%1$s">%2$s</label></th><td><input type="text" id="gbrw-%1$s" name="%1$s" class="regular-text" value="%3$s" /></td></tr>',
			esc_attr( $name ),
			esc_html( $label ),
			esc_attr( $value )
		);
	}

	/**
	 * Number input row.
	 *
	 * @param string $name  Field name.
	 * @param string $label Row label.
	 * @param int    $value Current value.
	 * @param int    $min   Minimum.
	 * @param int    $max   Maximum.
	 * @return void
	 */
	private static function number_row( string $name, string $label, int $value, int $min, int $max ): void {
		printf(
			'<tr><th scope="row"><label for="gbrw-%1$s">%2$s</label></th><td><input type="number" id="gbrw-%1$s" name="%1$s" value="%3$d" min="%4$d" max="%5$d" class="small-text" /></td></tr>',
			esc_attr( $name ),
			esc_html( $label ),
			(int) $value,
			(int) $min,
			(int) $max
		);
	}

	/**
	 * Colour input row.
	 *
	 * @param string $name  Field name.
	 * @param string $label Row label.
	 * @param string $value Current value.
	 * @return void
	 */
	private static function color_row( string $name, string $label, string $value ): void {
		printf(
			'<tr><th scope="row"><label for="gbrw-%1$s">%2$s</label></th><td><input type="color" id="gbrw-%1$s" name="%1$s" value="%3$s" /> <code>%3$s</code></td></tr>',
			esc_attr( $name ),
			esc_html( $label ),
			esc_attr( $value )
		);
	}

	/**
	 * Checkbox row.
	 *
	 * @param string $name    Field name.
	 * @param string $label   Row label.
	 * @param bool   $checked Current value.
	 * @return void
	 */
	private static function check_row( string $name, string $label, bool $checked ): void {
		printf(
			'<tr><th scope="row">%2$s</th><td><label><input type="checkbox" name="%1$s" value="1"%3$s> %4$s</label></td></tr>',
			esc_attr( $name ),
			esc_html( $label ),
			checked( $checked, true, false ),
			esc_html__( 'Show', 'google-reviews-widget' )
		);
	}

	/**
	 * Select row.
	 *
	 * @param string                   $name    Field name.
	 * @param string                   $label   Row label.
	 * @param string                   $value   Current value.
	 * @param array<array-key, string> $options Value => label. Numeric-looking
	 *                                          keys become integers in PHP, so
	 *                                          both key types must be accepted.
	 * @return void
	 */
	private static function select_row( string $name, string $label, string $value, array $options ): void {
		printf(
			'<tr><th scope="row"><label for="gbrw-%1$s">%2$s</label></th><td><select id="gbrw-%1$s" name="%1$s">',
			esc_attr( $name ),
			esc_html( $label )
		);

		foreach ( $options as $option_value => $option_label ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( (string) $option_value ),
				selected( (string) $value, (string) $option_value, false ),
				esc_html( $option_label )
			);
		}

		echo '</select></td></tr>';
	}
}
