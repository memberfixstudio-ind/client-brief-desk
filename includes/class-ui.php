<?php
namespace ClientBriefDesk;

defined( 'ABSPATH' ) || exit;

final class UI {

	private static $screen = '';

	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'front_assets' ) );
		add_shortcode( 'client_brief_desk', array( __CLASS__, 'shortcode' ) );
	}

	public static function menu() {
		self::$screen = add_menu_page(
			__( 'Client Brief Desk', 'client-brief-desk' ),
			__( 'Client Brief Desk', 'client-brief-desk' ),
			'manage_options', 'client-brief-desk', array( __CLASS__, 'render' ), 'dashicons-clipboard'
		);
	}

	public static function admin_assets( $hook ) {
		if ( self::$screen && self::$screen === $hook && current_user_can( 'manage_options' ) ) {
			self::enqueue();
		}
	}

	public static function front_assets() {
		if ( is_user_logged_in() && is_singular() && has_shortcode( (string) get_post_field( 'post_content', get_queried_object_id() ), 'client_brief_desk' ) ) {
			self::enqueue();
		}
	}

	private static function enqueue() {
		wp_enqueue_style( 'client-brief-desk', plugins_url( 'assets/desk.css', CBD_FILE ), array(), CBD_VERSION );
		wp_enqueue_script( 'client-brief-desk', plugins_url( 'assets/admin.js', CBD_FILE ), array( 'wp-api-fetch', 'wp-i18n' ), CBD_VERSION, true );
		wp_localize_script( 'client-brief-desk', 'clientBriefDesk', array(
			'root'          => rest_url( 'client-brief-desk/v1/' ),
			'nonce'         => wp_create_nonce( 'wp_rest' ),
			'isAdmin'       => current_user_can( 'manage_options' ),
			'currentUserId' => get_current_user_id(),
			'timezone'      => wp_timezone_string(),
		) );
		wp_set_script_translations( 'client-brief-desk', 'client-brief-desk', plugin_dir_path( CBD_FILE ) . 'languages' );
	}

	public static function render() {
		if ( current_user_can( 'manage_options' ) ) {
			echo self::shell(); // All shell strings and attributes are escaped below.
		}
	}

	public static function shortcode() {
		if ( ! is_user_logged_in() ) {
			return '<p><a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">' . esc_html__( 'Log in to view your briefs', 'client-brief-desk' ) . '</a></p>';
		}
		return self::shell();
	}

	private static function shell() {
		self::enqueue();
		ob_start();
		// Shortcodes in widgets or templates can render after the normal stylesheet pass.
		if ( did_action( 'wp_head' ) && ! wp_style_is( 'client-brief-desk', 'done' ) ) {
			wp_print_styles( 'client-brief-desk' );
		}
		?>
		<div id="cbd-app" class="cbd-app">
			<header class="cbd-header">
				<div><p class="cbd-eyebrow"><span class="cbd-mark" aria-hidden="true">▤</span> <?php esc_html_e( 'Client Brief Desk', 'client-brief-desk' ); ?></p>
					<h1><?php echo current_user_can( 'manage_options' ) ? esc_html__( 'Good work starts here.', 'client-brief-desk' ) : esc_html__( 'Let’s get the details right.', 'client-brief-desk' ); ?></h1>
					<p class="cbd-intro"><?php echo current_user_can( 'manage_options' ) ? esc_html__( 'Clear questions. Considered answers. One place to review.', 'client-brief-desk' ) : esc_html__( 'Answer at your own pace, save your progress, then submit for review.', 'client-brief-desk' ); ?></p>
				</div>
				<?php if ( current_user_can( 'manage_options' ) ) : ?>
					<button type="button" id="cbd-new" class="cbd-button cbd-primary"><?php esc_html_e( 'New brief', 'client-brief-desk' ); ?></button>
				<?php endif; ?>
			</header>
			<noscript><p><?php esc_html_e( 'Enable JavaScript to load and work on your briefs.', 'client-brief-desk' ); ?></p></noscript>
			<p id="cbd-announcement" class="cbd-notice" role="status" aria-atomic="true"></p>
			<div id="cbd-list-error" class="cbd-notice cbd-error" role="alert" hidden></div>
			<section class="cbd-queue" aria-labelledby="cbd-queue-heading">
				<div class="cbd-section-head"><div><p class="cbd-eyebrow"><?php esc_html_e( 'The work in progress', 'client-brief-desk' ); ?></p><h2 id="cbd-queue-heading"><?php echo current_user_can( 'manage_options' ) ? esc_html__( 'Briefs', 'client-brief-desk' ) : esc_html__( 'Your briefs', 'client-brief-desk' ); ?></h2></div><p id="cbd-total" class="cbd-meta"></p></div>
				<form id="cbd-filters" class="cbd-filters" role="search" aria-label="<?php esc_attr_e( 'Find briefs', 'client-brief-desk' ); ?>">
					<div class="cbd-field cbd-search"><label for="cbd-search"><?php esc_html_e( 'Search titles', 'client-brief-desk' ); ?></label><input id="cbd-search" name="search" type="search" maxlength="120" placeholder="<?php esc_attr_e( 'A project or brief title', 'client-brief-desk' ); ?>"></div>
					<div class="cbd-field"><label for="cbd-status"><?php esc_html_e( 'Status', 'client-brief-desk' ); ?></label><select id="cbd-status" name="status"></select></div>
					<button type="submit" class="cbd-button"><?php esc_html_e( 'Search', 'client-brief-desk' ); ?></button><button type="button" id="cbd-refresh" class="cbd-button cbd-quiet"><?php esc_html_e( 'Refresh', 'client-brief-desk' ); ?></button>
				</form>
				<div id="cbd-list" aria-busy="true"><p class="cbd-empty"><?php esc_html_e( 'Loading briefs…', 'client-brief-desk' ); ?></p></div>
				<nav class="cbd-pagination" aria-label="<?php esc_attr_e( 'Brief pages', 'client-brief-desk' ); ?>"><p id="cbd-page" class="cbd-meta" role="status"></p><div class="cbd-actions"><button type="button" id="cbd-prev" class="cbd-button" disabled><?php esc_html_e( 'Previous', 'client-brief-desk' ); ?></button><button type="button" id="cbd-next" class="cbd-button" disabled><?php esc_html_e( 'Next', 'client-brief-desk' ); ?></button></div></nav>
			</section>
			<?php if ( current_user_can( 'manage_options' ) ) : ?>
				<details class="cbd-setup"><summary><?php esc_html_e( 'Set up the client portal', 'client-brief-desk' ); ?></summary><p><?php esc_html_e( 'Add this shortcode to a WordPress page and share that page with your clients:', 'client-brief-desk' ); ?> <code>[client_brief_desk]</code></p><p><?php esc_html_e( 'Each client needs an existing WordPress account. They must log in with the account assigned to the brief. No email is sent by this plugin.', 'client-brief-desk' ); ?></p></details>
			<?php endif; ?>
			<footer class="cbd-footer"><?php esc_html_e( 'Intake and review, kept in WordPress.', 'client-brief-desk' ); ?></footer>
			<dialog id="cbd-panel" class="cbd-app cbd-panel" aria-labelledby="cbd-panel-title">
				<div class="cbd-panel-head"><p id="cbd-panel-kicker" class="cbd-eyebrow"></p><button type="button" id="cbd-close" class="cbd-button cbd-quiet"><?php esc_html_e( 'Close', 'client-brief-desk' ); ?></button></div>
				<h2 id="cbd-panel-title" tabindex="-1"></h2>
				<div id="cbd-panel-error" class="cbd-notice cbd-error" role="alert" hidden></div>
				<p id="cbd-panel-notice" class="cbd-notice" role="status" aria-atomic="true"></p>
				<div id="cbd-conflict" class="cbd-conflict" hidden><p><?php esc_html_e( 'This brief changed elsewhere. Your typed content is still here. Load the latest version, compare it with your draft, then choose your next action.', 'client-brief-desk' ); ?></p><button type="button" id="cbd-reload" class="cbd-button"><?php esc_html_e( 'Load latest (keep my draft)', 'client-brief-desk' ); ?></button></div>
				<div id="cbd-panel-content"></div>
			</dialog>
		</div>
		<?php
		return ob_get_clean();
	}
}
