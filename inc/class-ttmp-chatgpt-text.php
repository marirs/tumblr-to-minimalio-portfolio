<?php
/**
 * ChatGPT Text-only AI Service — generates SEO data from tags (no image)
 *
 * Uses the same OpenAI API key but sends only tags, making it fast and cheap.
 * Acts as a middle tier between vision AI and dumb tag fallback.
 *
 * @package TumblrToMinimalioPortfolio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TTMP_ChatGPT_Text extends TTMP_AI_Service {

	protected $id   = 'chatgpt_text';
	protected $name = 'ChatGPT (text)';

	const MODEL = 'gpt-4o-mini';

	/**
	 * Check if the service is configured — reuses OpenAI key
	 */
	public function is_configured() {
		$key = get_option( TTMP_OpenAI::OPTION_KEY, '' );
		return ! empty( $key );
	}

	/**
	 * Test connection — reuses OpenAI test
	 */
	public function test_connection() {
		$openai = new TTMP_OpenAI();
		return $openai->test_connection();
	}

	/**
	 * Generate SEO data from tags only (no image)
	 */
	public function generate_seo_data( $image_url, $tags = [], $existing_categories = [], $can_create_categories = false ) {
		if ( $this->rate_limited ) {
			return new WP_Error( 'rate_limited', 'ChatGPT Text is rate-limited for this session.' );
		}

		$key = get_option( TTMP_OpenAI::OPTION_KEY, '' );
		if ( empty( $key ) ) {
			return new WP_Error( 'not_configured', 'OpenAI API key not configured.' );
		}

		if ( empty( $tags ) ) {
			return new WP_Error( 'no_tags', 'No tags available for text-based generation.' );
		}

		$prompt = $this->build_text_prompt( $tags, $existing_categories, $can_create_categories );

		$body = [
			'model'       => self::MODEL,
			'messages'    => [
				[
					'role'    => 'user',
					'content' => $prompt,
				],
			],
			'max_tokens'     => 200,
			'temperature'    => 0.4,
			'response_format' => [ 'type' => 'json_object' ],
		];

		$response = wp_remote_post( TTMP_OpenAI::API_BASE . 'chat/completions', [
			'timeout' => 15,
			'headers' => [
				'Authorization' => 'Bearer ' . $key,
				'Content-Type'  => 'application/json',
			],
			'body' => wp_json_encode( $body ),
		] );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'request_failed', 'ChatGPT Text request failed: ' . $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 429 === $code ) {
			$this->set_rate_limited( true );
			return new WP_Error( 'rate_limited', 'ChatGPT Text rate limit reached.' );
		}

		if ( 200 !== $code ) {
			$msg = isset( $data['error']['message'] ) ? $data['error']['message'] : 'HTTP ' . $code;
			return new WP_Error( 'api_error', 'ChatGPT Text error: ' . $msg );
		}

		if ( empty( $data['choices'][0]['message']['content'] ) ) {
			return new WP_Error( 'empty_response', 'ChatGPT Text returned an empty response.' );
		}

		return $this->parse_response( $data['choices'][0]['message']['content'] );
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
		$prompt .= "- \"description\": An engaging SEO meta description (max 155 characters)\n";

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
