<?php
/**
 * Gemini Text-only AI Service — generates SEO data from tags (no image)
 *
 * Uses the same Gemini API key but sends only tags, making it fast and free.
 * Acts as a middle tier between vision AI and dumb tag fallback.
 *
 * @package TumblrToMinimalioPortfolio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TTMP_Gemini_Text extends TTMP_AI_Service {

	protected $id   = 'gemini_text';
	protected $name = 'Gemini (text)';

	const MODEL = 'gemini-2.0-flash';

	/**
	 * Check if the service is configured — reuses Gemini key
	 */
	public function is_configured() {
		$key = get_option( TTMP_Gemini::OPTION_KEY, '' );
		return ! empty( $key );
	}

	/**
	 * Test connection — reuses Gemini test
	 */
	public function test_connection() {
		$gemini = new TTMP_Gemini();
		return $gemini->test_connection();
	}

	/**
	 * Generate SEO data from tags only (no image)
	 */
	public function generate_seo_data( $image_url, $tags = [], $existing_categories = [], $can_create_categories = false ) {
		if ( $this->rate_limited ) {
			return new WP_Error( 'rate_limited', 'Gemini Text is rate-limited for this session.' );
		}

		$key = get_option( TTMP_Gemini::OPTION_KEY, '' );
		if ( empty( $key ) ) {
			return new WP_Error( 'not_configured', 'Gemini API key not configured.' );
		}

		if ( empty( $tags ) ) {
			return new WP_Error( 'no_tags', 'No tags available for text-based generation.' );
		}

		$prompt = $this->build_text_prompt( $tags, $existing_categories, $can_create_categories );

		$url = TTMP_Gemini::API_BASE . 'models/' . self::MODEL . ':generateContent?key=' . $key;

		$body = [
			'contents' => [
				[
					'parts' => [
						[ 'text' => $prompt ],
					],
				],
			],
			'generationConfig' => [
				'temperature'      => 0.4,
				'maxOutputTokens'  => 200,
				'responseMimeType' => 'application/json',
			],
		];

		$response = wp_remote_post( $url, [
			'timeout' => 15,
			'headers' => [ 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode( $body ),
		] );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'request_failed', 'Gemini Text request failed: ' . $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 429 === $code ) {
			$this->set_rate_limited( true );
			return new WP_Error( 'rate_limited', 'Gemini Text rate limit reached.' );
		}

		if ( 200 !== $code ) {
			$msg = isset( $data['error']['message'] ) ? $data['error']['message'] : 'HTTP ' . $code;
			return new WP_Error( 'api_error', 'Gemini Text error: ' . $msg );
		}

		if ( empty( $data['candidates'][0]['content']['parts'][0]['text'] ) ) {
			return new WP_Error( 'empty_response', 'Gemini Text returned an empty response.' );
		}

		return $this->parse_response( $data['candidates'][0]['content']['parts'][0]['text'] );
	}

	/**
	 * Build a text-only prompt for tag-based SEO generation
	 */
	protected function build_text_prompt( $tags, $existing_categories = [], $can_create_categories = false ) {
		$prompt  = "You are an SEO expert for a photography portfolio website.\n";
		$prompt .= "Based on the following tags from a photograph, generate SEO metadata.\n\n";
		$prompt .= "Tags: " . implode( ', ', $tags ) . "\n\n";
		$prompt .= "Return ONLY a JSON object with these exact keys:\n";
		$prompt .= "- \"title\": A creative, descriptive SEO title for this photograph (max 60 characters). Make it sound natural and engaging, not just a list of tags.\n";
		$prompt .= "- \"description\": An engaging SEO meta description (max 254 characters)\n";

		if ( ! empty( $existing_categories ) || $can_create_categories ) {
			$prompt .= "- \"category\": ";
			if ( ! empty( $existing_categories ) && ! $can_create_categories ) {
				$prompt .= "Pick the single best matching category from: [" . implode( ', ', $existing_categories ) . "]. If none fit, use null.\n";
			} elseif ( ! empty( $existing_categories ) && $can_create_categories ) {
				$prompt .= "Pick the best from: [" . implode( ', ', $existing_categories ) . "]. If none fit, suggest a short new category (1-3 words).\n";
			} else {
				$prompt .= "Suggest a short category name (1-3 words).\n";
			}
		}

		$prompt .= "\nRespond with ONLY the JSON object, no markdown, no code blocks.";
		return $prompt;
	}
}
