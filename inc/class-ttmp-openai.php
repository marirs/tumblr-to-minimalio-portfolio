<?php
/**
 * OpenAI AI Service
 *
 * @package TumblrToMinimalioPortfolio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TTMP_OpenAI extends TTMP_AI_Service {

	protected $id   = 'openai';
	protected $name = 'OpenAI';

	const OPTION_KEY = 'ttmp_openai_api_key';
	const API_BASE   = 'https://api.openai.com/v1/';
	const MODEL      = 'gpt-4o-mini';

	/**
	 * Check if the service is configured
	 */
	public function is_configured() {
		$key = get_option( self::OPTION_KEY, '' );
		return ! empty( $key );
	}

	/**
	 * Test the API key by listing models (no cost)
	 */
	public function test_connection() {
		$key = get_option( self::OPTION_KEY, '' );
		if ( empty( $key ) ) {
			return [ 'success' => false, 'message' => __( 'No API key configured.', 'tumblr-to-minimalio-portfolio' ) ];
		}

		$response = wp_remote_get( self::API_BASE . 'models', [
			'timeout' => 15,
			'headers' => [
				'Authorization' => 'Bearer ' . $key,
			],
		] );

		if ( is_wp_error( $response ) ) {
			return [ 'success' => false, 'message' => $response->get_error_message() ];
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			$msg  = isset( $body['error']['message'] ) ? $body['error']['message'] : 'HTTP ' . $code;
			return [ 'success' => false, 'message' => $msg ];
		}

		return [ 'success' => true, 'message' => __( 'Connected successfully to OpenAI.', 'tumblr-to-minimalio-portfolio' ) ];
	}

	/**
	 * Generate SEO data using OpenAI Vision
	 */
	public function generate_seo_data( $image_url, $tags = [], $existing_categories = [], $can_create_categories = false ) {
		if ( $this->rate_limited ) {
			return new WP_Error( 'rate_limited', 'OpenAI is rate-limited for this session.' );
		}

		$key = get_option( self::OPTION_KEY, '' );
		if ( empty( $key ) ) {
			return new WP_Error( 'not_configured', 'OpenAI API key not configured.' );
		}

		// $image_url is pre-downloaded base64 data when called from the chain
		$image_base64 = $image_url;
		if ( empty( $image_base64 ) ) {
			return new WP_Error( 'no_image_data', 'No image data provided for OpenAI analysis.' );
		}

		$prompt = $this->build_prompt( $tags, $existing_categories, $can_create_categories );

		$body = [
			'model'      => self::MODEL,
			'messages'   => [
				[
					'role'    => 'user',
					'content' => [
						[
							'type' => 'text',
							'text' => $prompt,
						],
						[
							'type'      => 'image_url',
							'image_url' => [
								'url'    => 'data:image/jpeg;base64,' . $image_base64,
								'detail' => 'low',
							],
						],
					],
				],
			],
			'max_tokens'     => 300,
			'temperature'    => 0.3,
			'response_format' => [ 'type' => 'json_object' ],
		];

		$response = wp_remote_post( self::API_BASE . 'chat/completions', [
			'timeout' => 30,
			'headers' => [
				'Authorization' => 'Bearer ' . $key,
				'Content-Type'  => 'application/json',
			],
			'body' => wp_json_encode( $body ),
		] );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'request_failed', 'OpenAI request failed: ' . $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 429 === $code ) {
			$this->set_rate_limited( true );
			return new WP_Error( 'rate_limited', 'OpenAI rate limit reached.' );
		}

		if ( 200 !== $code ) {
			$msg = isset( $data['error']['message'] ) ? $data['error']['message'] : 'HTTP ' . $code;
			return new WP_Error( 'api_error', 'OpenAI error: ' . $msg );
		}

		if ( empty( $data['choices'][0]['message']['content'] ) ) {
			return new WP_Error( 'empty_response', 'OpenAI returned an empty response.' );
		}

		$raw_text = $data['choices'][0]['message']['content'];

		return $this->parse_response( $raw_text );
	}

}
