<?php
/**
 * AI Service Chain — manages failover between AI services
 *
 * @package TumblrToMinimalioPortfolio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TTMP_AI_Chain {

	/**
	 * Ordered list of vision AI service instances (send image)
	 *
	 * @var TTMP_AI_Service[]
	 */
	private $vision_services = [];

	/**
	 * Ordered list of text-only AI service instances (send tags only)
	 *
	 * @var TTMP_AI_Service[]
	 */
	private $text_services = [];

	/**
	 * Singleton instance
	 */
	private static $instance = null;

	/**
	 * Get singleton instance
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor — builds the service chain from configured keys, respecting saved order
	 */
	private function __construct() {
		$order = get_option( 'ttmp_ai_service_order', [ 'gemini', 'cloudflare', 'openai' ] );

		// Vision services (image-based)
		$available_vision = [
			'gemini'     => new TTMP_Gemini(),
			'cloudflare' => new TTMP_Cloudflare(),
			'openai'     => new TTMP_OpenAI(),
		];

		foreach ( $order as $id ) {
			if ( isset( $available_vision[ $id ] ) && $available_vision[ $id ]->is_configured() ) {
				$this->vision_services[] = $available_vision[ $id ];
			}
		}

		// Safety net: add configured services not in saved order
		foreach ( $available_vision as $id => $service ) {
			if ( ! in_array( $id, $order, true ) && $service->is_configured() ) {
				$this->vision_services[] = $service;
			}
		}

		// Text-only services (tag-based, reuse existing API keys)
		$text_order = get_option( 'ttmp_ai_text_order', [ 'chatgpt_text', 'gemini_text' ] );

		$available_text = [
			'chatgpt_text' => new TTMP_ChatGPT_Text(),
			'gemini_text'  => new TTMP_Gemini_Text(),
		];

		foreach ( $text_order as $id ) {
			if ( isset( $available_text[ $id ] ) && $available_text[ $id ]->is_configured() ) {
				$this->text_services[] = $available_text[ $id ];
			}
		}

		foreach ( $available_text as $id => $service ) {
			if ( ! in_array( $id, $text_order, true ) && $service->is_configured() ) {
				$this->text_services[] = $service;
			}
		}
	}

	/**
	 * Reset the singleton (useful for testing or when settings change)
	 */
	public static function reset() {
		self::$instance = null;
	}

	/**
	 * Get the list of configured service names for display
	 *
	 * @return string[] Service names in chain order
	 */
	public function get_chain_names() {
		$names = [];
		foreach ( $this->vision_services as $service ) {
			$names[] = $service->get_name();
		}
		foreach ( $this->text_services as $service ) {
			$names[] = $service->get_name();
		}
		$names[] = __( 'Tag fallback', 'tumblr-to-minimalio' );
		return $names;
	}

	/**
	 * Get a display string of the chain
	 *
	 * @return string e.g. "OpenAI → Gemini → ChatGPT (text) → Gemini (text) → Tag fallback"
	 */
	public function get_chain_display() {
		return implode( ' → ', $this->get_chain_names() );
	}

	/**
	 * Check if any AI service (vision or text) is configured
	 *
	 * @return bool
	 */
	public function has_ai_services() {
		return ! empty( $this->vision_services ) || ! empty( $this->text_services );
	}

	/**
	 * Generate SEO data using the three-tier failover chain:
	 * 1. Vision AI (image + tags) — best quality
	 * 2. Text AI (tags only) — fast, cheap, still AI quality
	 * 3. Tag fallback (no AI) — last resort
	 *
	 * @param string $image_url             Small thumbnail URL.
	 * @param array  $tags                  Post tags.
	 * @param array  $existing_categories   Existing portfolio category names.
	 * @param bool   $can_create_categories Whether AI can create new categories.
	 * @param bool   $assign_categories     Whether to assign categories at all.
	 *
	 * @return array {
	 *     'title'        => string,
	 *     'description'  => string,
	 *     'category'     => string|null,
	 *     'source'       => string (which service generated the data),
	 * }
	 */
	public function generate( $image_url, $tags = [], $existing_categories = [], $can_create_categories = false, $assign_categories = true ) {
		$errors = [];
		$cat_args = $assign_categories ? $existing_categories : [];
		$cat_create = $assign_categories ? $can_create_categories : false;

		// Tier 1: Vision AI services (image-based)
		// Pre-download the image once so each vision service doesn't re-download it
		$image_base64 = '';
		if ( ! empty( $image_url ) && ! empty( $this->vision_services ) ) {
			$tmp = download_url( $image_url, 30 );
			if ( ! is_wp_error( $tmp ) ) {
				$raw = file_get_contents( $tmp );
				@unlink( $tmp );
				if ( ! empty( $raw ) ) {
					$image_base64 = base64_encode( $raw );
				}
			}
		}

		if ( ! empty( $image_base64 ) ) {
			error_log( '[TTMP] Image downloaded OK (' . strlen( $image_base64 ) . ' bytes base64). Trying ' . count( $this->vision_services ) . ' vision service(s).' );
			foreach ( $this->vision_services as $service ) {
				if ( $service->is_rate_limited() ) {
					error_log( '[TTMP] ' . $service->get_name() . ': skipped (rate-limited).' );
					continue;
				}

				error_log( '[TTMP] Trying vision service: ' . $service->get_name() );
				$result = $service->generate_seo_data( $image_base64, $tags, $cat_args, $cat_create );

				if ( ! is_wp_error( $result ) && ! empty( $result['title'] ) ) {
					error_log( '[TTMP] ' . $service->get_name() . ' succeeded: "' . $result['title'] . '"' );
					$result['source'] = $service->get_name();
					if ( ! $assign_categories ) {
						$result['category'] = null;
					}
					if ( ! empty( $errors ) ) {
						$result['ai_errors'] = $errors;
					}
					return $result;
				}

				if ( is_wp_error( $result ) ) {
					$err_msg = $service->get_name() . ': ' . $result->get_error_message();
					error_log( '[TTMP] Vision FAILED — ' . $err_msg );
					$errors[] = $err_msg;
				}
			}
		} elseif ( ! empty( $image_url ) ) {
			error_log( '[TTMP] Image download FAILED for: ' . $image_url );
			$errors[] = 'Image download failed — skipping vision AI, trying text AI.';
		}

		// Tier 2: Text-only AI services (tag-based)
		if ( ! empty( $tags ) ) {
			foreach ( $this->text_services as $service ) {
				if ( $service->is_rate_limited() ) {
					continue;
				}

				$result = $service->generate_seo_data( $image_url, $tags, $cat_args, $cat_create );

				if ( ! is_wp_error( $result ) && ! empty( $result['title'] ) ) {
					$result['source'] = $service->get_name();
					if ( ! $assign_categories ) {
						$result['category'] = null;
					}
					if ( ! empty( $errors ) ) {
						$result['ai_errors'] = $errors;
					}
					return $result;
				}

				if ( is_wp_error( $result ) ) {
					$errors[] = $service->get_name() . ': ' . $result->get_error_message();
				}
			}
		}

		// Tier 3: Tag-based fallback (no AI)
		$tag_result = TTMP_Tag_Fallback::generate_seo_data(
			$tags,
			$cat_args,
			$cat_create
		);

		$tag_result['source'] = __( 'Tag fallback', 'tumblr-to-minimalio' );

		if ( ! $assign_categories ) {
			$tag_result['category'] = null;
		}

		if ( ! empty( $errors ) ) {
			$tag_result['ai_errors'] = $errors;
		}

		return $tag_result;
	}
}
