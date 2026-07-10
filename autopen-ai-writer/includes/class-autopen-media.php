<?php
/**
 * Handles media generation and attachment.
 *
 * This class is responsible for the entire image handling pipeline:
 * 1. Calling the API to generate an image from a prompt.
 * 2. Downloading the generated image to the server.
 * 3. Sideloading the image into the WordPress Media Library.
 * 4. Attaching the image to a post and applying SEO metadata (alt text, title, etc.).
 *
 * @package AutoPen
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class AutoPen_Media {

	/**
	 * Generates, downloads, and attaches an image to a post.
	 *
	 * @since 1.0.0
	 * @param string $prompt      The text prompt for image generation.
	 * @param int    $post_id     The ID of the post to attach the image to.
	 * @param array  $meta        An array of metadata (title, alt_text, etc.) for the image.
	 * @param bool   $is_featured True if this is a featured image, false otherwise.
	 * @return int|WP_Error The attachment ID on success, or a WP_Error object on failure.
	 */
	public static function sideload_image( string $prompt, int $post_id, array $meta, bool $is_featured ) {
		// Step 1: Generate the image via the API.
		$generation_result = AutoPen_API::generate_image( $prompt, $is_featured );
		if ( ! $generation_result['success'] ) {
			AutoPen_Logger::error( 'Image generation failed: ' . $generation_result['error'] );
			return new WP_Error( 'api_generation_failed', $generation_result['error'] );
		}
		$image_url = $generation_result['url'];

		// Step 2: Download the image to a temporary file.
		// WordPress core functions required for this process.
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$temp_file = download_url( $image_url, 300 ); // 300-second timeout.
		if ( is_wp_error( $temp_file ) ) {
			AutoPen_Logger::error( 'Image download failed: ' . $temp_file->get_error_message() );
			return $temp_file;
		}

		// Step 3: Prepare the file for sideloading.
		$file_name = self::generate_seo_filename( $meta, $post_id );
		$file_array = [
			'name'     => $file_name,
			'tmp_name' => $temp_file,
		];

		// Step 4: Sideload the image into the Media Library.
		// More WordPress core functions required.
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		
		$attachment_id = media_handle_sideload( $file_array, $post_id );

		// Clean up the temporary file.
		if ( file_exists( $temp_file ) ) {
			wp_delete_file( $temp_file );
		}

		if ( is_wp_error( $attachment_id ) ) {
			AutoPen_Logger::error( 'Media sideloading failed: ' . $attachment_id->get_error_message() );
			return $attachment_id;
		}

		// Step 5: Apply the provided metadata to the new attachment.
		self::apply_metadata( $attachment_id, $meta );

		return $attachment_id;
	}

	/**
	 * Generates an SEO-friendly filename for an image.
	 *
	 * It prioritizes the AI-generated filename, falls back to alt text,
	 * and finally uses the post title if nothing else is available.
	 *
	 * @since 1.0.0
	 * @param array $meta    The image metadata.
	 * @param int   $post_id The post ID.
	 * @return string The unique, sanitized filename.
	 */
	private static function generate_seo_filename( array $meta, int $post_id ): string {
		$base_name = '';
		if ( ! empty( $meta['file_name'] ) ) {
			$base_name = pathinfo( $meta['file_name'], PATHINFO_FILENAME );
		} elseif ( ! empty( $meta['alt_text'] ) ) {
			$base_name = $meta['alt_text'];
		} else {
			$base_name = get_the_title( $post_id );
		}
		$sanitized_name = sanitize_title( $base_name );
		// Ensure the filename is unique within the uploads directory.
		$upload_dir = wp_get_upload_dir();
		return wp_unique_filename( $upload_dir['path'], $sanitized_name . '.jpg' );
	}

	/**
	 * Applies metadata (title, caption, alt text, description) to a media attachment.
	 *
	 * @since 1.0.0
	 * @param int   $attachment_id The ID of the attachment.
	 * @param array $meta          The metadata to apply.
	 */
	private static function apply_metadata( int $attachment_id, array $meta ): void {
		$update_data = [ 'ID' => $attachment_id ];
		if ( ! empty( $meta['title'] ) ) {
			$update_data['post_title'] = sanitize_text_field( $meta['title'] );
		}
		if ( ! empty( $meta['caption'] ) ) {
			$update_data['post_excerpt'] = sanitize_text_field( $meta['caption'] );
		}
		if ( ! empty( $meta['description'] ) ) {
			$update_data['post_content'] = sanitize_textarea_field( $meta['description'] );
		}
		// Update the attachment post data.
		wp_update_post( $update_data );

		// Alt text is stored in post meta, not the post table.
		if ( ! empty( $meta['alt_text'] ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $meta['alt_text'] ) );
		}
	}
}