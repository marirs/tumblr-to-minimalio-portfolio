<?php
/**
 * Main Importer class
 *
 * @package TumblrToMinimalioPortfolio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TTMP_Importer {

	const API_BASE       = 'https://api.tumblr.com/v2/blog/';
	const PER_PAGE       = 20;
	const OPTION_API_KEY  = 'ttmp_tumblr_api_key';
	const OPTION_BLOG_URL = 'ttmp_tumblr_blog_url';
	const OPTION_USE_AI   = 'ttmp_use_ai';
	const OPTION_AI_CATEGORIES       = 'ttmp_ai_categories';
	const OPTION_AI_CATEGORIES_MODE  = 'ttmp_ai_categories_mode';
	const OPTION_AI_ORDER            = 'ttmp_ai_service_order';
	const OPTION_AI_TEXT_ORDER       = 'ttmp_ai_text_order';
	const OPTION_POST_STATUS         = 'ttmp_post_status';
	const OPTION_POST_AUTHOR         = 'ttmp_post_author';

	public static function init() {
		add_action( 'admin_init', [ __CLASS__, 'register_importer' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
		add_action( 'wp_ajax_ttmp_fetch_posts', [ __CLASS__, 'ajax_fetch_posts' ] );
		add_action( 'wp_ajax_ttmp_import_post', [ __CLASS__, 'ajax_import_post' ] );
		add_action( 'wp_ajax_ttmp_test_api', [ __CLASS__, 'ajax_test_api' ] );
	}

	public static function register_importer() {
		if ( ! function_exists( 'register_importer' ) ) {
			return;
		}
		register_importer(
			'ttmp-importer',
			__( 'Tumblr to Minimalio Portfolio', 'tumblr-to-minimalio-portfolio' ),
			__( 'Import Tumblr posts (photos, videos, text) into the Minimalio Portfolio custom post type with optional AI-powered SEO.', 'tumblr-to-minimalio-portfolio' ),
			[ __CLASS__, 'dispatch' ]
		);
	}

	public static function enqueue_assets( $hook ) {
		if ( ! isset( $_GET['import'] ) || 'ttmp-importer' !== $_GET['import'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		wp_enqueue_style( 'ttmp-importer', TTMP_PLUGIN_URL . 'assets/admin/ttmp-importer.css', [], TTMP_VERSION );
		wp_enqueue_script( 'ttmp-importer', TTMP_PLUGIN_URL . 'assets/admin/ttmp-importer.js', [ 'jquery', 'jquery-ui-sortable' ], TTMP_VERSION, true );

		$chain = TTMP_AI_Chain::get_instance();

		wp_localize_script( 'ttmp-importer', 'ttmpImporter', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'ttmp_import' ),
			'useAI'   => (bool) get_option( self::OPTION_USE_AI, false ),
			'aiChain' => $chain->get_chain_display(),
			'hasAI'   => $chain->has_ai_services(),
			'aiCategories'     => (bool) get_option( self::OPTION_AI_CATEGORIES, false ),
			'aiCategoriesMode' => get_option( self::OPTION_AI_CATEGORIES_MODE, 'existing' ),
			'i18n'    => [
				'fetching'      => __( 'Fetching...', 'tumblr-to-minimalio-portfolio' ),
				'importing'     => __( 'Importing...', 'tumblr-to-minimalio-portfolio' ),
				'complete'      => __( 'Import complete!', 'tumblr-to-minimalio-portfolio' ),
				'imported'      => __( 'Imported', 'tumblr-to-minimalio-portfolio' ),
				'skipped'       => __( 'Skipped', 'tumblr-to-minimalio-portfolio' ),
				'failed'        => __( 'Failed', 'tumblr-to-minimalio-portfolio' ),
				'noPostsFound'  => __( 'No posts with images or videos found.', 'tumblr-to-minimalio-portfolio' ),
				'confirmImport' => __( 'Start importing the selected posts?', 'tumblr-to-minimalio-portfolio' ),
			],
		] );
	}

	public static function dispatch() {
		$step = isset( $_GET['step'] ) ? absint( $_GET['step'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['ttmp_settings_nonce'] ) ) {
			check_admin_referer( 'ttmp_settings', 'ttmp_settings_nonce' );
			self::save_settings();
			$step = 1;
		}

		if ( 0 === $step ) {
			self::render_settings_page();
		} else {
			self::render_fetch_page();
		}
	}

	private static function save_settings() {
		// Nonce already verified in dispatch() via check_admin_referer().
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['ttmp_api_key'] ) ) {
			update_option( self::OPTION_API_KEY, sanitize_text_field( wp_unslash( $_POST['ttmp_api_key'] ) ) );
		}
		if ( isset( $_POST['ttmp_blog_url'] ) ) {
			$blog_url = sanitize_text_field( wp_unslash( $_POST['ttmp_blog_url'] ) );
			$blog_url = str_replace( [ 'https://', 'http://' ], '', $blog_url );
			$blog_url = rtrim( $blog_url, '/' );
			update_option( self::OPTION_BLOG_URL, $blog_url );
		}

		update_option( self::OPTION_USE_AI, isset( $_POST['ttmp_use_ai'] ) ? 1 : 0 );

		if ( isset( $_POST['ttmp_gemini_key'] ) ) {
			update_option( TTMP_Gemini::OPTION_KEY, sanitize_text_field( wp_unslash( $_POST['ttmp_gemini_key'] ) ) );
		}
		if ( isset( $_POST['ttmp_cloudflare_account_id'] ) ) {
			update_option( TTMP_Cloudflare::OPTION_ACCOUNT_ID, sanitize_text_field( wp_unslash( $_POST['ttmp_cloudflare_account_id'] ) ) );
		}
		if ( isset( $_POST['ttmp_cloudflare_api_token'] ) ) {
			update_option( TTMP_Cloudflare::OPTION_API_TOKEN, sanitize_text_field( wp_unslash( $_POST['ttmp_cloudflare_api_token'] ) ) );
		}
		if ( isset( $_POST['ttmp_openai_key'] ) ) {
			update_option( TTMP_OpenAI::OPTION_KEY, sanitize_text_field( wp_unslash( $_POST['ttmp_openai_key'] ) ) );
		}

		if ( isset( $_POST['ttmp_ai_service_order'] ) ) {
			$order = array_map( 'sanitize_text_field', explode( ',', sanitize_text_field( wp_unslash( $_POST['ttmp_ai_service_order'] ) ) ) );
			$valid = [ 'gemini', 'cloudflare', 'openai' ];
			$order = array_values( array_intersect( $order, $valid ) );
			if ( ! empty( $order ) ) {
				update_option( self::OPTION_AI_ORDER, $order );
			}
		}

		if ( isset( $_POST['ttmp_ai_text_order'] ) ) {
			$text_order = array_map( 'sanitize_text_field', explode( ',', sanitize_text_field( wp_unslash( $_POST['ttmp_ai_text_order'] ) ) ) );
			$valid_text = [ 'chatgpt_text', 'gemini_text' ];
			$text_order = array_values( array_intersect( $text_order, $valid_text ) );
			if ( ! empty( $text_order ) ) {
				update_option( self::OPTION_AI_TEXT_ORDER, $text_order );
			}
		}

		update_option( self::OPTION_AI_CATEGORIES, isset( $_POST['ttmp_ai_categories'] ) ? 1 : 0 );
		$cat_mode = isset( $_POST['ttmp_ai_categories_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['ttmp_ai_categories_mode'] ) ) : 'existing';
		update_option( self::OPTION_AI_CATEGORIES_MODE, $cat_mode );

		// Post status
		$post_status = isset( $_POST['ttmp_post_status'] ) ? sanitize_text_field( wp_unslash( $_POST['ttmp_post_status'] ) ) : 'publish';
		if ( ! in_array( $post_status, [ 'publish', 'draft' ], true ) ) {
			$post_status = 'publish';
		}
		update_option( self::OPTION_POST_STATUS, $post_status );

		// Post author
		$post_author = isset( $_POST['ttmp_post_author'] ) ? absint( $_POST['ttmp_post_author'] ) : get_current_user_id();
		update_option( self::OPTION_POST_AUTHOR, $post_author );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		TTMP_AI_Chain::reset();
	}

	private static function render_settings_page() {
		$api_key   = get_option( self::OPTION_API_KEY, '' );
		$blog_url  = get_option( self::OPTION_BLOG_URL, '' );
		$use_ai    = get_option( self::OPTION_USE_AI, false );
		$gemini_key    = get_option( TTMP_Gemini::OPTION_KEY, '' );
		$cf_account_id = get_option( TTMP_Cloudflare::OPTION_ACCOUNT_ID, '' );
		$cf_api_token  = get_option( TTMP_Cloudflare::OPTION_API_TOKEN, '' );
		$openai_key    = get_option( TTMP_OpenAI::OPTION_KEY, '' );
		$ai_categories      = get_option( self::OPTION_AI_CATEGORIES, false );
		$ai_categories_mode = get_option( self::OPTION_AI_CATEGORIES_MODE, 'existing' );
		$service_order      = get_option( self::OPTION_AI_ORDER, [ 'gemini', 'cloudflare', 'openai' ] );
		$text_order         = get_option( self::OPTION_AI_TEXT_ORDER, [ 'chatgpt_text', 'gemini_text' ] );

		$service_cards = [
			'gemini' => [
				'label'       => __( 'Google Gemini', 'tumblr-to-minimalio-portfolio' ),
				'badge'       => 'free',
				'badge_label' => __( 'Free', 'tumblr-to-minimalio-portfolio' ),
				'desc'        => __( 'Free tier: 15 requests/min, 1,500/day. Best balance of quality and cost.', 'tumblr-to-minimalio-portfolio' ),
			],
			'cloudflare' => [
				'label'       => __( 'Cloudflare Workers AI', 'tumblr-to-minimalio-portfolio' ),
				'badge'       => 'free',
				'badge_label' => __( 'Free', 'tumblr-to-minimalio-portfolio' ),
				'desc'        => __( 'Free tier: ~100-200 images/day. Used as fallback if primary is rate-limited.', 'tumblr-to-minimalio-portfolio' ),
			],
			'openai' => [
				'label'       => __( 'OpenAI', 'tumblr-to-minimalio-portfolio' ),
				'badge'       => 'paid',
				'badge_label' => __( 'Paid', 'tumblr-to-minimalio-portfolio' ),
				'desc'        => __( 'Pay-per-use (~$0.001/image with GPT-4o-mini). Highest quality results.', 'tumblr-to-minimalio-portfolio' ),
			],
		];
		?>
		<div class="wrap ttmp-wrap">
			<h1><?php esc_html_e( 'Tumblr to Minimalio Portfolio — Settings', 'tumblr-to-minimalio-portfolio' ); ?></h1>
			<form method="post" action="">
				<?php wp_nonce_field( 'ttmp_settings', 'ttmp_settings_nonce' ); ?>

				<div class="ttmp-settings-section">
					<h2><?php esc_html_e( 'Tumblr API Settings', 'tumblr-to-minimalio-portfolio' ); ?></h2>
					<table class="form-table">
						<tr>
							<th><label for="ttmp_blog_url"><?php esc_html_e( 'Tumblr Blog URL', 'tumblr-to-minimalio-portfolio' ); ?></label></th>
							<td>
								<input type="text" id="ttmp_blog_url" name="ttmp_blog_url" value="<?php echo esc_attr( $blog_url ); ?>" class="regular-text" placeholder="yourblog.tumblr.com" />
								<p class="description"><?php esc_html_e( 'Enter your Tumblr blog URL (e.g., yourblog.tumblr.com)', 'tumblr-to-minimalio-portfolio' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><label for="ttmp_api_key"><?php esc_html_e( 'Tumblr OAuth Consumer Key', 'tumblr-to-minimalio-portfolio' ); ?></label></th>
							<td>
								<input type="text" id="ttmp_api_key" name="ttmp_api_key" value="<?php echo esc_attr( $api_key ); ?>" class="regular-text" />
								<p class="description">
									<?php
									/* translators: %s: link to Tumblr OAuth apps page */
									printf( esc_html__( 'Get your OAuth Consumer Key from %s. Register a new application, then copy the OAuth Consumer Key.', 'tumblr-to-minimalio-portfolio' ), '<a href="https://www.tumblr.com/oauth/apps" target="_blank">tumblr.com/oauth/apps</a>' ); ?>
								</p>
							</td>
						</tr>
					</table>
				</div>

				<div class="ttmp-settings-section">
					<h2><?php esc_html_e( 'AI-Powered SEO Generation', 'tumblr-to-minimalio-portfolio' ); ?></h2>
					<table class="form-table">
						<tr>
							<th><?php esc_html_e( 'Enable AI', 'tumblr-to-minimalio-portfolio' ); ?></th>
							<td>
								<label>
									<input type="checkbox" id="ttmp_use_ai" name="ttmp_use_ai" value="1" <?php checked( $use_ai ); ?> />
									<?php esc_html_e( 'Use AI to generate SEO titles & descriptions when missing', 'tumblr-to-minimalio-portfolio' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'When enabled, images without titles will be analyzed by AI. Configure at least one AI service below.', 'tumblr-to-minimalio-portfolio' ); ?></p>
							</td>
						</tr>
					</table>

					<div id="ttmp-ai-services" style="<?php echo $use_ai ? '' : 'display:none;'; ?>">
						<p class="description" style="margin-bottom:8px;"><span class="dashicons dashicons-sort" style="font-size:16px;vertical-align:text-bottom;"></span> <?php esc_html_e( 'Drag to reorder the failover priority, or use the arrow buttons.', 'tumblr-to-minimalio-portfolio' ); ?></p>
						<input type="hidden" id="ttmp_ai_service_order" name="ttmp_ai_service_order" value="<?php echo esc_attr( implode( ',', $service_order ) ); ?>" />

						<div id="ttmp-sortable-services">
						<?php
						$priority = 1;
						foreach ( $service_order as $svc_id ) :
							if ( ! isset( $service_cards[ $svc_id ] ) ) continue;
							$card = $service_cards[ $svc_id ];
						?>
						<div class="ttmp-ai-service-card" data-service="<?php echo esc_attr( $svc_id ); ?>">
							<div class="ttmp-service-header">
								<span class="ttmp-service-drag dashicons dashicons-menu"></span>
								<span class="ttmp-service-priority"><?php echo esc_html( $priority ); ?></span>
								<span class="ttmp-service-name"><?php echo esc_html( $card['label'] ); ?></span>
								<span class="ttmp-service-badge ttmp-badge-<?php echo esc_attr( $card['badge'] ); ?>"><?php echo esc_html( $card['badge_label'] ); ?></span>
								<span class="ttmp-service-arrows">
									<button type="button" class="button-link ttmp-move-up" title="<?php esc_attr_e( 'Move up', 'tumblr-to-minimalio-portfolio' ); ?>"><span class="dashicons dashicons-arrow-up-alt2"></span></button>
									<button type="button" class="button-link ttmp-move-down" title="<?php esc_attr_e( 'Move down', 'tumblr-to-minimalio-portfolio' ); ?>"><span class="dashicons dashicons-arrow-down-alt2"></span></button>
								</span>
							</div>
							<p class="description"><?php echo esc_html( $card['desc'] ); ?></p>
							<?php if ( 'gemini' === $svc_id ) : ?>
							<table class="form-table">
								<tr>
									<th><label for="ttmp_gemini_key"><?php esc_html_e( 'API Key', 'tumblr-to-minimalio-portfolio' ); ?></label></th>
									<td>
										<input type="password" id="ttmp_gemini_key" name="ttmp_gemini_key" value="<?php echo esc_attr( $gemini_key ); ?>" class="regular-text" />
										<button type="button" class="button ttmp-test-api" data-service="gemini"><?php esc_html_e( 'Test', 'tumblr-to-minimalio-portfolio' ); ?></button>
										<span class="ttmp-test-result" data-service="gemini"></span>
										<p class="description"><?php
										/* translators: %s: link to Google AI Studio API key page */
										printf( esc_html__( 'Get your free API key in 30 seconds from %s', 'tumblr-to-minimalio-portfolio' ), '<a href="https://aistudio.google.com/apikey" target="_blank">aistudio.google.com/apikey</a>' ); ?></p>
										<p class="description"><em><?php esc_html_e( '(Optional — leave blank if you don\'t want to use this service. Also powers Gemini text-only fallback.)', 'tumblr-to-minimalio-portfolio' ); ?></em></p>
									</td>
								</tr>
							</table>
							<?php elseif ( 'cloudflare' === $svc_id ) : ?>
							<table class="form-table">
								<tr>
									<th><label for="ttmp_cloudflare_account_id"><?php esc_html_e( 'Account ID', 'tumblr-to-minimalio-portfolio' ); ?></label></th>
									<td>
										<input type="text" id="ttmp_cloudflare_account_id" name="ttmp_cloudflare_account_id" value="<?php echo esc_attr( $cf_account_id ); ?>" class="regular-text" />
										<p class="description"><?php
										/* translators: %1$s: link to Cloudflare dashboard, %2$s: link to Workers & Pages */
										printf( esc_html__( 'Log in to %1$s → select any domain (or the main dashboard) → your Account ID is in the right sidebar under "API", or visit %2$s.', 'tumblr-to-minimalio-portfolio' ), '<a href="https://dash.cloudflare.com" target="_blank">dash.cloudflare.com</a>', '<a href="https://dash.cloudflare.com/?to=/:account/workers" target="_blank">Workers & Pages</a>' ); ?></p>
									</td>
								</tr>
								<tr>
									<th><label for="ttmp_cloudflare_api_token"><?php esc_html_e( 'API Token', 'tumblr-to-minimalio-portfolio' ); ?></label></th>
									<td>
										<input type="password" id="ttmp_cloudflare_api_token" name="ttmp_cloudflare_api_token" value="<?php echo esc_attr( $cf_api_token ); ?>" class="regular-text" />
										<button type="button" class="button ttmp-test-api" data-service="cloudflare"><?php esc_html_e( 'Test', 'tumblr-to-minimalio-portfolio' ); ?></button>
										<span class="ttmp-test-result" data-service="cloudflare"></span>
										<p class="description"><?php
										/* translators: %1$s: link to Cloudflare API tokens page, %2$s: permission name */
										printf( esc_html__( 'Go to %1$s → create a token → use the "Edit Cloudflare Workers" template (or create a custom token with %2$s permission). Copy the generated token.', 'tumblr-to-minimalio-portfolio' ), '<a href="https://dash.cloudflare.com/profile/api-tokens" target="_blank">My Profile → API Tokens</a>', '<strong>Account.Workers AI:Read</strong>' ); ?></p>
										<p class="description"><em><?php esc_html_e( '(Optional — leave blank if you don\'t want to use this service.)', 'tumblr-to-minimalio-portfolio' ); ?></em></p>
									</td>
								</tr>
							</table>
							<?php elseif ( 'openai' === $svc_id ) : ?>
							<table class="form-table">
								<tr>
									<th><label for="ttmp_openai_key"><?php esc_html_e( 'API Key', 'tumblr-to-minimalio-portfolio' ); ?></label></th>
									<td>
										<input type="password" id="ttmp_openai_key" name="ttmp_openai_key" value="<?php echo esc_attr( $openai_key ); ?>" class="regular-text" />
										<button type="button" class="button ttmp-test-api" data-service="openai"><?php esc_html_e( 'Test', 'tumblr-to-minimalio-portfolio' ); ?></button>
										<span class="ttmp-test-result" data-service="openai"></span>
										<p class="description"><?php
										/* translators: %s: link to OpenAI API keys page */
										printf( esc_html__( 'Get your API key from %s. Pay-per-use, no subscription needed.', 'tumblr-to-minimalio-portfolio' ), '<a href="https://platform.openai.com/api-keys" target="_blank">platform.openai.com/api-keys</a>' ); ?></p>
										<p class="description"><em><?php esc_html_e( '(Optional — leave blank if you don\'t want to use this service. Also powers ChatGPT text-only fallback.)', 'tumblr-to-minimalio-portfolio' ); ?></em></p>
									</td>
								</tr>
							</table>
							<?php endif; ?>
						</div>
						<?php $priority++; endforeach; ?>
						</div>

						<h3 style="margin-top:24px;"><?php esc_html_e( 'Text-Only AI Fallback (uses tags, no image)', 'tumblr-to-minimalio-portfolio' ); ?></h3>
						<p class="description" style="margin-bottom:8px;"><?php esc_html_e( 'When vision AI fails or is rate-limited, these services generate titles from your post tags. They reuse the API keys above — no extra setup needed.', 'tumblr-to-minimalio-portfolio' ); ?></p>
						<p class="description" style="margin-bottom:8px;"><span class="dashicons dashicons-sort" style="font-size:16px;vertical-align:text-bottom;"></span> <?php esc_html_e( 'Drag to reorder.', 'tumblr-to-minimalio-portfolio' ); ?></p>
						<input type="hidden" id="ttmp_ai_text_order" name="ttmp_ai_text_order" value="<?php echo esc_attr( implode( ',', $text_order ) ); ?>" />

						<?php
						$text_cards = [
							'chatgpt_text' => [
								'label' => __( 'ChatGPT (text)', 'tumblr-to-minimalio-portfolio' ),
								'desc'  => __( 'Uses your OpenAI API key. Sends only tags — fast (~1s) and very cheap (~$0.0001/request). Produces creative, natural-sounding titles.', 'tumblr-to-minimalio-portfolio' ),
								'key'   => 'openai',
							],
							'gemini_text' => [
								'label' => __( 'Gemini (text)', 'tumblr-to-minimalio-portfolio' ),
								'desc'  => __( 'Uses your Gemini API key. Sends only tags — fast and free. Good quality titles from tag context.', 'tumblr-to-minimalio-portfolio' ),
								'key'   => 'gemini',
							],
						];
						?>
						<div id="ttmp-sortable-text-services">
						<?php
						$text_priority = 1;
						foreach ( $text_order as $txt_id ) :
							if ( ! isset( $text_cards[ $txt_id ] ) ) continue;
							$tcard = $text_cards[ $txt_id ];
						?>
						<div class="ttmp-ai-service-card ttmp-text-service-card" data-service="<?php echo esc_attr( $txt_id ); ?>">
							<div class="ttmp-service-header">
								<span class="ttmp-service-drag dashicons dashicons-menu"></span>
								<span class="ttmp-service-priority ttmp-text-priority"><?php echo esc_html( $text_priority ); ?></span>
								<span class="ttmp-service-name"><?php echo esc_html( $tcard['label'] ); ?></span>
								<span class="ttmp-service-badge ttmp-badge-auto"><?php esc_html_e( 'Auto', 'tumblr-to-minimalio-portfolio' ); ?></span>
								<span class="ttmp-service-arrows">
									<button type="button" class="button-link ttmp-text-move-up" title="<?php esc_attr_e( 'Move up', 'tumblr-to-minimalio-portfolio' ); ?>"><span class="dashicons dashicons-arrow-up-alt2"></span></button>
									<button type="button" class="button-link ttmp-text-move-down" title="<?php esc_attr_e( 'Move down', 'tumblr-to-minimalio-portfolio' ); ?>"><span class="dashicons dashicons-arrow-down-alt2"></span></button>
								</span>
							</div>
							<p class="description"><?php echo esc_html( $tcard['desc'] ); ?></p>
							<p class="description"><em><?php
								/* translators: %s: AI service name (Gemini or OpenAI) */
								printf( esc_html__( 'Requires %s API key (configured above).', 'tumblr-to-minimalio-portfolio' ), '<strong>' . esc_html( 'gemini' === $tcard['key'] ? 'Gemini' : 'OpenAI' ) . '</strong>' ); ?></em></p>
						</div>
						<?php $text_priority++; endforeach; ?>
						</div>

						<div class="ttmp-ai-service-card">
							<h3><?php esc_html_e( 'AI Category Assignment', 'tumblr-to-minimalio-portfolio' ); ?></h3>
							<table class="form-table">
								<tr>
									<th><?php esc_html_e( 'Assign Categories', 'tumblr-to-minimalio-portfolio' ); ?></th>
									<td>
										<label>
											<input type="checkbox" id="ttmp_ai_categories" name="ttmp_ai_categories" value="1" <?php checked( $ai_categories ); ?> />
											<?php esc_html_e( 'Allow AI to assign categories to imported posts', 'tumblr-to-minimalio-portfolio' ); ?>
										</label>
									</td>
								</tr>
								<tr id="ttmp-category-mode-row" style="<?php echo $ai_categories ? '' : 'display:none;'; ?>">
									<th><?php esc_html_e( 'Category Mode', 'tumblr-to-minimalio-portfolio' ); ?></th>
									<td>
										<fieldset>
											<label>
												<input type="radio" name="ttmp_ai_categories_mode" value="existing" <?php checked( $ai_categories_mode, 'existing' ); ?> />
												<?php esc_html_e( 'Use existing categories only — no new categories created', 'tumblr-to-minimalio-portfolio' ); ?>
											</label><br/><br/>
											<label>
												<input type="radio" name="ttmp_ai_categories_mode" value="create" <?php checked( $ai_categories_mode, 'create' ); ?> />
												<?php esc_html_e( 'Create new categories as needed — matches existing first, creates new if no match', 'tumblr-to-minimalio-portfolio' ); ?>
											</label>
										</fieldset>
									</td>
								</tr>
							</table>
						</div>

						<div class="ttmp-chain-preview">
							<strong><?php esc_html_e( 'Current failover chain:', 'tumblr-to-minimalio-portfolio' ); ?></strong>
							<span id="ttmp-chain-display"><?php echo esc_html( TTMP_AI_Chain::get_instance()->get_chain_display() ); ?></span>
							<p class="description"><?php esc_html_e( 'Services are tried in order. If one fails or is rate-limited, the next one is used.', 'tumblr-to-minimalio-portfolio' ); ?></p>
						</div>
					</div>
				</div>

				<div class="ttmp-settings-section">
					<h2><?php esc_html_e( 'Import Settings', 'tumblr-to-minimalio-portfolio' ); ?></h2>
					<table class="form-table">
						<tr>
							<th><?php esc_html_e( 'Post Status', 'tumblr-to-minimalio-portfolio' ); ?></th>
							<td>
								<?php $post_status = get_option( self::OPTION_POST_STATUS, 'publish' ); ?>
								<fieldset>
									<label>
										<input type="radio" name="ttmp_post_status" value="publish" <?php checked( $post_status, 'publish' ); ?> />
										<?php esc_html_e( 'Published — posts go live immediately', 'tumblr-to-minimalio-portfolio' ); ?>
									</label><br/><br/>
									<label>
										<input type="radio" name="ttmp_post_status" value="draft" <?php checked( $post_status, 'draft' ); ?> />
										<?php esc_html_e( 'Draft — review posts before publishing', 'tumblr-to-minimalio-portfolio' ); ?>
									</label>
								</fieldset>
							</td>
						</tr>
						<tr>
							<th><label for="ttmp_post_author"><?php esc_html_e( 'Post Author', 'tumblr-to-minimalio-portfolio' ); ?></label></th>
							<td>
								<?php
								$post_author = get_option( self::OPTION_POST_AUTHOR, get_current_user_id() );
								wp_dropdown_users( [
									'name'             => 'ttmp_post_author',
									'id'               => 'ttmp_post_author',
									'selected'         => $post_author,
									'who'              => 'authors',
									'show_option_none' => false,
								] );
								?>
								<p class="description"><?php esc_html_e( 'All imported posts will be assigned to this author.', 'tumblr-to-minimalio-portfolio' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<?php submit_button( __( 'Save Settings & Continue', 'tumblr-to-minimalio-portfolio' ) ); ?>
			</form>
		</div>
		<?php
	}

	private static function render_fetch_page() {
		$blog_url = get_option( self::OPTION_BLOG_URL, '' );
		$api_key  = get_option( self::OPTION_API_KEY, '' );

		if ( empty( $blog_url ) || empty( $api_key ) ) {
			echo '<div class="wrap"><div class="notice notice-error"><p>' . esc_html__( 'Please configure your Tumblr API settings first.', 'tumblr-to-minimalio-portfolio' ) . '</p></div></div>';
			return;
		}

		$use_ai        = get_option( self::OPTION_USE_AI, false );
		$ai_categories = get_option( self::OPTION_AI_CATEGORIES, false );
		$chain         = TTMP_AI_Chain::get_instance();
		?>
		<div class="wrap ttmp-wrap">
			<h1><?php esc_html_e( 'Import from Tumblr to Minimalio Portfolio', 'tumblr-to-minimalio-portfolio' ); ?></h1>
			<p>
				<?php
				/* translators: %s: Tumblr blog URL */
				printf( esc_html__( 'Blog: %s', 'tumblr-to-minimalio-portfolio' ), '<strong>' . esc_html( $blog_url ) . '</strong>' ); ?>
				&mdash; <a href="<?php echo esc_url( admin_url( 'admin.php?import=ttmp-importer&step=0' ) ); ?>"><?php esc_html_e( 'Change settings', 'tumblr-to-minimalio-portfolio' ); ?></a>
			</p>

			<div id="ttmp-controls">
				<button id="ttmp-fetch" class="button button-primary button-hero"><?php esc_html_e( 'Fetch Posts from Tumblr', 'tumblr-to-minimalio-portfolio' ); ?></button>
			</div>

			<div id="ttmp-progress" style="display:none;">
				<div class="ttmp-progress-bar-wrap"><div class="ttmp-progress-bar" style="width:0%"></div></div>
				<p class="ttmp-progress-text"></p>
			</div>

			<div id="ttmp-results" style="display:none;">
				<div class="ttmp-actions">
					<button id="ttmp-select-all" class="button"><?php esc_html_e( 'Select All', 'tumblr-to-minimalio-portfolio' ); ?></button>
					<button id="ttmp-deselect-all" class="button"><?php esc_html_e( 'Deselect All', 'tumblr-to-minimalio-portfolio' ); ?></button>
					<span class="ttmp-count"></span>
					<button id="ttmp-import-selected" class="button button-primary" style="float:right;"><?php esc_html_e( 'Import Selected', 'tumblr-to-minimalio-portfolio' ); ?></button>
				</div>

				<div class="ttmp-import-options">
					<h3><?php esc_html_e( 'Import Options', 'tumblr-to-minimalio-portfolio' ); ?></h3>

					<?php if ( $use_ai && $chain->has_ai_services() ) : ?>
					<label class="ttmp-option-label">
						<input type="checkbox" id="ttmp-use-ai" value="1" checked />
						<?php esc_html_e( 'Generate AI titles & descriptions for posts without titles', 'tumblr-to-minimalio-portfolio' ); ?>
						<span class="description">(<?php echo esc_html( $chain->get_chain_display() ); ?>)</span>
					</label>
					<?php elseif ( $use_ai ) : ?>
					<p class="description">
						<?php esc_html_e( 'AI is enabled but no API keys are configured. Tag-based fallback will be used.', 'tumblr-to-minimalio-portfolio' ); ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?import=ttmp-importer&step=0' ) ); ?>"><?php esc_html_e( 'Configure API keys', 'tumblr-to-minimalio-portfolio' ); ?></a>
					</p>
					<?php endif; ?>

					<?php if ( $ai_categories ) : ?>
					<label class="ttmp-option-label">
						<input type="checkbox" id="ttmp-assign-categories" value="1" checked />
						<?php esc_html_e( 'Assign categories to imported posts', 'tumblr-to-minimalio-portfolio' ); ?>
						<span class="description">(<?php echo 'existing' === get_option( self::OPTION_AI_CATEGORIES_MODE, 'existing' ) ? esc_html__( 'existing categories only', 'tumblr-to-minimalio-portfolio' ) : esc_html__( 'will create new if needed', 'tumblr-to-minimalio-portfolio' ); ?>)</span>
					</label>
					<?php endif; ?>
				</div>

				<nav class="ttmp-tabs">
					<a href="#" class="ttmp-tab active" data-tab="posts"><?php esc_html_e( 'Posts', 'tumblr-to-minimalio-portfolio' ); ?></a>
					<a href="#" class="ttmp-tab" data-tab="log"><?php esc_html_e( 'Import Log', 'tumblr-to-minimalio-portfolio' ); ?> <span id="ttmp-log-count" class="ttmp-tab-badge" style="display:none;">0</span></a>
				</nav>

				<div class="ttmp-tab-content" id="ttmp-tab-posts">
					<div id="ttmp-posts-list"></div>
				</div>

				<div class="ttmp-tab-content" id="ttmp-tab-log" style="display:none;">
					<div id="ttmp-log-entries"></div>
				</div>
			</div>
		</div>
		<?php
	}

	// =========================================================================
	// AJAX: Test API Key
	// =========================================================================

	public static function ajax_test_api() {
		check_ajax_referer( 'ttmp_import', 'nonce' );
		if ( ! current_user_can( 'import' ) ) {
			wp_send_json_error( [ 'message' => 'Permission denied.' ] );
		}

		$service_id = isset( $_POST['service'] ) ? sanitize_text_field( wp_unslash( $_POST['service'] ) ) : '';

		switch ( $service_id ) {
			case 'gemini':
				if ( isset( $_POST['key'] ) ) {
					update_option( TTMP_Gemini::OPTION_KEY, sanitize_text_field( wp_unslash( $_POST['key'] ) ) );
				}
				$service = new TTMP_Gemini();
				break;
			case 'cloudflare':
				if ( isset( $_POST['account_id'] ) ) {
					update_option( TTMP_Cloudflare::OPTION_ACCOUNT_ID, sanitize_text_field( wp_unslash( $_POST['account_id'] ) ) );
				}
				if ( isset( $_POST['token'] ) ) {
					update_option( TTMP_Cloudflare::OPTION_API_TOKEN, sanitize_text_field( wp_unslash( $_POST['token'] ) ) );
				}
				$service = new TTMP_Cloudflare();
				break;
			case 'openai':
				if ( isset( $_POST['key'] ) ) {
					update_option( TTMP_OpenAI::OPTION_KEY, sanitize_text_field( wp_unslash( $_POST['key'] ) ) );
				}
				$service = new TTMP_OpenAI();
				break;
			default:
				wp_send_json_error( [ 'message' => 'Unknown service.' ] );
				return;
		}

		$result = $service->test_connection();
		TTMP_AI_Chain::reset();

		if ( $result['success'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
		}
	}

	// =========================================================================
	// AJAX: Fetch Posts
	// =========================================================================

	public static function ajax_fetch_posts() {
		check_ajax_referer( 'ttmp_import', 'nonce' );
		if ( ! current_user_can( 'import' ) ) {
			wp_send_json_error( [ 'message' => 'Permission denied.' ] );
		}

		$api_key  = get_option( self::OPTION_API_KEY, '' );
		$blog_url = get_option( self::OPTION_BLOG_URL, '' );
		$offset   = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		$type     = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : 'photo';

		if ( ! $api_key || ! $blog_url ) {
			wp_send_json_error( [ 'message' => 'API key or blog URL not configured.' ] );
		}

		$url = self::API_BASE . rawurlencode( $blog_url ) . '/posts/' . $type . '?' . http_build_query( [
			'api_key' => $api_key,
			'limit'   => self::PER_PAGE,
			'offset'  => $offset,
		] );

		$response = wp_remote_get( $url, [ 'timeout' => 30 ] );
		if ( is_wp_error( $response ) ) {
			wp_send_json_error( [ 'message' => $response->get_error_message() ] );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || empty( $body['response'] ) ) {
			$msg = isset( $body['meta']['msg'] ) ? $body['meta']['msg'] : 'Unknown API error.';
			wp_send_json_error( [ 'message' => $msg, 'code' => $code ] );
		}

		$posts       = $body['response']['posts'];
		$total_posts = $body['response']['total_posts'];
		$formatted   = [];

		foreach ( $posts as $post ) {
			$formatted[] = self::format_tumblr_post( $post, $type );
		}

		wp_send_json_success( [
			'posts'      => $formatted,
			'totalPosts' => $total_posts,
			'offset'     => $offset,
			'hasMore'    => ( $offset + self::PER_PAGE ) < $total_posts,
		] );
	}

	// =========================================================================
	// AJAX: Import Post
	// =========================================================================

	public static function ajax_import_post() {
		check_ajax_referer( 'ttmp_import', 'nonce' );
		if ( ! current_user_can( 'import' ) ) {
			wp_send_json_error( [ 'message' => 'Permission denied.' ] );
		}

		$post_data = isset( $_POST['post_data'] ) ? map_deep( wp_unslash( $_POST['post_data'] ), 'sanitize_text_field' ) : null;
		if ( ! $post_data ) {
			wp_send_json_error( [ 'message' => 'No post data provided.' ] );
		}

		// Sanitize
		$tumblr_id   = sanitize_text_field( $post_data['tumblr_id'] );
		$type        = sanitize_text_field( $post_data['type'] );
		$date        = sanitize_text_field( $post_data['date'] );
		$timestamp   = absint( $post_data['timestamp'] );
		$slug        = sanitize_title( $post_data['slug'] );
		$tags        = isset( $post_data['tags'] ) ? array_map( 'sanitize_text_field', $post_data['tags'] ) : [];
		$title       = sanitize_text_field( $post_data['title'] );
		$description = wp_kses_post( $post_data['description'] );
		$images      = [];
		if ( isset( $post_data['images'] ) && is_array( $post_data['images'] ) ) {
			foreach ( $post_data['images'] as $img ) {
				$clean = esc_url_raw( $img );
				if ( ! empty( $clean ) ) {
					$images[] = $clean;
				}
			}
		}
		$video_url = isset( $post_data['video_url'] ) ? esc_url_raw( $post_data['video_url'] ) : '';
		$vimeo_id  = isset( $post_data['vimeo_id'] ) ? sanitize_text_field( $post_data['vimeo_id'] ) : '';
		$post_url  = isset( $post_data['post_url'] ) ? esc_url_raw( $post_data['post_url'] ) : '';
		$thumbnail = isset( $post_data['thumbnail'] ) ? esc_url_raw( $post_data['thumbnail'] ) : '';

		// Import options
		$use_ai            = isset( $_POST['use_ai'] ) && '1' === $_POST['use_ai'];
		$assign_categories = isset( $_POST['assign_categories'] ) && '1' === $_POST['assign_categories'];

		// Duplicate check
		$existing = self::find_existing_post( $tumblr_id );
		if ( $existing ) {
			wp_send_json_success( [ 'status' => 'skipped', 'message' => 'Already imported.', 'post_id' => $existing->ID ] );
		}

		// AI SEO generation
		$ai_result = null;
		$ai_errors = [];

		if ( $use_ai && empty( $title ) ) {
			$ai_image_url = ! empty( $thumbnail ) ? $thumbnail : ( ! empty( $images ) ? $images[0] : '' );

			$existing_categories = [];
			if ( $assign_categories ) {
				$terms = get_terms( [ 'taxonomy' => 'portfolio-categories', 'hide_empty' => false, 'fields' => 'names' ] );
				if ( ! is_wp_error( $terms ) ) {
					$existing_categories = $terms;
				}
			}

			$can_create = $assign_categories && 'create' === get_option( self::OPTION_AI_CATEGORIES_MODE, 'existing' );
			$chain      = TTMP_AI_Chain::get_instance();
			$ai_result  = $chain->generate( $ai_image_url, $tags, $existing_categories, $can_create, $assign_categories );

			if ( isset( $ai_result['ai_errors'] ) ) {
				$ai_errors = $ai_result['ai_errors'];
			}
			if ( ! empty( $ai_result['title'] ) ) {
				$title = $ai_result['title'];
			}
			if ( ! empty( $ai_result['description'] ) && empty( $description ) ) {
				$description = $ai_result['description'];
			}
		}

		// Final fallback title
		if ( empty( $title ) ) {
			$title = self::generate_random_title( $type, $date );
		}

		// Build content
		// 1 image = featured image only (no embed). 2+ images = first is featured, rest are Gutenberg blocks.
		$content = '';
		if ( 'photo' === $type || ( ! empty( $images ) && 'video' !== $type ) ) {
			if ( count( $images ) > 1 ) {
				$extra_images = array_slice( $images, 1 );
				if ( count( $extra_images ) > 1 ) {
					$content .= "<!-- wp:gallery -->\n<figure class=\"wp-block-gallery has-nested-images columns-default is-cropped\">\n";
					foreach ( $extra_images as $img_url ) {
						$content .= "<!-- wp:image -->\n<figure class=\"wp-block-image\"><img src=\"" . esc_url( $img_url ) . "\" alt=\"\"/></figure>\n<!-- /wp:image -->\n";
					}
					$content .= "</figure>\n<!-- /wp:gallery -->\n";
				} else {
					$content .= "<!-- wp:image -->\n<figure class=\"wp-block-image\"><img src=\"" . esc_url( $extra_images[0] ) . "\" alt=\"\"/></figure>\n<!-- /wp:image -->\n";
				}
			}
			if ( ! empty( $description ) && ! empty( $images ) ) {
				// Strip img tags from description to avoid duplicate images (Tumblr often includes them in both photos array and caption HTML)
				$description = preg_replace( '/<img[^>]*>/i', '', $description );
				// Remove empty figure/a tags left behind after stripping images
				$description = preg_replace( '/<a[^>]*>\s*<\/a>/i', '', $description );
				$description = preg_replace( '/<figure[^>]*>\s*<\/figure>/i', '', $description );
				$description = trim( $description );
			}
			if ( ! empty( $description ) ) {
				$content .= $description;
			}
		} elseif ( 'video' === $type ) {
			if ( ! empty( $vimeo_id ) ) {
				$content .= "<!-- wp:embed {\"url\":\"https://vimeo.com/" . esc_attr( $vimeo_id ) . "\",\"type\":\"video\",\"providerNameSlug\":\"vimeo\"} -->\n";
				$content .= "<figure class=\"wp-block-embed is-type-video is-provider-vimeo wp-block-embed-vimeo\"><div class=\"wp-block-embed__wrapper\">\nhttps://vimeo.com/" . esc_html( $vimeo_id ) . "\n</div></figure>\n<!-- /wp:embed -->\n";
			} elseif ( ! empty( $video_url ) ) {
				$content .= "<!-- wp:video -->\n<figure class=\"wp-block-video\"><video controls src=\"" . esc_url( $video_url ) . "\"></video></figure>\n<!-- /wp:video -->\n";
			}
			if ( ! empty( $description ) ) {
				$content .= "\n" . $description;
			}
		}

		// Create post
		$post_date     = gmdate( 'Y-m-d H:i:s', $timestamp );
		$post_date_gmt = gmdate( 'Y-m-d H:i:s', $timestamp );
		$post_status   = get_option( self::OPTION_POST_STATUS, 'publish' );
		$post_author   = absint( get_option( self::OPTION_POST_AUTHOR, get_current_user_id() ) );

		$post_id = wp_insert_post( [
			'post_type'     => 'portfolio',
			'post_title'    => $title,
			'post_content'  => $content,
			'post_status'   => $post_status,
			'post_author'   => $post_author,
			'post_date'     => $post_date,
			'post_date_gmt' => $post_date_gmt,
			'post_name'     => ! empty( $slug ) ? $slug : sanitize_title( $title ),
		], true );

		if ( is_wp_error( $post_id ) ) {
			wp_send_json_error( [ 'message' => $post_id->get_error_message() ] );
		}

		// Meta
		update_post_meta( $post_id, '_tumblr_post_id', $tumblr_id );
		update_post_meta( $post_id, '_tumblr_post_url', $post_url );
		update_post_meta( $post_id, '_tumblr_post_type', $type );

		if ( $ai_result && ! empty( $ai_result['description'] ) ) {
			wp_update_post( [ 'ID' => $post_id, 'post_excerpt' => $ai_result['description'] ] );
		}

		if ( ! empty( $vimeo_id ) ) {
			update_post_meta( $post_id, '_vimeo_id', $vimeo_id );
		}

		// Tags
		if ( ! empty( $tags ) ) {
			$tag_ids = [];
			foreach ( $tags as $tag_name ) {
				$term = term_exists( $tag_name, 'portfolio-tags' );
				if ( ! $term ) {
					$term = wp_insert_term( $tag_name, 'portfolio-tags' );
				}
				if ( ! is_wp_error( $term ) ) {
					$tag_ids[] = (int) ( is_array( $term ) ? $term['term_id'] : $term );
				}
			}
			if ( ! empty( $tag_ids ) ) {
				wp_set_object_terms( $post_id, $tag_ids, 'portfolio-tags' );
			}
		}

		// AI category
		if ( $assign_categories && $ai_result && ! empty( $ai_result['category'] ) ) {
			$cat_name = $ai_result['category'];
			$cat_term = term_exists( $cat_name, 'portfolio-categories' );
			$can_create = 'create' === get_option( self::OPTION_AI_CATEGORIES_MODE, 'existing' );

			if ( ! $cat_term && $can_create ) {
				$cat_term = wp_insert_term( $cat_name, 'portfolio-categories' );
			}
			if ( $cat_term && ! is_wp_error( $cat_term ) ) {
				$cat_id = (int) ( is_array( $cat_term ) ? $cat_term['term_id'] : $cat_term );
				wp_set_object_terms( $post_id, [ $cat_id ], 'portfolio-categories' );
			}
		}

		// AI-generated alt text
		$ai_alt_text = ( $ai_result && ! empty( $ai_result['alt_text'] ) ) ? $ai_result['alt_text'] : $title;

		// Featured image + sideload extra images with proper attachment IDs
		$featured_image_set = false;
		$image_errors = [];

		if ( ! empty( $images ) ) {
			$attach_id = self::sideload_image( $images[0], $post_id, $title );
			if ( is_wp_error( $attach_id ) ) {
				$image_errors[] = 'Featured: ' . $attach_id->get_error_message();
			} else {
				set_post_thumbnail( $post_id, $attach_id );
				update_post_meta( $attach_id, '_wp_attachment_image_alt', $ai_alt_text );
				$featured_image_set = true;
			}

			// Sideload extra images and rebuild content with proper attachment IDs and alt text
			if ( count( $images ) > 1 ) {
				$att_ids = [];
				for ( $i = 1; $i < count( $images ); $i++ ) {
					$extra = self::sideload_image( $images[ $i ], $post_id, $title . ' ' . ( $i + 1 ) );
					if ( is_wp_error( $extra ) ) {
						$image_errors[] = 'Image ' . ( $i + 1 ) . ': ' . $extra->get_error_message();
					} else {
						update_post_meta( $extra, '_wp_attachment_image_alt', $ai_alt_text );
						$att_ids[] = $extra;
					}
				}
				if ( ! empty( $att_ids ) ) {
					$new_content = '';
					if ( count( $att_ids ) > 1 ) {
						$new_content .= "<!-- wp:gallery -->\n<figure class=\"wp-block-gallery has-nested-images columns-default is-cropped\">\n";
						foreach ( $att_ids as $aid ) {
							$aurl = wp_get_attachment_url( $aid );
							$new_content .= "<!-- wp:image {\"id\":" . $aid . "} -->\n<figure class=\"wp-block-image\"><img src=\"" . esc_url( $aurl ) . "\" alt=\"" . esc_attr( $ai_alt_text ) . "\" class=\"wp-image-" . $aid . "\"/></figure>\n<!-- /wp:image -->\n";
						}
						$new_content .= "</figure>\n<!-- /wp:gallery -->\n";
					} else {
						$aurl = wp_get_attachment_url( $att_ids[0] );
						$new_content .= "<!-- wp:image {\"id\":" . $att_ids[0] . "} -->\n<figure class=\"wp-block-image\"><img src=\"" . esc_url( $aurl ) . "\" alt=\"" . esc_attr( $ai_alt_text ) . "\" class=\"wp-image-" . $att_ids[0] . "\"/></figure>\n<!-- /wp:image -->\n";
					}
					if ( ! empty( $description ) ) {
						$new_content .= "\n" . $description;
					}
					wp_update_post( [ 'ID' => $post_id, 'post_content' => $new_content ] );
				}
			}
		} elseif ( ! empty( $thumbnail ) && 'video' === $type ) {
			$attach_id = self::sideload_image( $thumbnail, $post_id, $title );
			if ( is_wp_error( $attach_id ) ) {
				$image_errors[] = 'Thumbnail: ' . $attach_id->get_error_message();
			} else {
				set_post_thumbnail( $post_id, $attach_id );
				$featured_image_set = true;
			}
		}

		// Response
		$message = sprintf( 'Imported: %s', $title );
		if ( ! $featured_image_set && ! empty( $images ) ) {
			$message .= ' (featured image failed)';
		}

		$resp = [
			'status'             => 'imported',
			'message'            => $message,
			'post_id'            => $post_id,
			'edit_url'           => get_edit_post_link( $post_id, 'raw' ),
			'has_featured_image' => $featured_image_set,
			'images_count'       => count( $images ),
			'title'              => $title,
		];
		if ( $ai_result ) {
			$resp['ai_source'] = isset( $ai_result['source'] ) ? $ai_result['source'] : '';
		}
		if ( ! empty( $image_errors ) ) {
			$resp['image_errors'] = $image_errors;
		}
		if ( ! empty( $ai_errors ) ) {
			$resp['ai_errors'] = $ai_errors;
		}

		wp_send_json_success( $resp );
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	private static function format_tumblr_post( $post, $type ) {
		$effective_type = $type;
		if ( ! empty( $post['photos'] ) ) {
			$effective_type = 'photo';
		}
		if ( ! empty( $post['video_url'] ) || ! empty( $post['player'] ) || ! empty( $post['video_type'] ) ) {
			$effective_type = 'video';
		}

		$data = [
			'tumblr_id' => $post['id'], 'type' => $effective_type,
			'date' => $post['date'], 'timestamp' => $post['timestamp'],
			'post_url' => $post['post_url'],
			'slug' => isset( $post['slug'] ) ? $post['slug'] : '',
			'tags' => isset( $post['tags'] ) ? $post['tags'] : [],
			'title' => '', 'description' => '', 'thumbnail' => '',
			'images' => [], 'video_url' => '', 'video_type' => '', 'vimeo_id' => '',
		];

		$existing = self::find_existing_post( $post['id'] );
		$data['already_imported'] = ! empty( $existing );

		$caption = '';
		if ( ! empty( $post['caption'] ) ) {
			$caption = $post['caption'];
		} elseif ( ! empty( $post['body'] ) ) {
			$caption = $post['body'];
		}
		$data['description'] = $caption;
		$data['title'] = self::extract_title_from_caption( $caption );
		if ( empty( $data['title'] ) && ! empty( $post['title'] ) ) {
			$data['title'] = wp_strip_all_tags( $post['title'] );
		}

		// Photos array
		if ( ! empty( $post['photos'] ) ) {
			foreach ( $post['photos'] as $photo ) {
				if ( ! empty( $photo['original_size']['url'] ) ) {
					$data['images'][] = $photo['original_size']['url'];
				}
				if ( ! empty( $photo['caption'] ) && empty( $data['title'] ) ) {
					$data['title'] = wp_strip_all_tags( $photo['caption'] );
				}
			}
			$first = $post['photos'][0];
			if ( ! empty( $first['alt_sizes'] ) ) {
				foreach ( $first['alt_sizes'] as $size ) {
					if ( $size['width'] <= 500 ) {
						$data['thumbnail'] = $size['url'];
						break;
					}
				}
			}
			if ( empty( $data['thumbnail'] ) && ! empty( $first['original_size']['url'] ) ) {
				$data['thumbnail'] = $first['original_size']['url'];
			}
		}

		// Extract from body HTML
		if ( empty( $data['images'] ) && ! empty( $caption ) ) {
			preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $caption, $m );
			if ( ! empty( $m[1] ) ) {
				foreach ( $m[1] as $img_url ) {
					if ( strpos( $img_url, 'http' ) === 0 && ( preg_match( '/\.(jpg|jpeg|png|gif|webp|bmp)/i', $img_url ) || strpos( $img_url, 'tumblr.com' ) !== false ) ) {
						$data['images'][] = $img_url;
					}
				}
				if ( ! empty( $data['images'] ) ) {
					$data['thumbnail'] = $data['images'][0];
					$data['type'] = 'photo';
				}
			}
		}

		// Video fields
		if ( 'video' === $effective_type ) {
			$data['video_type'] = isset( $post['video_type'] ) ? $post['video_type'] : '';
			if ( ! empty( $post['video_url'] ) ) {
				$data['video_url'] = $post['video_url'];
			}
			if ( 'vimeo' === $data['video_type'] && ! empty( $post['permalink_url'] ) && preg_match( '/vimeo\.com\/(\d+)/', $post['permalink_url'], $vm ) ) {
				$data['vimeo_id'] = $vm[1];
			}
			if ( empty( $data['vimeo_id'] ) && ! empty( $post['player'] ) ) {
				foreach ( $post['player'] as $player ) {
					if ( ! empty( $player['embed_code'] ) && preg_match( '/vimeo\.com\/video\/(\d+)/', $player['embed_code'], $vm ) ) {
						$data['vimeo_id'] = $vm[1];
						break;
					}
				}
			}
			if ( ! empty( $post['thumbnail_url'] ) ) {
				$data['thumbnail'] = $post['thumbnail_url'];
			}
		}

		return $data;
	}

	private static function extract_title_from_caption( $caption ) {
		if ( empty( $caption ) ) return '';
		if ( preg_match( '/<h[1-6][^>]*>(.*?)<\/h[1-6]>/i', $caption, $m ) ) {
			$t = trim( wp_strip_all_tags( $m[1] ) );
			if ( ! empty( $t ) ) return strlen( $t ) > 80 ? substr( $t, 0, 80 ) . '...' : $t;
		}
		if ( preg_match( '/<p[^>]*>(.*?)<\/p>/is', $caption, $m ) ) {
			$t = trim( wp_strip_all_tags( $m[1] ) );
			if ( ! empty( $t ) ) return strlen( $t ) > 80 ? substr( $t, 0, 80 ) . '...' : $t;
		}
		$plain = trim( wp_strip_all_tags( $caption ) );
		if ( ! empty( $plain ) ) return strlen( $plain ) > 80 ? substr( $plain, 0, 80 ) . '...' : $plain;
		return '';
	}

	private static function generate_random_title( $type, $date ) {
		$adj  = [ 'Vivid', 'Silent', 'Radiant', 'Serene', 'Bold', 'Drifting', 'Golden', 'Midnight', 'Crimson', 'Azure' ];
		$noun = [ 'Moment', 'Vision', 'Fragment', 'Glimpse', 'Scene', 'Frame', 'Impression', 'Study', 'Composition', 'Reflection' ];
		return $adj[ array_rand( $adj ) ] . ' ' . $noun[ array_rand( $noun ) ] . ' — ' . ( 'video' === $type ? 'Video' : 'Photo' ) . ' ' . gmdate( 'M Y', strtotime( $date ) );
	}

	private static function find_existing_post( $tumblr_id ) {
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		$q = new WP_Query( [ 'post_type' => 'portfolio', 'meta_key' => '_tumblr_post_id', 'meta_value' => $tumblr_id, 'posts_per_page' => 1, 'post_status' => 'any' ] );
		return $q->have_posts() ? $q->posts[0] : null;
	}

	private static function sideload_image( $url, $post_id, $desc = '' ) {
		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		if ( empty( $url ) ) return new WP_Error( 'empty_url', 'Image URL is empty.' );

		$tmp = download_url( $url, 120 );
		if ( is_wp_error( $tmp ) ) return new WP_Error( 'download_failed', 'Download failed: ' . $tmp->get_error_message() );
		if ( ! file_exists( $tmp ) || filesize( $tmp ) === 0 ) { wp_delete_file( $tmp ); return new WP_Error( 'empty_file', 'Downloaded file is empty.' ); }

		$url_path = wp_parse_url( $url, PHP_URL_PATH );
		$filename = basename( $url_path );
		if ( strpos( $filename, '?' ) !== false ) $filename = strtok( $filename, '?' );

		$mime = function_exists( 'mime_content_type' ) ? mime_content_type( $tmp ) : '';
		if ( empty( $mime ) || 'application/octet-stream' === $mime ) {
			$fi = finfo_open( FILEINFO_MIME_TYPE );
			if ( $fi ) { $mime = finfo_file( $fi, $tmp ); finfo_close( $fi ); }
		}

		$ext_map = [ 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp', 'image/bmp' => 'bmp' ];
		$ft = wp_check_filetype( $filename );
		if ( empty( $ft['ext'] ) || empty( $ft['type'] ) ) {
			$ext = isset( $ext_map[ $mime ] ) ? $ext_map[ $mime ] : 'jpg';
			$safe = sanitize_file_name( $desc );
			$filename = ( ! empty( $safe ) ? $safe : 'tumblr-import-' . uniqid() ) . '.' . $ext;
		} elseif ( ! empty( $mime ) && isset( $ext_map[ $mime ] ) && $ext_map[ $mime ] !== $ft['ext'] ) {
			$filename = pathinfo( $filename, PATHINFO_FILENAME ) . '.' . $ext_map[ $mime ];
		}

		add_filter( 'upload_mimes', [ __CLASS__, 'allow_image_mimes' ] );
		$attach_id = media_handle_sideload( [ 'name' => $filename, 'tmp_name' => $tmp ], $post_id, $desc );
		remove_filter( 'upload_mimes', [ __CLASS__, 'allow_image_mimes' ] );

		if ( is_wp_error( $attach_id ) ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'sideload_failed', 'Sideload failed: ' . $attach_id->get_error_message() . ' | File: ' . $filename . ' | MIME: ' . $mime );
		}
		return $attach_id;
	}

	public static function allow_image_mimes( $mimes ) {
		$mimes['jpg|jpeg|jpe'] = 'image/jpeg';
		$mimes['png'] = 'image/png';
		$mimes['gif'] = 'image/gif';
		$mimes['webp'] = 'image/webp';
		$mimes['bmp'] = 'image/bmp';
		return $mimes;
	}
}

TTMP_Importer::init();
