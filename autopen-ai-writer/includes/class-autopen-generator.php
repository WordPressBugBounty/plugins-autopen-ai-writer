<?php
/**
 * The core content generation pipeline.
 *
 * This class contains the entire step-by-step logic for generating a post,
 * from fetching a topic to publishing the final, SEO-optimized article with images.
 * It handles both automated cron generation and manual, user-initiated generation.
 *
 * @package AutoPen
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class AutoPen_Generator {

	/**
	 * The main function executed by the WP Cron job.
	 * Manages the state of a multi-step generation process across multiple cron runs.
	 *
	 * @since 1.0.0
	 */
	public static function cron_generate(): void {
		// Use a transient to lock the process, preventing multiple cron jobs from running simultaneously.
		if ( get_transient( 'autopen_cron_lock' ) ) {
			AutoPen_Logger::info( 'Cron skipped: Lock is active.' );
			return;
		}

		set_transient( 'autopen_cron_lock', true, 300 ); // Lock for 5 minutes.

		try {
			// Set high resource limits for the generation process.
			// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
			@ini_set( 'max_execution_time', 0 );
			// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
			@ini_set( 'memory_limit', '512M' );
			// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
			@set_time_limit( 0 );
			ignore_user_abort( true );

			// Get the state of the current generation process from the options table.
			$state = get_option( 'autopen_cron_state', false );

			// If no state exists, this is the start of a new generation process.
			if ( ! $state ) {
				$options = AutoPen_Utils::get_options();
				if ( empty( $options['api_key'] ) ) {
					throw new Exception( esc_html__( 'Cannot generate: OpenAI API Key is not configured.', 'autopen-ai-writer' ) );
				}
				if ( empty( $options['cron_enabled'] ) ) {
					throw new Exception( esc_html__( 'Automation is disabled.', 'autopen-ai-writer' ) );
				}
				$topics = AutoPen_Utils::get_lines_from_textarea( $options['topics_queue'] );
				if ( empty( $topics ) ) {
					throw new Exception( esc_html__( 'Topic queue is empty.', 'autopen-ai-writer' ) );
				}

				// Initialize the state for the first topic in the queue.
				$raw_topic   = $topics[0];
				$clean_topic = preg_replace( '/\s*\(failed: \d+\)$/', '', $raw_topic );
				AutoPen_Logger::info( "Starting cron stepwise generation for topic: \"{$clean_topic}\"." );

				$state = [
					'raw_topic'     => $raw_topic,
					'clean_topic'   => $clean_topic,
					'step'          => 'generate_content',
					'data'          => [],
					'post_id'       => 0,
					'image_map'     => [],
					'current_image' => 1,
					'image_hints'   => '',
				];

				update_option( 'autopen_cron_state', $state );
			}

			// Execute the current step. The state is passed by reference and will be updated.
			$result = self::do_step( $state );

			// Save the updated state.
			update_option( 'autopen_cron_state', $state );

			if ( $result['done'] ) {
				// If the process is finished, delete the state.
				delete_option( 'autopen_cron_state' );
			} else {
				// If not done, schedule a one-off event to run the next step shortly.
				wp_schedule_single_event( time() + 10, AUTOPEN_CRON_HOOK );
			}
		} catch ( Exception $e ) {
			// Handle any exceptions during the process.
			$state = get_option( 'autopen_cron_state', false );
			if ( $state && isset( $state['raw_topic'] ) ) {
				self::handle_failure( $state['raw_topic'], $e->getMessage() );
			}
			delete_option( 'autopen_cron_state' );
			AutoPen_Logger::error( 'Cron generation failed: ' . $e->getMessage() );
		} finally {
			// Always release the lock.
			delete_transient( 'autopen_cron_lock' );
		}
	}

	/**
	 * Starts the manual generation process. (AJAX callback)
	 *
	 * @since 1.0.0
	 * @throws Exception If preconditions for generation are not met.
	 * @return array The initial status message.
	 */
	public static function manual_start(): array {
		$options = AutoPen_Utils::get_options();

		if ( empty( $options['api_key'] ) ) {
			throw new Exception( esc_html__( 'Cannot generate: OpenAI API Key is not configured.', 'autopen-ai-writer' ) );
		}

		$topics = AutoPen_Utils::get_lines_from_textarea( $options['topics_queue'] );
		if ( empty( $topics ) ) {
			throw new Exception( esc_html__( 'Topic queue is empty.', 'autopen-ai-writer' ) );
		}

		// Initialize the state and store it in a user-specific transient.
		$raw_topic   = $topics[0];
		$clean_topic = preg_replace( '/\s*\(failed: \d+\)$/', '', $raw_topic );
		AutoPen_Logger::info( "Starting manual stepwise generation for topic: \"{$clean_topic}\"." );

		$state = [
			'raw_topic'     => $raw_topic,
			'clean_topic'   => $clean_topic,
			'step'          => 'generate_content',
			'data'          => [],
			'post_id'       => 0,
			'image_map'     => [],
			'current_image' => 1,
			'image_hints'   => '',
		];

		$user_id = get_current_user_id();
		set_transient( 'autopen_manual_state_' . $user_id, $state, HOUR_IN_SECONDS );

		return [
			'message' => __( 'Started generation for topic: ', 'autopen-ai-writer' ) . esc_html( $clean_topic ),
			'done'    => false,
		];
	}

	/**
	 * Executes the next step in the manual generation process. (AJAX callback)
	 *
	 * @since 1.0.0
	 * @throws Exception If no active state is found.
	 * @return array The result of the current step.
	 */
	public static function manual_do_step(): array {
		$user_id = get_current_user_id();
		$state   = get_transient( 'autopen_manual_state_' . $user_id );
		if ( ! $state ) {
			throw new Exception( esc_html__( 'No active generation state found.', 'autopen-ai-writer' ) );
		}

		// Execute the step.
		$result = self::do_step( $state );

		// Save the updated state back to the transient.
		set_transient( 'autopen_manual_state_' . $user_id, $state, HOUR_IN_SECONDS );

		if ( $result['done'] ) {
			// Clean up the transient if the process is complete.
			delete_transient( 'autopen_manual_state_' . $user_id );
		}

		return $result;
	}

	/**
	 * The core state machine for the generation pipeline.
	 *
	 * Executes a single step based on the current state and advances the
	 * state to the next step.
	 *
	 * @since 1.0.0
	 * @param array &$state The current generation state (passed by reference).
	 * @return array The result of the step, including a message and a 'done' flag.
	 * @throws Exception If a critical step fails.
	 */
	private static function do_step( array &$state ): array {
		$options = AutoPen_Utils::get_options();
		$message = '';

		switch ( $state['step'] ) {
			// Step 1: Generate the main article content (title and HTML).
			case 'generate_content':
				AutoPen_Logger::info( 'Generating main article content...' );
				$context        = AutoPen_Utils::build_prompt_context( $options );
				$content_result = AutoPen_API::generate_article_content( $state['clean_topic'], $context );
				if ( ! $content_result['success'] ) {
					throw new Exception( esc_html( $content_result['error'] ) );
				}
				$state['data'] = $content_result['data'];
				if ( empty( $state['data']['title'] ) || empty( $state['data']['content_html'] ) ) {
					throw new Exception( esc_html__( 'AI failed to return a valid title and content_html.', 'autopen-ai-writer' ) );
				}
				$state['step'] = 'extract_hints';
				$message       = __( 'Content generated successfully.', 'autopen-ai-writer' );
				AutoPen_Logger::info( $message );
				break;

			// Step 2: Extract image hints from the generated HTML.
			case 'extract_hints':
				$state['image_hints'] = self::extract_image_hints( $state['data']['content_html'] );
				$state['step']        = 'generate_metadata';
				$message              = __( 'Image hints extracted.', 'autopen-ai-writer' );
				AutoPen_Logger::info( $message );
				break;

			// Step 3: Generate SEO metadata based on the content.
			case 'generate_metadata':
				AutoPen_Logger::info( 'Generating SEO metadata...' );
				$context         = AutoPen_Utils::build_prompt_context( $options, $state['data']['title'], $state['image_hints'] );
				$metadata_result = AutoPen_API::generate_article_metadata( $state['data']['content_html'], $context );
				if ( ! $metadata_result['success'] ) {
					throw new Exception( esc_html( $metadata_result['error'] ) );
				}
				$state['data'] = array_merge( $state['data'], $metadata_result['data'] );
				$state['step'] = 'create_post';
				$message       = __( 'Metadata generated successfully.', 'autopen-ai-writer' );
				AutoPen_Logger::info( $message );
				break;

			// Step 4: Create a draft post in WordPress.
			case 'create_post':
				$post_id = self::create_post( $state['data'], $options );
				if ( is_wp_error( $post_id ) ) {
					throw new Exception( esc_html( $post_id->get_error_message() ) );
				}
				$state['post_id'] = $post_id;
				$state['step']    = 'generate_featured_image';
				$message          = __( 'Post created in WordPress.', 'autopen-ai-writer' );
				AutoPen_Logger::info( $message );
				break;

			// Step 5: Generate and attach the featured image.
			case 'generate_featured_image':
				if ( ! empty( $state['data']['images']['featured'] ) ) {
					AutoPen_Logger::info( 'Generating featured image...' );
					$meta          = $state['data']['images']['featured'];
					$attachment_id = AutoPen_Media::sideload_image( $meta['prompt'], $state['post_id'], $meta, true );
					if ( ! is_wp_error( $attachment_id ) ) {
						set_post_thumbnail( $state['post_id'], $attachment_id );
					} else {
						AutoPen_Logger::warning( 'Featured image generation failed: ' . $attachment_id->get_error_message() );
					}
				}
				$state['step']          = 'generate_in_post_image';
				$state['current_image'] = 1; // Reset counter for in-post images.
				$message                = __( 'Featured image processed.', 'autopen-ai-writer' );
				AutoPen_Logger::info( $message );
				break;

			// Step 6: Loop through and generate all in-post images.
			case 'generate_in_post_image':
				$images = $state['data']['images']['in_post'] ?? [];
				$index  = $state['current_image'] - 1;
				if ( $index < count( $images ) ) {
					// This step repeats for each in-post image.
					AutoPen_Logger::info( 'Generating in-post image ' . $state['current_image'] . '...' );
					$img_meta      = $images[ $index ];
					$attachment_id = AutoPen_Media::sideload_image( $img_meta['prompt'], $state['post_id'], $img_meta, false );
					if ( ! is_wp_error( $attachment_id ) ) {
						$state['image_map'][ $state['current_image'] ] = $attachment_id;
					} else {
						AutoPen_Logger::warning( 'In-post image ' . $state['current_image'] . ' failed: ' . $attachment_id->get_error_message() );
					}
					$state['current_image']++;
					$message = __( 'In-post image ', 'autopen-ai-writer' ) . ( $state['current_image'] - 1 ) . __( ' processed.', 'autopen-ai-writer' );
					AutoPen_Logger::info( $message );
				} else {
					// Once all images are processed, move to the next step.
					$state['step'] = 'finalize_content';
					$message       = __( 'All in-post images processed.', 'autopen-ai-writer' );
					AutoPen_Logger::info( $message );
				}
				break;

			// Step 7: Finalize the post content by inserting links and images.
			case 'finalize_content':
				$final_content = self::insert_links_into_content( $state['data']['content_html'], $state['data']['links'] ?? [] );
				$final_content = self::replace_image_placeholders( $final_content, $state['image_map'] );
				wp_update_post(
					[
						'ID'           => $state['post_id'],
						'post_content' => $final_content,
						'post_status'  => $options['publish_status'],
					]
				);
				$state['step'] = 'apply_taxonomies';
				$message       = __( 'Content finalized and updated.', 'autopen-ai-writer' );
				AutoPen_Logger::info( $message );
				break;

			// Step 8: Apply categories and tags.
			case 'apply_taxonomies':
				self::apply_taxonomies( $state['post_id'], $state['data'], $options );
				$state['step'] = 'apply_seo';
				$message       = __( 'Taxonomies applied.', 'autopen-ai-writer' );
				AutoPen_Logger::info( $message );
				break;

			// Step 9: Apply metadata for integrated SEO plugins.
			case 'apply_seo':
				AutoPen_SEO::apply_meta( $state['post_id'], $state['data'], $options['seo_plugin'] );
				$state['step'] = 'handle_success';
				$message       = __( 'SEO metadata applied.', 'autopen-ai-writer' );
				AutoPen_Logger::info( $message );
				break;

			// Step 10: Final success handler.
			case 'handle_success':
				self::handle_success( $state['post_id'] );
				$message = __( 'Generation complete!', 'autopen-ai-writer' );
				AutoPen_Logger::info( $message );
				return [
					'message'   => $message,
					'done'      => true,
					'post_id'   => $state['post_id'],
					'edit_link' => get_edit_post_link( $state['post_id'] ),
					'view_link' => get_permalink( $state['post_id'] ),
				];
		}

		return [
			'message' => $message,
			'done'    => false,
		];
	}
	
	/**
	 * Creates a new post with placeholder content.
	 *
	 * @since 1.0.0
	 * @param array $data The generated data containing title and slug.
	 * @param array $options The plugin settings.
	 * @return int|WP_Error The new post ID or a WP_Error object on failure.
	 */
	private static function create_post( array $data, array $options ) {
		$post_data = [
			'post_title'   => sanitize_text_field( $data['title'] ),
			'post_name'    => sanitize_title( $data['slug'] ?? $data['title'] ),
			'post_content' => '', // Placeholder to be replaced later.
			'post_status'  => 'draft', // Always create as draft first.
			'post_author'  => $options['default_author'],
			'post_type'    => 'post',
		];
		$post_id   = wp_insert_post( $post_data, true );
		if ( ! is_wp_error( $post_id ) ) {
			// Add meta flag to identify this post as AI-generated.
			update_post_meta( $post_id, '_autopen_generated', true );
		}
		return $post_id;
	}

	/**
	 * Inserts internal and external links into the content.
	 *
	 * @since 1.0.0
	 * @param string $content The HTML content.
	 * @param array  $links   An array of link objects to insert.
	 * @return string The content with links inserted.
	 */
	private static function insert_links_into_content( string $content, array $links ): string {
		if ( empty( $links ) ) {
			return $content;
		}
		foreach ( $links as $link ) {
			if ( empty( $link['anchor'] ) || empty( $link['url'] ) ) {
				continue;
			}
			$anchor    = esc_html( $link['anchor'] );
			$url       = esc_url( $link['url'] );
			$rel       = ( $link['type'] ?? 'internal' ) === 'external' ? 'target="_blank" rel="nofollow noopener noreferrer"' : '';
			$link_html = sprintf( '<a href="%s" %s>%s</a>', $url, $rel, $anchor );
			// Use a regex to replace the first occurrence of the anchor text that is not already inside a link.
			$pattern = '/\b(' . preg_quote( $anchor, '/' ) . ')\b(?![^<]*?>)/i';
			$content = preg_replace( $pattern, $link_html, $content, 1 );
		}
		return $content;
	}

	/**
	 * Replaces image placeholders like [IN_POST_IMG_1] with the actual image HTML.
	 *
	 * @since 1.0.0
	 * @param string $content The HTML content with placeholders.
	 * @param array  $image_map An associative array mapping image number to attachment ID.
	 * @return string The content with images.
	 */
	private static function replace_image_placeholders( string $content, array $image_map ): string {
		if ( empty( $image_map ) ) {
			// Remove any placeholders if image generation failed.
			return preg_replace( '/(?:<p>)?\s*\[\s*IN_POST_IMG_(\d+)[^\]]*\]\s*(?:<\/p>)?/i', '', $content );
		}
		
		return preg_replace_callback(
			'/(?:<p>)?\s*\[\s*IN_POST_IMG_(\d+)[^\]]*\]\s*(?:<\/p>)?/i',
			function ( $matches ) use ( $image_map ) {
				$image_number = (int) $matches[1];
				if ( isset( $image_map[ $image_number ] ) ) {
					$attachment_id = $image_map[ $image_number ];
					$image_html    = wp_get_attachment_image( $attachment_id, 'large', false, [ 'class' => 'aligncenter' ] );
					if ( $image_html ) {
						// Wrap the image in a figure for block editor compatibility.
						return '<figure class="wp-block-image size-large aligncenter">' . $image_html . '</figure>';
					}
				}
				return ''; // Remove placeholder if image not found.
			},
			$content
		);
	}

	/**
	 * Extracts image hints from placeholders in the generated content.
	 *
	 * @since 1.0.0
	 * @param string $content The HTML content.
	 * @return string A newline-separated list of hints.
	 */
	private static function extract_image_hints( string $content ): string {
		preg_match_all( '/\[\s*IN_POST_IMG_(\d+)\s*hint:\s*([^\]]+)\]/i', $content, $matches, PREG_SET_ORDER );
		$hints = [];
		foreach ( $matches as $match ) {
			$hints[] = "Image {$match[1]}: " . trim( $match[2] );
		}
		return implode( "\n", $hints );
	}

	/**
	 * Applies categories and tags to a post.
	 * Creates categories if they don't exist, including parent/child relationships.
	 *
	 * @since 1.0.0
	 * @param int   $post_id The ID of the post.
	 * @param array $data    The generated data containing taxonomies.
	 * @param array $options The plugin settings.
	 */
	private static function apply_taxonomies( int $post_id, array $data, array $options ): void {
		// Handle categories.
		if ( ! empty( $options['enable_categories'] ) && ! empty( $data['category'] ) ) {
			$cat_name  = sanitize_text_field( $data['category'] );
			$parent_id = 0;
			// Check for parent > child category format.
			if ( strpos( $cat_name, '>' ) !== false ) {
				$parts       = array_map( 'trim', explode( '>', $cat_name, 2 ) );
				$parent_name = $parts[0];
				$child_name  = $parts[1];
				$parent_term = term_exists( $parent_name, 'category' );
				if ( 0 !== $parent_term && null !== $parent_term ) {
					$parent_id = (int) $parent_term['term_id'];
				} else {
					$new_parent = wp_insert_term( $parent_name, 'category' );
					if ( ! is_wp_error( $new_parent ) ) {
						$parent_id = (int) $new_parent['term_id'];
					}
				}
				$cat_name = $child_name;
			}
			$term_id       = 0;
			$existing_term = term_exists( $cat_name, 'category', $parent_id );
			if ( 0 !== $existing_term && null !== $existing_term ) {
				$term_id = (int) $existing_term['term_id'];
			} else {
				$new_term = wp_insert_term( $cat_name, 'category', [ 'parent' => $parent_id ] );
				if ( ! is_wp_error( $new_term ) ) {
					$term_id = (int) $new_term['term_id'];
				}
			}
			if ( $term_id > 0 ) {
				wp_set_post_categories( $post_id, [ $term_id ] );
			}
		}
		// Handle tags.
		if ( ! empty( $options['enable_tags'] ) && ! empty( $data['tags'] ) && is_array( $data['tags'] ) ) {
			wp_set_post_tags( $post_id, array_map( 'sanitize_text_field', $data['tags'] ) );
		}
	}

	/**
	 * Handles the successful completion of a generation.
	 * Removes the topic from the queue and updates stats.
	 *
	 * @since 1.0.0
	 * @param int $post_id The ID of the newly created post.
	 */
	private static function handle_success( int $post_id ): void {
		$options = AutoPen_Utils::get_options();
		$topics  = AutoPen_Utils::get_lines_from_textarea( $options['topics_queue'] );
		array_shift( $topics ); // Remove the successfully processed topic from the top.
		$options['topics_queue']    = implode( "\n", $topics );
		$options['posts_generated'] = (int) $options['posts_generated'] + 1;
		$options['last_post_id']    = $post_id;
		update_option( AUTOPEN_OPTIONS_KEY, $options );
	}

	/**
	 * Handles a failure during the generation process.
	 * Re-queues the topic or moves it to a failed queue after multiple attempts.
	 *
	 * @since 1.0.0
	 * @param string $raw_topic The topic string, which may contain failure count.
	 * @param string $error     The error message.
	 */
	private static function handle_failure( string $raw_topic, string $error ): void {
		AutoPen_Logger::error( "Generation failed for topic \"{$raw_topic}\": {$error}" );
		$options       = AutoPen_Utils::get_options();
		$topics        = AutoPen_Utils::get_lines_from_textarea( $options['topics_queue'] );
		$failed_topics = AutoPen_Utils::get_lines_from_textarea( $options['failed_topics'] );
		$key           = array_search( $raw_topic, $topics, true );
		if ( false === $key ) {
			return; // Topic not found, maybe already processed.
		}

		$clean_topic   = preg_replace( '/\s*\(failed: \d+\)$/', '', $raw_topic );
		$failure_count = 0;
		if ( preg_match( '/\(failed: (\d+)\)$/', $raw_topic, $matches ) ) {
			$failure_count = (int) $matches[1];
		}
		$failure_count++;
		unset( $topics[ $key ] ); // Remove the topic from its current position.

		if ( $failure_count >= 3 ) {
			// After 3 failures, move to the permanent failed queue.
			$failed_topics[] = $clean_topic . ' (Reason: ' . $error . ')';
			AutoPen_Logger::error( "Topic \"{$clean_topic}\" has failed 3 times and is being moved to the failed queue." );
		} else {
			// Otherwise, add it to the bottom of the queue with an updated failure count.
			$topics[] = $clean_topic . " (failed: {$failure_count})";
		}
		$options['topics_queue']  = implode( "\n", array_values( $topics ) );
		$options['failed_topics'] = implode( "\n", $failed_topics );
		update_option( AUTOPEN_OPTIONS_KEY, $options );
	}
}