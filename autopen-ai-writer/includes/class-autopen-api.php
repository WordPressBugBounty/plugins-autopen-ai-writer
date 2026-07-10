<?php
/**
 * Handles all communication with the OpenAI API.
 *
 * This class provides static methods to validate API keys, generate article
 * content, generate metadata, and create images. It abstracts the complexities
 * of making HTTP requests to the OpenAI endpoints.
 *
 * @package AutoPen
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class AutoPen_API {

	/**
	 * The base URL for the OpenAI API v1.
	 *
	 * @since 1.0.0
	 */
	private const API_BASE = 'https://api.openai.com/v1/';

	/**
	 * Validates an OpenAI API key by making a simple request to the models endpoint.
	 *
	 * @since 1.0.0
	 * @param  string $api_key The API key to validate.
	 * @return array An array with 'success' (bool) and 'message' (string).
	 */
	public static function validate_key( string $api_key ): array {
		$response = self::request( 'models', $api_key, [], 'GET' );
		if ( is_wp_error( $response ) ) {
			return [
				'success' => false,
				'message' => $response->get_error_message(),
			];
		}
		
		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 === $code ) {
			return [
				'success' => true,
				'message' => __( 'API Key is valid and connection is successful!', 'autopen-ai-writer' ),
			];
		}

		$body          = json_decode( wp_remote_retrieve_body( $response ), true );
		$error_message = $body['error']['message'] ?? __( 'Unknown API error.', 'autopen-ai-writer' );
		return [
			'success' => false,
			/* translators: 1: HTTP status code, 2: Error message from API */
			'message' => sprintf(
				/* translators: 1: HTTP status code, 2: Error message from API */
				__( 'API Key validation failed (Code: %1$d): %2$s', 'autopen-ai-writer' ),
				$code,
				$error_message
			),
		];
	}

	/**
	 * Generates the main article content (title and HTML body).
	 *
	 * @since 1.0.0
	 * @param  string $topic The topic for the article.
	 * @param  array  $context Dynamic data to populate the prompt.
	 * @return array The API response, containing either 'data' or 'error'.
	 */
	public static function generate_article_content( string $topic, array $context ): array {
		$options = AutoPen_Utils::get_options();
		$model   = $options['gpt_model'];
		$payload = [
			'model'    => $model,
			'messages' => [
				[
					'role'    => 'system',
					'content' => AutoPen_Utils::prepare_prompt( $options['system_prompt'], $context ),
				],
				[
					'role'    => 'user',
					'content' => AutoPen_Utils::prepare_prompt( AutoPen_Utils::get_default_content_prompt(), $context, $topic ),
				],
			],
		];

		// Adjust payload for newer models which use different parameter names.
		if ( strpos( $model, 'gpt-5' ) !== false ) {
			$payload['max_tokens']  = 4096;
			$payload['temperature'] = 1.0; // GPT-5 requires temperature = 1
		} elseif ( strpos( $model, 'gpt-4' ) !== false ) {
			$payload['max_tokens']  = 4096;
			$payload['temperature'] = 0.7;
		} else {
			$payload['max_tokens']  = 4096;
			$payload['temperature'] = 0.7;
		}

		return self::process_chat_completion( $payload );
	}

	/**
	 * Generates SEO metadata, image details, and links based on generated content.
	 *
	 * @since 1.0.0
	 * @param  string $generated_content The HTML content of the article.
	 * @param  array  $context Dynamic data to populate the prompt.
	 * @return array The API response, containing either 'data' or 'error'.
	 */
	public static function generate_article_metadata( string $generated_content, array $context ): array {
		$options = AutoPen_Utils::get_options();
		$model   = $options['gpt_model'];
		// Provide a snippet of the content to the AI to save tokens.
		$context['generated_content'] = mb_substr( wp_strip_all_tags( $generated_content ), 0, 8000 );

		$payload = [
			'model'           => $model,
			'messages'        => [
				[
					'role'    => 'system',
					'content' => AutoPen_Utils::prepare_prompt( $options['system_prompt'], $context ),
				],
				[
					'role'    => 'user',
					'content' => AutoPen_Utils::prepare_prompt( AutoPen_Utils::get_default_metadata_prompt(), $context ),
				],
			],
			// Force the model to return a JSON object.
			'response_format' => [ 'type' => 'json_object' ],
		];

		if ( strpos( $model, 'gpt-4' ) !== false || strpos( $model, 'gpt-5' ) !== false ) {
			$payload['max_tokens']  = 4096;
			$payload['temperature'] = 0.7;
		} else {
			$payload['max_tokens']  = 4096;
			$payload['temperature'] = 0.7;
		}
		return self::process_chat_completion( $payload );
	}

	/**
	 * Generates an image using DALL-E.
	 *
	 * @since 1.0.0
	 * @param  string $prompt The prompt for the image.
	 * @param  bool   $is_featured Whether this is a featured image (affects size/quality).
	 * @return array The API response, containing either a 'url' or 'error'.
	 */
	public static function generate_image( string $prompt, bool $is_featured ): array {
		$options = AutoPen_Utils::get_options();
		$payload = [
			'model'   => $options['image_model'],
			'prompt'  => self::enhance_image_prompt( $prompt ),
			'n'       => 1,
			'size'    => $is_featured ? $options['featured_image_size'] : '1024x1024',
			'quality' => ( 'dall-e-3' === $options['image_model'] ) ? ( $is_featured ? $options['featured_image_quality'] : 'standard' ) : null,
			'style'   => 'vivid',
		];
		// DALL-E 2 does not support 'quality' or 'style' parameters.
		if ( 'dall-e-2' === $options['image_model'] ) {
			unset( $payload['quality'], $payload['style'] );
		}
		$response = self::request( 'images/generations', $options['api_key'], $payload );
		if ( is_wp_error( $response ) ) {
			return [
				'success' => false,
				'error'   => $response->get_error_message(),
			];
		}
		
		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		
		if ( 200 === $code ) {
			$url = $body['data'][0]['url'] ?? null;
			if ( $url ) {
				return [
					'success' => true,
					'url'     => $url,
				];
			}
			return [
				'success' => false,
				'error'   => __( 'API returned success, but no image URL was found.', 'autopen-ai-writer' ),
			];
		}
		$error_message = $body['error']['message'] ?? __( 'Unknown image generation error.', 'autopen-ai-writer' );
		return [
			'success' => false,
			'error'   => sprintf(
				/* translators: 1: HTTP status code, 2: Error message from API */
				__( 'Image API Error (%1$d): %2$s', 'autopen-ai-writer' ),
				$code,
				$error_message
			),
		];
	}

	/**
	 * Processes the response from a chat completion request.
	 *
	 * @since 1.0.0
	 * @param  array $payload The payload sent to the API.
	 * @return array The processed response with parsed JSON data or an error.
	 */
	private static function process_chat_completion( array $payload ): array {
		$options  = AutoPen_Utils::get_options();
		$response = self::request( 'chat/completions', $options['api_key'], $payload );
		if ( is_wp_error( $response ) ) {
			return [
				'success' => false,
				'error'   => $response->get_error_message(),
			];
		}
		
		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		
		if ( 200 === $code ) {
			$content_string = $body['choices'][0]['message']['content'] ?? '';
			if ( empty( $content_string ) ) {
				if ( ( $body['choices'][0]['finish_reason'] ?? '' ) === 'length' ) {
					return [
						'success' => false,
						'error'   => __( 'The AI model ran out of tokens before completing the response. Try a model with a larger context window or a shorter prompt.', 'autopen-ai-writer' ),
					];
				}
				return [
					'success' => false,
					'error'   => __( 'AI returned an empty response.', 'autopen-ai-writer' ),
				];
			}

			// Find the first '{' and the last '}' to extract the core JSON object.
			$json_start = strpos( $content_string, '{' );
			$json_end   = strrpos( $content_string, '}' );

			if ( false !== $json_start && false !== $json_end && $json_end > $json_start ) {
				$json_string = substr( $content_string, $json_start, $json_end - $json_start + 1 );
				$json_data   = json_decode( $json_string, true );

				if ( JSON_ERROR_NONE === json_last_error() && is_array( $json_data ) ) {
					return [
						'success' => true,
						'data'    => $json_data,
					];
				}
			}

			// Fallback for safety.
			$cleaned_string = preg_replace( '/^```json\s*|\s*```$/', '', trim( $content_string ) );
			$cleaned_string = preg_replace( '/[[:cntrl:]]/', '', $cleaned_string );
			$json_data      = json_decode( $cleaned_string, true );
			
			if ( JSON_ERROR_NONE === json_last_error() && is_array( $json_data ) ) {
				return [
					'success' => true,
					'data'    => $json_data,
				];
			} else {
				$error = sprintf(
					/* translators: %s: JSON error message from json_last_error_msg(). */
					__( 'Failed to parse JSON response from AI. Error: %s', 'autopen-ai-writer' ),
					json_last_error_msg()
				);
				AutoPen_Logger::error(
					$error,
					[ 'raw_response' => substr( $content_string, 0, 500 ) ]
				);
				return [
					'success' => false,
					'error'   => $error,
				];
			}
		}
		$error_message = $body['error']['message'] ?? __( 'Unknown API error.', 'autopen-ai-writer' );
		$log_message   = 'Generation API Error';
		AutoPen_Logger::error( "$log_message ($code): $error_message" );
		return [
			'success' => false,
			'error'   => sprintf( '%s (%d): %s', $log_message, $code, $error_message ),
		];
	}

	/**
	 * A robust wrapper for wp_remote_request with built-in retries for server-side errors.
	 *
	 * @since 1.0.0
	 * @param  string $endpoint The API endpoint (e.g., 'chat/completions').
	 * @param  string $api_key  The OpenAI API key.
	 * @param  array  $payload  The data to send in the request body.
	 * @param  string $method   The HTTP method ('POST' or 'GET').
	 * @return array|WP_Error The response array or a WP_Error object on failure.
	 */
	private static function request( string $endpoint, string $api_key, array $payload = [], string $method = 'POST' ) {
		$url  = self::API_BASE . $endpoint;
		$args = [
			'method'  => $method,
			'headers' => [
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
				'User-Agent'    => 'autopen/' . AUTOPEN_VERSION,
			],
			'timeout' => 300,
		];
		if ( 'POST' === $method ) {
			$args['body'] = wp_json_encode( $payload );
		}
		
		$max_retries = 2;
		for ( $i = 0; $i <= $max_retries; $i++ ) {
			$response = wp_remote_request( $url, $args );
			if ( ! is_wp_error( $response ) ) {
				$code = wp_remote_retrieve_response_code( $response );
				if ( $code < 500 && 429 !== $code ) {
					return $response;
				}
			}
			if ( $i < $max_retries ) {
				sleep( 2 * ( $i + 1 ) );
			}
		}
		return is_wp_error( $response ) ? $response : new WP_Error( 'api_error', __( 'API request failed after multiple retries.', 'autopen-ai-writer' ) );
	}
	
	/**
	 * Appends generic enhancing keywords to an image prompt.
	 *
	 * @since 1.0.0
	 * @param  string $prompt The original user-provided prompt.
	 * @return string The enhanced prompt.
	 */
	private static function enhance_image_prompt( string $prompt ): string {
		$enhancers       = 'professional photography, high detail, cinematic lighting, 8k, sharp focus, no text';
		$enhanced_prompt = $prompt . ', ' . $enhancers;
		return substr( $enhanced_prompt, 0, 4000 );
	}
}