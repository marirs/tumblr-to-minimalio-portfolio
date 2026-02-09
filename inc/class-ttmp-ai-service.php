<?php
/**
 * Abstract AI Service base class
 *
 * @package TumblrToMinimalioPortfolio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class TTMP_AI_Service {

	/**
	 * Service identifier
	 *
	 * @var string
	 */
	protected $id = '';

	/**
	 * Service display name
	 *
	 * @var string
	 */
	protected $name = '';

	/**
	 * Whether this service is currently rate-limited
	 *
	 * @var bool
	 */
	protected $rate_limited = false;

	/**
	 * Get the service ID
	 */
	public function get_id() {
		return $this->id;
	}

	/**
	 * Get the service name
	 */
	public function get_name() {
		return $this->name;
	}

	/**
	 * Check if the service is rate-limited
	 */
	public function is_rate_limited() {
		return $this->rate_limited;
	}

	/**
	 * Mark this service as rate-limited for this session
	 */
	public function set_rate_limited( $limited = true ) {
		$this->rate_limited = $limited;
	}

	/**
	 * Check if the service is configured (has API keys)
	 *
	 * @return bool
	 */
	abstract public function is_configured();

	/**
	 * Test the API key validity without using credits
	 *
	 * @return array { 'success' => bool, 'message' => string }
	 */
	abstract public function test_connection();

	/**
	 * Generate SEO title, description, and category for an image
	 *
	 * @param string $image_url        URL of the image (small thumbnail preferred).
	 * @param array  $tags             Existing tags for context.
	 * @param array  $existing_categories List of existing portfolio category names.
	 * @param bool   $can_create_categories Whether AI can suggest new categories.
	 *
	 * @return array|WP_Error {
	 *     'title'       => string (max 60 chars),
	 *     'description' => string (max 155 chars),
	 *     'category'    => string|null (category name or null),
	 * }
	 */
	abstract public function generate_seo_data( $image_url, $tags = [], $existing_categories = [], $can_create_categories = false );

	/**
	 * Build the prompt for SEO generation
	 */
	protected function build_prompt( $tags = [], $existing_categories = [], $can_create_categories = false ) {
		$prompt = "Analyze this image and generate SEO-optimized metadata.\n\n";
		$prompt .= "Return ONLY a JSON object with these exact keys:\n";
		$prompt .= "- \"title\": A concise, descriptive SEO title (max 60 characters, no quotes around it)\n";
		$prompt .= "- \"description\": An engaging SEO meta description (max 155 characters)\n";

		if ( ! empty( $existing_categories ) || $can_create_categories ) {
			$prompt .= "- \"category\": ";
			if ( ! empty( $existing_categories ) && ! $can_create_categories ) {
				$prompt .= "Pick the single best matching category from this list: [" . implode( ', ', $existing_categories ) . "]. If none fit, use null.\n";
			} elseif ( ! empty( $existing_categories ) && $can_create_categories ) {
				$prompt .= "Pick the best matching category from this list: [" . implode( ', ', $existing_categories ) . "]. If none fit well, suggest a short new category name (1-3 words).\n";
			} else {
				$prompt .= "Suggest a short category name (1-3 words) that best describes this image.\n";
			}
		}

		if ( ! empty( $tags ) ) {
			$prompt .= "\nContext tags: " . implode( ', ', $tags ) . "\n";
		}

		$prompt .= "\nRespond with ONLY the JSON object, no markdown formatting, no code blocks, no extra text.";

		return $prompt;
	}

	/**
	 * Parse the AI response JSON
	 */
	protected function parse_response( $raw_response ) {
		// Strip markdown code blocks if present
		$cleaned = trim( $raw_response );
		$cleaned = preg_replace( '/^```(?:json)?\s*/i', '', $cleaned );
		$cleaned = preg_replace( '/\s*```$/', '', $cleaned );
		$cleaned = trim( $cleaned );

		$data = json_decode( $cleaned, true );

		// If JSON parsing fails, try to fix common issues (unquoted values, trailing commas)
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			// Try to extract values with regex as fallback
			$title = '';
			$desc  = '';
			$cat   = null;

			if ( preg_match( '/"title"\s*:\s*"([^"]+)"/i', $cleaned, $m ) ) {
				$title = $m[1];
			} elseif ( preg_match( '/"title"\s*:\s*([^,}\n]+)/i', $cleaned, $m ) ) {
				$title = trim( $m[1], " \t\n\r\0\x0B\"" );
			}

			if ( preg_match( '/"description"\s*:\s*"([^"]+)"/i', $cleaned, $m ) ) {
				$desc = $m[1];
			} elseif ( preg_match( '/"description"\s*:\s*([^,}\n]+)/i', $cleaned, $m ) ) {
				$desc = trim( $m[1], " \t\n\r\0\x0B\"" );
			}

			if ( preg_match( '/"category"\s*:\s*"([^"]+)"/i', $cleaned, $m ) ) {
				$cat = $m[1];
			}

			if ( ! empty( $title ) ) {
				return [
					'title'       => sanitize_text_field( substr( $title, 0, 60 ) ),
					'description' => sanitize_text_field( substr( $desc, 0, 155 ) ),
					'category'    => $cat !== null ? sanitize_text_field( $cat ) : null,
				];
			}

			return new WP_Error( 'parse_error', 'Failed to parse AI response: ' . json_last_error_msg() . ' | Raw: ' . substr( $raw_response, 0, 200 ) );
		}

		return [
			'title'       => isset( $data['title'] ) ? sanitize_text_field( substr( $data['title'], 0, 60 ) ) : '',
			'description' => isset( $data['description'] ) ? sanitize_text_field( substr( $data['description'], 0, 155 ) ) : '',
			'category'    => isset( $data['category'] ) && $data['category'] !== null ? sanitize_text_field( $data['category'] ) : null,
		];
	}
}
