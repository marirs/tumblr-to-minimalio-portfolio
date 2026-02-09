<?php
/**
 * Tag-based fallback for SEO generation (no AI required)
 *
 * @package TumblrToMinimalioPortfolio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TTMP_Tag_Fallback {

	/**
	 * Generic/noise tags to skip when building titles
	 */
	private static $skip_tags = [
		'iphone photography', 'mobile photography', 'phone photography',
		'photographers on tumblr', 'original photographers', 'lensblr',
		'photo', 'photography', 'image', 'picture', 'pic', 'photos',
	];

	/**
	 * Tags that are likely categories
	 */
	private static $category_tags = [
		'travel', 'architecture', 'nature', 'landscape', 'portrait', 'portraits',
		'street', 'street photography', 'urban', 'wildlife', 'food', 'fashion',
		'abstract', 'macro', 'aerial', 'underwater', 'sports', 'event',
		'wedding', 'documentary', 'fine art', 'black and white', 'monochrome',
		'sunset', 'sunrise', 'night', 'cityscape', 'seascape', 'religious',
		'digital art', 'art', 'design', 'interior', 'exterior', 'automotive',
		'still life', 'floral', 'botanical', 'minimalist', 'conceptual',
	];

	/**
	 * Generate SEO data from tags
	 *
	 * @param array  $tags                  Post tags.
	 * @param array  $existing_categories   Existing portfolio category names.
	 * @param bool   $can_create_categories Whether to suggest new categories.
	 *
	 * @return array {
	 *     'title'       => string,
	 *     'description' => string,
	 *     'category'    => string|null,
	 * }
	 */
	public static function generate_seo_data( $tags = [], $existing_categories = [], $can_create_categories = false ) {
		$result = [
			'title'       => '',
			'description' => '',
			'category'    => null,
		];

		if ( empty( $tags ) ) {
			return $result;
		}

		// Filter out noise tags
		$meaningful_tags = array_filter( $tags, function ( $tag ) {
			return ! in_array( strtolower( trim( $tag ) ), self::$skip_tags, true );
		} );

		if ( empty( $meaningful_tags ) ) {
			$meaningful_tags = $tags;
		}

		$meaningful_tags = array_values( $meaningful_tags );

		// Generate title (max 60 chars, no date)
		$result['title'] = self::build_title( $meaningful_tags );

		// Generate description (max 155 chars)
		$result['description'] = self::build_description( $meaningful_tags, $tags );

		// Determine category
		$result['category'] = self::determine_category( $tags, $existing_categories, $can_create_categories );

		return $result;
	}

	/**
	 * Build an SEO title from tags
	 */
	private static function build_title( $tags ) {
		// Capitalize each tag
		$capitalized = array_map( 'ucwords', $tags );

		// Separate location-like tags from descriptive tags
		$locations   = [];
		$descriptors = [];
		$location_keywords = [ 'dubai', 'uae', 'bahrain', 'manama', 'london', 'paris', 'tokyo', 'new york', 'singapore' ];

		foreach ( $capitalized as $tag ) {
			if ( in_array( strtolower( $tag ), $location_keywords, true ) ) {
				$locations[] = $tag;
			} else {
				$descriptors[] = $tag;
			}
		}

		// Build title: "Descriptors in Location" or just "Descriptors"
		$title_parts = array_slice( $descriptors, 0, 3 );
		$title = implode( ' ', $title_parts );

		if ( ! empty( $locations ) ) {
			$location_str = implode( ', ', array_slice( $locations, 0, 2 ) );
			$candidate = $title . ' in ' . $location_str;
			if ( strlen( $candidate ) <= 60 ) {
				$title = $candidate;
			}
		}

		// Trim to 60 chars
		if ( strlen( $title ) > 60 ) {
			$title = substr( $title, 0, 57 ) . '...';
		}

		return $title;
	}

	/**
	 * Build an SEO description from tags
	 */
	private static function build_description( $meaningful_tags, $all_tags ) {
		$capitalized = array_map( 'ucwords', $meaningful_tags );

		// Build a natural-sounding description
		$tag_list = implode( ', ', array_slice( $capitalized, 0, 4 ) );

		$templates = [
			'A captivating photograph featuring %s. Explore this stunning visual composition and discover the beauty captured in this moment.',
			'Discover this beautiful image showcasing %s. A striking visual piece that captures attention and tells a compelling story.',
			'An impressive photograph highlighting %s. This image brings together elements of beauty and artistry in a single frame.',
		];

		// Pick a template based on tag hash for consistency
		$hash  = crc32( implode( '', $all_tags ) );
		$index = abs( $hash ) % count( $templates );

		$description = sprintf( $templates[ $index ], $tag_list );

		// Trim to 155 chars
		if ( strlen( $description ) > 155 ) {
			$description = substr( $description, 0, 152 ) . '...';
		}

		return $description;
	}

	/**
	 * Determine the best category from tags
	 */
	private static function determine_category( $tags, $existing_categories, $can_create_categories ) {
		$lower_tags = array_map( 'strtolower', $tags );

		// First, try to match against existing categories
		if ( ! empty( $existing_categories ) ) {
			foreach ( $existing_categories as $cat ) {
				$lower_cat = strtolower( $cat );
				if ( in_array( $lower_cat, $lower_tags, true ) ) {
					return $cat;
				}
				// Partial match
				foreach ( $lower_tags as $tag ) {
					if ( strpos( $tag, $lower_cat ) !== false || strpos( $lower_cat, $tag ) !== false ) {
						return $cat;
					}
				}
			}
		}

		// If we can create new categories, find the best category-like tag
		if ( $can_create_categories ) {
			foreach ( $lower_tags as $tag ) {
				if ( in_array( $tag, self::$category_tags, true ) ) {
					return ucwords( $tag );
				}
			}
			// Use the first meaningful tag as category
			$meaningful = array_filter( $lower_tags, function ( $tag ) {
				return ! in_array( $tag, self::$skip_tags, true );
			} );
			if ( ! empty( $meaningful ) ) {
				return ucwords( reset( $meaningful ) );
			}
		}

		return null;
	}
}
