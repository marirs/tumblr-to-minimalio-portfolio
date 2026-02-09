<?php
/**
 * Google Gemini AI Service
 *
 * @package TumblrToMinimalioPortfolio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TTMP_Gemini extends TTMP_AI_Service {

	protected $id   = 'gemini';
	protected $name = 'Google Gemini';

	const OPTION_KEY = 'ttmp_gemini_api_key';
	const API_BASE   = 'https://generativelanguage.googleapis.com/v1beta/';
	const MODEL      = 'gemini-2.0-flash';

	/**
	 * Check if the service is configured
	 */
	public function is_configured() {
		$key = get_option( self::OPTION_KEY, '' );
		return ! empty( $key );
	}

	/**
	 * Test the API key by listing models (free, no credits used)
	 */
	public function test_connection() {
		$key = get_option( self::OPTION_KEY, '' );
		if ( empty( $key ) ) {
			return [ 'success' => false, 'message' => __( 'No API key configured.', 'tumblr-to-minimalio' ) ];
		}

		$url = self::API_BASE . 'models?key=' . urlencode( $key );

		$response = wp_remote_get( $url, [ 'timeout' => 15 ] );

		if ( is_wp_error( $response ) ) {
			return [ 'success' => false, 'message' => $response->get_error_message() ];
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			$msg  = isset( $body['error']['message'] ) ? $body['error']['message'] : 'HTTP ' . $code;
			return [ 'success' => false, 'message' => $msg ];
		}

		return [ 'success' => true, 'message' => __( 'Connected successfully to Google Gemini.', 'tumblr-to-minimalio' ) ];
	}

	/**
	 * Generate SEO data using Gemini Vision
	 */
	public function generate_seo_data( $image_url, $tags = [], $existing_categories = [], $can_create_categories = false ) {
		if ( $this->rate_limited ) {
			return new WP_Error( 'rate_limited', 'Gemini is rate-limited for this session.' );
		}

		$key = get_option( self::OPTION_KEY, '' );
		if ( empty( $key ) ) {
			return new WP_Error( 'not_configured', 'Gemini API key not configured.' );
		}

		// $image_url is pre-downloaded base64 data when called from the chain
		$image_base64 = $image_url;
		if ( empty( $image_base64 ) ) {
			return new WP_Error( 'no_image_data', 'No image data provided for Gemini analysis.' );
		}

		$prompt = $this->build_prompt( $tags, $existing_categories, $can_create_categories );

		$url = self::API_BASE . 'models/' . self::MODEL . ':generateContent?key=' . urlencode( $key );

		$body = [
			'contents' => [
				[
					'parts' => [
						[
							'text' => $prompt,
						],
						[
							'inline_data' => [
								'mime_type' => 'image/jpeg',
								'data'      => $image_base64,
							],
						],
					],
				],
			],
			'generationConfig' => [
				'temperature'      => 0.3,
				'maxOutputTokens'  => 300,
				'responseMimeType' => 'application/json',
			],
		];

		$response = wp_remote_post( $url, [
			'timeout' => 30,
			'headers' => [ 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode( $body ),
		] );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'request_failed', 'Gemini request failed: ' . $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 429 === $code ) {
			$this->set_rate_limited( true );
			return new WP_Error( 'rate_limited', 'Gemini rate limit reached.' );
		}

		if ( 200 !== $code ) {
			$msg = isset( $data['error']['message'] ) ? $data['error']['message'] : 'HTTP ' . $code;
			return new WP_Error( 'api_error', 'Gemini error: ' . $msg );
		}

		if ( empty( $data['candidates'][0]['content']['parts'][0]['text'] ) ) {
			return new WP_Error( 'empty_response', 'Gemini returned an empty response.' );
		}

		$raw_text = $data['candidates'][0]['content']['parts'][0]['text'];

		return $this->parse_response( $raw_text );
	}

}
