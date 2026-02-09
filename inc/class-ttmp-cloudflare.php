<?php
/**
 * Cloudflare Workers AI Service
 *
 * @package TumblrToMinimalioPortfolio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TTMP_Cloudflare extends TTMP_AI_Service {

	protected $id   = 'cloudflare';
	protected $name = 'Cloudflare Workers AI';

	const OPTION_ACCOUNT_ID = 'ttmp_cloudflare_account_id';
	const OPTION_API_TOKEN  = 'ttmp_cloudflare_api_token';
	const API_BASE          = 'https://api.cloudflare.com/client/v4/accounts/';
	const MODEL             = '@cf/llava-hf/llava-1.5-7b-hf';

	/**
	 * Check if the service is configured
	 */
	public function is_configured() {
		$account_id = get_option( self::OPTION_ACCOUNT_ID, '' );
		$api_token  = get_option( self::OPTION_API_TOKEN, '' );
		return ! empty( $account_id ) && ! empty( $api_token );
	}

	/**
	 * Test the API connection by listing models (no inference cost)
	 */
	public function test_connection() {
		$account_id = get_option( self::OPTION_ACCOUNT_ID, '' );
		$api_token  = get_option( self::OPTION_API_TOKEN, '' );

		if ( empty( $account_id ) || empty( $api_token ) ) {
			return [ 'success' => false, 'message' => __( 'Account ID or API token not configured.', 'tumblr-to-minimalio' ) ];
		}

		$url = self::API_BASE . rawurlencode( $account_id ) . '/ai/models/search';

		$response = wp_remote_get( $url, [
			'timeout' => 15,
			'headers' => [
				'Authorization' => 'Bearer ' . $api_token,
			],
		] );

		if ( is_wp_error( $response ) ) {
			return [ 'success' => false, 'message' => $response->get_error_message() ];
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			$msg  = isset( $body['errors'][0]['message'] ) ? $body['errors'][0]['message'] : 'HTTP ' . $code;
			return [ 'success' => false, 'message' => $msg ];
		}

		return [ 'success' => true, 'message' => __( 'Connected successfully to Cloudflare Workers AI.', 'tumblr-to-minimalio' ) ];
	}

	/**
	 * Generate SEO data using Cloudflare Workers AI (LLaVA)
	 */
	public function generate_seo_data( $image_url, $tags = [], $existing_categories = [], $can_create_categories = false ) {
		if ( $this->rate_limited ) {
			return new WP_Error( 'rate_limited', 'Cloudflare Workers AI is rate-limited for this session.' );
		}

		$account_id = get_option( self::OPTION_ACCOUNT_ID, '' );
		$api_token  = get_option( self::OPTION_API_TOKEN, '' );

		if ( empty( $account_id ) || empty( $api_token ) ) {
			return new WP_Error( 'not_configured', 'Cloudflare Workers AI not configured.' );
		}

		$prompt = $this->build_prompt( $tags, $existing_categories, $can_create_categories );

		// Download image and encode as base64 data URL
		$image_base64 = $this->get_image_base64( $image_url );
		if ( empty( $image_base64 ) ) {
			return new WP_Error( 'image_download_failed', 'Could not download image for Cloudflare analysis.' );
		}

		$url = self::API_BASE . rawurlencode( $account_id ) . '/ai/run/' . self::MODEL;

		$body = [
			'messages' => [
				[
					'role'    => 'user',
					'content' => $prompt,
				],
			],
			'image' => 'data:image/jpeg;base64,' . $image_base64,
		];

		$response = wp_remote_post( $url, [
			'timeout' => 30,
			'headers' => [
				'Authorization' => 'Bearer ' . $api_token,
				'Content-Type'  => 'application/json',
			],
			'body' => wp_json_encode( $body ),
		] );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'request_failed', 'Cloudflare request failed: ' . $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 429 === $code ) {
			$this->set_rate_limited( true );
			return new WP_Error( 'rate_limited', 'Cloudflare Workers AI rate limit reached.' );
		}

		if ( 200 !== $code || ( isset( $data['success'] ) && ! $data['success'] ) ) {
			$msg = isset( $data['errors'][0]['message'] ) ? $data['errors'][0]['message'] : 'HTTP ' . $code;
			return new WP_Error( 'api_error', 'Cloudflare error: ' . $msg );
		}

		$raw_text = '';
		if ( isset( $data['result']['response'] ) ) {
			$raw_text = $data['result']['response'];
		}

		if ( empty( $raw_text ) ) {
			return new WP_Error( 'empty_response', 'Cloudflare returned an empty response.' );
		}

		return $this->parse_response( $raw_text );
	}

	/**
	 * Download an image and return its base64 encoding
	 */
	private function get_image_base64( $url ) {
		$tmp = download_url( $url, 30 );
		if ( is_wp_error( $tmp ) ) {
			return '';
		}

		$data = file_get_contents( $tmp );
		@unlink( $tmp );

		if ( empty( $data ) ) {
			return '';
		}

		return base64_encode( $data );
	}
}
