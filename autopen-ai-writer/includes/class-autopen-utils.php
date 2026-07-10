<?php
/**
 * Provides utility functions and manages plugin options.
 *
 * This is a helper class with static methods for common tasks like retrieving
 * and sanitizing options, defining the settings fields for the admin UI,
 * getting system status, and preparing prompts for the API.
 *
 * @package AutoPen
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class AutoPen_Utils {

	/**
	 * Gets the plugin's options, merging saved values with defaults.
	 *
	 * @since 1.0.0
	 * @return array The complete array of plugin options.
	 */
	public static function get_options(): array {
		$options = get_option( AUTOPEN_OPTIONS_KEY, [] );
		// wp_parse_args ensures that any missing options are filled with their default values.
		return wp_parse_args( $options, self::get_default_options() );
	}

	/**
	 * Defines the default options for the plugin.
	 *
	 * @since 1.0.0
	 * @return array An array of default settings.
	 */
	public static function get_default_options(): array {
		return [
			'api_key'                => '',
			'gpt_model'              => 'gpt-4o',
			'image_model'            => 'dall-e-3',
			'topics_queue'           => "How to write a perfect blog post\nTips for effective content marketing",
			'failed_topics'          => '',
			'internal_links_pool'    => home_url( '/' ),
			'publish_status'         => 'draft',
			'default_author'         => get_current_user_id() ?: 1,
			'enable_categories'      => 1,
			'enable_tags'            => 1,
			'images_per_post'        => 2,
			'featured_image_size'    => '1792x1024',
			'featured_image_quality' => 'hd',
			'min_word_count'         => 1200,
			'seo_plugin'             => 'none',
			'frequency'              => 'daily',
			'cron_enabled'           => 1,
			'posts_generated'        => 0,
			'last_post_id'           => 0,
			'system_prompt'          => self::get_default_system_prompt(),
			'content_prompt'         => self::get_default_content_prompt(),
			'metadata_prompt'        => self::get_default_metadata_prompt(),
		];
	}

	/**
	 * Sanitizes the plugin options before saving them to the database.
	 *
	 * This is a callback function for the `register_setting` WordPress function.
	 *
	 * @since 1.0.0
	 * @param  array $input The raw input from the settings form.
	 * @return array The sanitized input.
	 */
	public static function sanitize_options( array $input ): array {
		$existing_options = self::get_options();
		$sanitized_input  = [];
		$all_field_keys   = array_keys( self::get_default_options() );
		$valid_frequencies = array_keys( wp_get_schedules() );

		foreach ( $all_field_keys as $key ) {
			if ( ! isset( $input[ $key ] ) ) {
				// Handle checkboxes that are not present in the input (i.e., unchecked).
				if ( in_array( $key, [ 'enable_categories', 'enable_tags', 'cron_enabled' ], true ) ) {
					// Only set to 0 if not an AJAX request, to avoid clearing on partial saves.
					if ( ! ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
						$sanitized_input[ $key ] = 0;
					}
				}
				continue;
			}
			
			// Sanitize each field based on its expected type.
			switch ( $key ) {
				case 'api_key':
				case 'gpt_model':
				case 'image_model':
				case 'publish_status':
				case 'featured_image_size':
				case 'featured_image_quality':
				case 'seo_plugin':
					$sanitized_input[ $key ] = sanitize_text_field( $input[ $key ] );
					break;
				case 'frequency':
					$value = sanitize_text_field( $input[ $key ] );
					// Ensure the selected frequency is a valid, registered cron schedule.
					$sanitized_input[ $key ] = in_array( $value, $valid_frequencies, true ) ? $value : 'daily';
					break;
				case 'topics_queue':
				case 'failed_topics':
				case 'internal_links_pool':
				case 'system_prompt':
				case 'content_prompt':
				case 'metadata_prompt':
					$sanitized_input[ $key ] = sanitize_textarea_field( $input[ $key ] );
					break;
				case 'default_author':
				case 'images_per_post':
				case 'min_word_count':
				case 'posts_generated':
				case 'last_post_id':
					$sanitized_input[ $key ] = absint( $input[ $key ] ); // Ensures positive integer.
					break;
				case 'enable_categories':
				case 'enable_tags':
				case 'cron_enabled':
					$sanitized_input[ $key ] = isset( $input[ $key ] ) && $input[ $key ] ? 1 : 0; // Cast to 1 or 0.
					break;
			}
		}
		// Merge sanitized new values with existing options to ensure no data is lost.
		return wp_parse_args( $sanitized_input, $existing_options );
	}

	/**
	 * Defines the structure and data for all settings fields.
	 *
	 * @since 1.0.0
	 * @return array A multi-dimensional array defining all fields.
	 */
	private static function get_settings_fields(): array {
		// Get available users for the author dropdown.
		$users = get_users( [ 'role__in' => [ 'author', 'editor', 'administrator' ] ] );
		$user_options = [];
		foreach ( $users as $user ) {
			$user_options[ $user->ID ] = $user->display_name;
		}

		// Get available cron schedules for the frequency dropdown.
		$schedules = wp_get_schedules();
		$schedule_options = [];
		// Define a list of schedules that we want to show in the UI.
		$valid_schedules = [
			'hourly'                 => [ 'display' => __( 'Every 1 Hour', 'autopen-ai-writer' ) ],
			'autopen_every_2_hours'  => [ 'display' => __( 'Every 2 Hours', 'autopen-ai-writer' ) ],
			'autopen_every_4_hours'  => [ 'display' => __( 'Every 4 Hours', 'autopen-ai-writer' ) ],
			'autopen_every_6_hours'  => [ 'display' => __( 'Every 6 Hours', 'autopen-ai-writer' ) ],
			'autopen_every_12_hours' => [ 'display' => __( 'Every 12 Hours', 'autopen-ai-writer' ) ],
			'daily'                  => [ 'display' => __( 'Once Daily', 'autopen-ai-writer' ) ],
			'autopen_every_2_days'   => [ 'display' => __( 'Every 2 Days', 'autopen-ai-writer' ) ],
			'autopen_twice_weekly'   => [ 'display' => __( 'Twice Weekly', 'autopen-ai-writer' ) ],
			'weekly'                 => [ 'display' => __( 'Weekly', 'autopen-ai-writer' ) ],
		];
		foreach ( $valid_schedules as $key => $details ) {
			if ( isset( $schedules[ $key ] ) ) {
				$schedule_options[ $key ] = $details['display'];
			}
		}

		// The main fields array, grouped by section.
		return [
			'api_config' => [
				'api_key' => [
					'label' => __( 'OpenAI API Key', 'autopen-ai-writer' ),
					'type'  => 'api-key',
				],
				'gpt_model' => [
					'label'       => __( 'GPT Model', 'autopen-ai-writer' ),
					'type'        => 'select',
					'options'     => [
						'gpt-4o'       => 'GPT-4o (Fast, versatile, recommended)',
						'gpt-4-turbo'  => 'GPT-4 Turbo (Stable, long context)',
						'gpt-3.5-turbo' => 'GPT-3.5 Turbo (Affordable, daily blogs)',
					],
					'description' => __( 'Select the language model for text generation.', 'autopen-ai-writer' ),
				],
				'image_model' => [
					'label'       => __( 'Image Model', 'autopen-ai-writer' ),
					'type'        => 'select',
					'options'     => [
						'dall-e-3' => 'DALL-E 3 (Recommended)',
						'dall-e-2' => 'DALL-E 2',
					],
					'description' => __( 'Select the model for image generation.', 'autopen-ai-writer' ),
				],
			],
			'content_settings' => [
				'topics_queue' => [
					'label'       => __( 'Topics Queue', 'autopen-ai-writer' ),
					'type'        => 'textarea',
					'description' => __( 'One topic per line. The generator will process the top one first.', 'autopen-ai-writer' ),
				],
				'internal_links_pool' => [
					'label'       => __( 'Internal Links Pool', 'autopen-ai-writer' ),
					'type'        => 'textarea',
					'description' => __( 'One full URL per line. The AI will use these to create internal links.', 'autopen-ai-writer' ),
				],
				'publish_status' => [
					'label'       => __( 'Default Post Status', 'autopen-ai-writer' ),
					'type'        => 'select',
					'options'     => [
						'draft'   => 'Draft',
						'publish' => 'Publish',
					],
					'description' => __( 'Choose to publish immediately or save as a draft for review.', 'autopen-ai-writer' ),
				],
				'default_author' => [
					'label'       => __( 'Default Author', 'autopen-ai-writer' ),
					'type'        => 'select',
					'options'     => $user_options,
					'description' => __( 'The user to assign generated posts to.', 'autopen-ai-writer' ),
				],
				'images_per_post' => [
					'label'       => __( 'Inline Images Per Post', 'autopen-ai-writer' ),
					'type'        => 'select',
					'options'     => [
						0 => '0',
						1 => '1',
						2 => '2',
						3 => '3',
						4 => '4',
						5 => '5',
					],
					'description' => __( 'Number of images to generate for the post body.', 'autopen-ai-writer' ),
				],
				'featured_image_size' => [
					'label'   => __( 'Featured Image Size', 'autopen-ai-writer' ),
					'type'    => 'select',
					'options' => [
						'1792x1024' => 'Landscape (1792x1024)',
						'1024x1024' => 'Square (1024x1024)',
					],
				],
				'featured_image_quality' => [
					'label'   => __( 'Featured Image Quality', 'autopen-ai-writer' ),
					'type'    => 'select',
					'options' => [
						'hd'       => 'HD',
						'standard' => 'Standard',
					],
				],
				'min_word_count' => [
					'label'       => __( 'Target Word Count', 'autopen-ai-writer' ),
					'type'        => 'number',
					'description' => __( 'The AI will target this word count. The post will still be created if it falls slightly short.', 'autopen-ai-writer' ),
				],
				'seo_plugin' => [
					'label'       => __( 'SEO Plugin Integration', 'autopen-ai-writer' ),
					'type'        => 'select',
					'options'     => [
						'none'     => 'None',
						'rankmath' => 'Rank Math',
						'seopress' => 'SEOPress',
						'yoast'    => 'Yoast SEO',
					],
					'description' => __( 'Automatically populate metadata for the selected plugin.', 'autopen-ai-writer' ),
				],
				'enable_categories' => [
					'label' => __( 'Auto-Assign Categories', 'autopen-ai-writer' ),
					'type'  => 'checkbox',
				],
				'enable_tags' => [
					'label' => __( 'Auto-Assign Tags', 'autopen-ai-writer' ),
					'type'  => 'checkbox',
				],
			],
			'automation' => [
				'frequency' => [
					'label'       => __( 'Generation Frequency', 'autopen-ai-writer' ),
					'type'        => 'select',
					'options'     => $schedule_options,
					'description' => __( 'How often the plugin should attempt to generate a new post.', 'autopen-ai-writer' ),
				],
			],
			'prompts' => [
				'system_prompt' => [
					'label'       => __( 'System Prompt', 'autopen-ai-writer' ),
					'type'        => 'textarea',
					'description' => __( 'Core instructions for the AI persona. Affects all calls.', 'autopen-ai-writer' ),
				],
				'content_prompt' => [
					'label'       => __( 'Content Prompt', 'autopen-ai-writer' ),
					'type'        => 'textarea',
					'description' => __( 'Prompt for generating the article title and HTML body.', 'autopen-ai-writer' ),
				],
				'metadata_prompt' => [
					'label'       => __( 'Metadata Prompt', 'autopen-ai-writer' ),
					'type'        => 'textarea',
					'description' => __( 'Prompt for generating SEO data, image details, and links based on the article content.', 'autopen-ai-writer' ),
				],
			],
		];
	}

	/**
	 * Renders the HTML for a group of settings fields.
	 *
	 * @since 1.0.0
	 * @param string $group_key The key for the group of fields to render.
	 * @param string $title     The title for the settings section.
	 */
	public static function render_settings_fields( string $group_key, string $title ): void {
		$all_fields = self::get_settings_fields();
		if ( ! isset( $all_fields[ $group_key ] ) ) {
			return;
		}
		$fields      = $all_fields[ $group_key ];
		$options     = self::get_options();
		echo '<h2>' . esc_html( $title ) . '</h2>';
		echo '<table class="form-table">';
		foreach ( $fields as $key => $field ) {
			$option_name = AUTOPEN_OPTIONS_KEY . "[$key]";
			$value       = $options[ $key ] ?? '';
			echo '<tr><th scope="row"><label for="autopen_options_' . esc_attr( $key ) . '">' . esc_html( $field['label'] ) . '</label></th><td>';
			// Render the correct HTML input based on the field type.
			switch ( $field['type'] ) {
				case 'api-key':
					echo '<div class="autopen-api-key-wrapper"><input type="password" id="autopen_options_api_key" name="' . esc_attr( $option_name ) . '" value="' . esc_attr( $value ) . '" class="regular-text" /><button type="button" id="autopen-toggle-key-visibility" class="button button-secondary"><span class="dashicons dashicons-visibility"></span></button><button type="button" id="autopen-validate-key-btn" class="button button-secondary"><span class="dashicons dashicons-admin-network"></span> ' . esc_html__( 'Validate API Key', 'autopen-ai-writer' ) . '</button></div>';
					break;
				case 'textarea':
					echo '<textarea id="autopen_options_' . esc_attr( $key ) . '" name="' . esc_attr( $option_name ) . '" class="large-text code">' . esc_textarea( $value ) . '</textarea>';
					if ( 'topics_queue' === $key ) {
						/* translators: %s: a placeholder for the topic count number */
						echo '<p class="autopen-field-meta">' . sprintf( esc_html__( 'Topics in queue: %s', 'autopen-ai-writer' ), '<strong id="autopen-topic-count">0</strong>' ) . '</p>';
					}
					break;
				case 'select':
					echo '<select id="autopen_options_' . esc_attr( $key ) . '" name="' . esc_attr( $option_name ) . '">';
					foreach ( $field['options'] as $opt_val => $opt_label ) {
						echo '<option value="' . esc_attr( $opt_val ) . '" ' . selected( $value, $opt_val, false ) . '>' . esc_html( $opt_label ) . '</option>';
					}
					echo '</select>';
					break;
				case 'checkbox':
					echo '<input type="checkbox" id="autopen_options_' . esc_attr( $key ) . '" name="' . esc_attr( $option_name ) . '" value="1" ' . checked( 1, $value, false ) . ' />';
					break;
				case 'number':
					echo '<input type="number" id="autopen_options_' . esc_attr( $key ) . '" name="' . esc_attr( $option_name ) . '" value="' . esc_attr( $value ) . '" class="small-text" />';
					break;
			}
			// Display the field description if it exists.
			if ( ! empty( $field['description'] ) ) {
				echo '<p class="description">' . wp_kses_post( $field['description'] ) . '</p>';
			}
			echo '</td></tr>';
		}
		echo '</table>';
	}

	/**
	 * Gathers data for the system status widget.
	 *
	 * @since 1.0.0
	 * @return array An array of status data for use in the admin UI.
	 */
	public static function get_system_status(): array {
		$options = self::get_options();
		$next_run_timestamp = wp_next_scheduled( AUTOPEN_CRON_HOOK );
		$next_run_formatted = $next_run_timestamp ? get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $next_run_timestamp ), 'M j, Y g:i A' ) : '';
		
		// Determine the overall system status class and text.
		$status = [];
		if ( empty( $options['api_key'] ) ) {
			$status['status_class'] = 'error';
			$status['status_text'] = __( 'Error: API Key Missing', 'autopen-ai-writer' );
		} elseif ( empty( $options['cron_enabled'] ) ) {
			$status['status_class'] = 'paused';
			$status['status_text'] = __( 'Automation Disabled', 'autopen-ai-writer' );
		} elseif ( ! $next_run_timestamp ) {
			$status['status_class'] = 'error';
			$status['status_text'] = __( 'Error: Not Scheduled', 'autopen-ai-writer' );
		} else {
			$status['status_class'] = 'active';
			$status['status_text'] = __( 'Automation Active', 'autopen-ai-writer' );
		}
		
		// Check if the WP Cron system itself appears to be running.
		$last_cron_check = get_transient( 'doing_cron' );
		$cron_ok = ( $last_cron_check && $last_cron_check + ( 2 * HOUR_IN_SECONDS ) > time() ) || $next_run_timestamp;
		
		return array_merge(
			$status,
			[
				'topics_queued'      => count( self::get_lines_from_textarea( $options['topics_queue'] ) ),
				'posts_generated'    => (int) $options['posts_generated'],
				'next_run'           => $next_run_formatted,
				'api_key_configured' => ! empty( $options['api_key'] ),
				'cron_ok'            => (bool) $cron_ok,
			]
		);
	}

	/**
	 * Splits a textarea's content into an array of non-empty lines.
	 *
	 * @since 1.0.0
	 * @param  string $text The content from a textarea.
	 * @return array An array of trimmed lines.
	 */
	public static function get_lines_from_textarea( string $text ): array {
		return array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $text ) ) ) );
	}

	/**
	 * Gets a list of all category names.
	 *
	 * @since 1.0.0
	 * @return array An array of category names.
	 */
	public static function get_category_names(): array {
		$categories = get_categories(
			[
				'hide_empty' => false,
				'fields'     => 'names',
			]
		);
		return is_wp_error( $categories ) ? [] : $categories;
	}

	/**
	 * Replaces placeholders in a prompt string with dynamic context values.
	 *
	 * Placeholders are in the format `{{key}}`.
	 *
	 * @since 1.0.0
	 * @param string      $prompt  The prompt template.
	 * @param array       $context An associative array of context data.
	 * @param string|null $topic   The topic, if applicable.
	 * @return string The prepared prompt with placeholders replaced.
	 */
	public static function prepare_prompt( string $prompt, array $context, string $topic = null ): string {
		if ( $topic ) {
			$context['topic'] = $topic;
		}
		foreach ( $context as $key => $value ) {
			$prompt = str_replace( "{{{$key}}}", (string) $value, $prompt );
		}
		return $prompt;
	}

	/**
	 * Returns the default system prompt.
	 *
	 * @since 1.0.0
	 * @return string The default prompt text.
	 */
	public static function get_default_system_prompt(): string {
		return 'You are a world-class SEO expert and an engaging, professional blog writer. Your primary goal is to create comprehensive, authoritative, and valuable blog posts that are meticulously optimized for search engines. Adhere to all instructions precisely. Your entire output must be a single, valid JSON object.';
	}

	/**
	 * Returns the default content generation prompt.
	 *
	 * @since 1.0.0
	 * @return string The default prompt text.
	 */
	public static function get_default_content_prompt(): string {
		return '**Don\'t think too long, make it quick** Please write a high-quality, SEO-optimized, professional blog post on the topic: "{{topic}}", with more than **{{min_word_count}} words** of actual content.' . "\n\n" .
			'The blog post should:' . "\n" .
			'- Read like it was written by an experienced content writer, suitable for publishing.' . "\n" .
			'- Include long, well-structured paragraphs, with each H2 subsection being 300–500 words and smooth transitions between sections.' . "\n" .
			'- Use H2, H3, and H4 headings to organize the content naturally and professionally.' . "\n" .
			'- Incorporate examples, case studies, tables, storytelling, and data to make it engaging.' . "\n" .
			'{{in_post_image_instruction}}' .
			'Deliver ONLY a valid JSON object with this structure:' . "\n" .
			'```json' . "\n" .
			'{' . "\n" .
			'"title": "An SEO-optimized, compelling blog title (60–70 characters).",' . "\n" .
			'"content_html": "The full post content in valid HTML. It MUST be at least {{min_word_count}} words and include all placeholders. DO NOT include an H1 tag."' . "\n" .
			'}' . "\n" .
			'```';
	}


	/**
	 * Returns the default metadata generation prompt.
	 *
	 * @since 1.0.0
	 * @return string The default prompt text.
	 */
	public static function get_default_metadata_prompt(): string {
		return 'Act as an SEO expert. Analyze the following blog post title and content. Your task is to generate all the necessary SEO metadata, image details, and links for it.' . "\n\n" .
			'**Article Title:** {{topic}}' . "\n" .
			'**Article Content Snippet:** {{generated_content}}' . "\n" .
			'**Image Hints Extracted from Content:**' . "\n" .
			'{{image_hints}}' . "\n\n" .
			'**Instructions:**' . "\n\n" .
			'1.  Generate all standard SEO metadata.' . "\n" .
			'2.  Generate a category. It can be a single category like "Marketing" or a subcategory like "WordPress > Plugins".' . "\n" .
			'3.  Based on the **Image Hints** provided above, generate a complete metadata block for a featured image AND for **all** `{{images_per_post}}` inline images. Each image must have a unique, descriptive `file_name`. The `in_post` array must contain exactly `{{images_per_post}}` items.' . "\n" .
			'4.  Generate an array of 3-5 relevant internal and external links to be inserted into the article. Provide the exact anchor text from the article where the link should be placed. Choose only real, authoritative external URLs.' . "\n\n" .
			'**Return ONLY a valid JSON object with the following structure:**' . "\n\n" .
			'```json' . "\n" .
			'{' . "\n" .
			'"meta_title": "A keyword-rich meta title for SERPs, 55–65 characters.",' . "\n" .
			'"meta_description": "A persuasive meta description, 150–160 characters, that drives clicks.",' . "\n" .
			'"slug": "short-seo-slug-3-5-words",' . "\n" .
			'"focus_keyword": "The primary keyword for the article.",' . "\n" .
			'"supporting_keywords": ["secondary keyword 1", "long-tail keyword 2"],' . "\n" .
			'"category": "The single best matching category or subcategory (e.g., \'Parent > Child\').",' . "\n" .
			'"tags": ["3–6 relevant", "lowercase", "seo tags"],' . "\n" .
			'"images": {' . "\n" .
			'    "featured": {' . "\n" .
			'    "prompt": "Highly detailed DALL-E prompt for a photorealistic featured image. Describe subject, setting, style, lighting, composition. Avoid text.",' . "\n" .
			'    "file_name": "seo-friendly-featured-image.jpg",' . "\n" .
			'    "title": "Readable title for the featured image.",' . "\n" .
			'    "alt_text": "SEO-optimized alt text including the focus keyword.",' . "\n" .
			'    "caption": "Engaging caption for the featured image.",' . "\n" .
			'    "description": "1–2 sentence SEO-friendly description of the featured image for the media library."' . "\n" .
			'    },' . "\n" .
			'    "in_post": [' . "\n" .
			'    {{in_post_json_example}}' . "\n" .
			'    ]' . "\n" .
			'},' . "\n" .
			'"links": [' . "\n" .
			'    {' . "\n" .
			'    "anchor": "exact anchor text found in the content",' . "\n" .
			'    "url": "[https://www.authoritative-external-site.com/resource](https://www.authoritative-external-site.com/resource)",' . "\n" .
			'    "type": "external"' . "\n" .
			'    },' . "\n" .
			'    {' . "\n" .
			'    "anchor": "another exact anchor text from the content",' . "\n" .
			'    "url": "an internal URL from {{internal_links_list}}",' . "\n" .
			'    "type": "internal"' . "\n" .
			'    }' . "\n" .
			']' . "\n" .
			'}' . "\n" .
			'```';
	}


	/**
	 * Builds the context array for populating prompt placeholders.
	 *
	 * @since 1.0.0
	 * @param array  $options     The plugin options.
	 * @param string $title       The article title.
	 * @param string $image_hints The extracted image hints.
	 * @return array The complete context array.
	 */
	public static function build_prompt_context( array $options, string $title = '', string $image_hints = '' ): array {
		$context = [
			'site_name'             => get_bloginfo( 'name' ),
			'min_word_count'        => $options['min_word_count'],
			'images_per_post'       => $options['images_per_post'],
			'internal_links_list'   => $options['internal_links_pool'],
			'available_categories'  => implode( ', ', self::get_category_names() ),
			'topic'                 => $title,
			'image_hints'           => $image_hints,
		];

		// Conditionally create the instruction for in-post images.
		if ( ! empty( $options['images_per_post'] ) && (int) $options['images_per_post'] > 0 ) {
			$context['in_post_image_instruction'] = '- Include placeholders for **' . (int) $options['images_per_post'] . ' in-post images** where appropriate in the format: `[IN_POST_IMG_X hint: A clear and descriptive hint of what type of image should be generated for this section]` with X being the image number (1, 2, 3...).' . "\n";
		} else {
			$context['in_post_image_instruction'] = '';
		}

		// Determine the number of images to generate a JSON example for.
		$image_count = 0;
		if ( ! empty( $image_hints ) ) {
			$image_count = count( preg_split( '/\r\n|\r|\n/', $image_hints ) );
		} elseif ( ! empty( $options['images_per_post'] ) ) {
			$image_count = (int) $options['images_per_post'];
		}
		
		// Dynamically build the JSON example for in-post images for the prompt.
		$in_post_example = [];
		for ( $i = 1; $i <= $image_count; $i++ ) {
			$in_post_example[] =
				'{' . "\n" .
				'    "prompt": "Detailed DALL-E prompt for inline image ' . $i . ', based on its specific hint. Describe style, mood, details clearly.",' . "\n" .
				'    "file_name": "descriptive-inline-image-filename-' . $i . '.jpg",' . "\n" .
				'    "title": "Inline Image ' . $i . ' Title",' . "\n" .
				'    "alt_text": "SEO-friendly alt text for inline image ' . $i . '.",' . "\n" .
				'    "caption": "Concise caption for inline image ' . $i . '.",' . "\n" .
				'    "description": "SEO-friendly media library description for inline image ' . $i . '."' . "\n" .
				'}';
		}
		$context['in_post_json_example'] = implode( ",\n", $in_post_example );
		return $context;
	}
}