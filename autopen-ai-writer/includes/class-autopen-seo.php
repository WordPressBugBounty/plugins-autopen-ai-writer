<?php
/**
 * Handles integration with popular SEO plugins.
 *
 * This class provides a centralized way to apply SEO metadata (like meta title,
 * description, and focus keywords) to posts. It detects which supported SEO
 * plugin is active and uses the correct post meta keys for that plugin.
 *
 * @package AutoPen
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class AutoPen_SEO {
	
	/**
	 * A wrapper function that applies SEO metadata based on the selected plugin.
	 *
	 * @since 1.0.0
	 * @param int    $post_id         The ID of the post to apply metadata to.
	 * @param array  $data            An array of AI-generated SEO data.
	 * @param string $selected_plugin The slug of the SEO plugin selected in settings.
	 */
	public static function apply_meta( int $post_id, array $data, string $selected_plugin ): void {
		switch ( $selected_plugin ) {
			case 'rankmath':
				// Check if the free or pro version of Rank Math is active.
				if ( is_plugin_active( 'seo-by-rank-math/rank-math.php' ) || is_plugin_active( 'seo-by-rank-math-pro/rank-math-pro.php' ) ) {
					self::apply_rankmath_meta( $post_id, $data );
					AutoPen_Logger::info( "Applied SEO metadata for Rank Math on post #{$post_id}." );
				}
				break;
			case 'seopress':
				// Check if the free or pro version of SEOPress is active.
				if ( is_plugin_active( 'wp-seopress/seopress.php' ) || is_plugin_active( 'wp-seopress-pro/seopress-pro.php' ) ) {
					self::apply_seopress_meta( $post_id, $data );
					AutoPen_Logger::info( "Applied SEO metadata for SEOPress on post #{$post_id}." );
				}
				break;
			case 'yoast':
				// Check if the free or pro version of Yoast SEO is active.
				if ( is_plugin_active( 'wordpress-seo/wp-seo.php' ) || is_plugin_active( 'wordpress-seo-premium/wp-seo-premium.php' ) ) {
					self::apply_yoast_meta( $post_id, $data );
					AutoPen_Logger::info( "Applied SEO metadata for Yoast SEO on post #{$post_id}." );
				}
				break;
		}
	}

	/**
	 * Applies metadata specifically for the Rank Math SEO plugin.
	 *
	 * @since 1.0.0
	 * @param int   $post_id The post ID.
	 * @param array $data    The SEO data.
	 */
	private static function apply_rankmath_meta( int $post_id, array $data ): void {
		if ( ! empty( $data['meta_title'] ) ) {
			update_post_meta( $post_id, 'rank_math_title', sanitize_text_field( $data['meta_title'] ) );
		}
		if ( ! empty( $data['meta_description'] ) ) {
			update_post_meta( $post_id, 'rank_math_description', sanitize_text_field( $data['meta_description'] ) );
		}
		if ( ! empty( $data['focus_keyword'] ) ) {
			// Rank Math stores keywords as a comma-separated string.
			$keywords = [ sanitize_text_field( $data['focus_keyword'] ) ];
			if ( ! empty( $data['supporting_keywords'] ) && is_array( $data['supporting_keywords'] ) ) {
				$keywords = array_merge( $keywords, array_map( 'sanitize_text_field', $data['supporting_keywords'] ) );
			}
			update_post_meta( $post_id, 'rank_math_focus_keyword', implode( ',', array_slice( $keywords, 0, 5 ) ) );
		}
	}
	
	/**
	 * Applies metadata specifically for the SEOPress plugin.
	 *
	 * @since 1.0.0
	 * @param int   $post_id The post ID.
	 * @param array $data    The SEO data.
	 */
	private static function apply_seopress_meta( int $post_id, array $data ): void {
		if ( ! empty( $data['meta_title'] ) ) {
			update_post_meta( $post_id, '_seopress_titles_title', sanitize_text_field( $data['meta_title'] ) );
		}
		if ( ! empty( $data['meta_description'] ) ) {
			update_post_meta( $post_id, '_seopress_titles_desc', sanitize_text_field( $data['meta_description'] ) );
		}
		if ( ! empty( $data['focus_keyword'] ) ) {
			// SEOPress also stores keywords as a comma-separated string.
			$keywords = [ sanitize_text_field( $data['focus_keyword'] ) ];
			if ( ! empty( $data['supporting_keywords'] ) && is_array( $data['supporting_keywords'] ) ) {
				$keywords = array_merge( $keywords, array_map( 'sanitize_text_field', $data['supporting_keywords'] ) );
			}
			update_post_meta( $post_id, '_seopress_analysis_target_kw', implode( ',', $keywords ) );
		}
	}

	/**
	 * Applies metadata specifically for the Yoast SEO plugin.
	 *
	 * @since 1.0.0
	 * @param int   $post_id The post ID.
	 * @param array $data    The SEO data.
	 */
	private static function apply_yoast_meta( int $post_id, array $data ): void {
		if ( ! empty( $data['meta_title'] ) ) {
			update_post_meta( $post_id, '_yoast_wpseo_title', sanitize_text_field( $data['meta_title'] ) );
		}
		if ( ! empty( $data['meta_description'] ) ) {
			update_post_meta( $post_id, '_yoast_wpseo_metadesc', sanitize_text_field( $data['meta_description'] ) );
		}
		// Yoast's free version only supports a single focus keyword.
		if ( ! empty( $data['focus_keyword'] ) ) {
			update_post_meta( $post_id, '_yoast_wpseo_focuskw', sanitize_text_field( $data['focus_keyword'] ) );
		}
	}
}