<?php

// Fonctions HTTP de WordPress simulées pour les tests (WpHubClient) : la réponse vient de $GLOBALS['lfw_reply'].
// Un tableau ['code' => int, 'headers' => array, 'body' => string] donne une réponse ; une chaîne simule une panne réseau.

if (! class_exists('WP_Error')) {
	class WP_Error
	{
		public function __construct(private string $code = '', private string $message = '') {}

		public function get_error_message(): string
		{
			return $this->message;
		}
	}
}

if (! function_exists('wp_remote_request')) {
	function wp_remote_request(string $url, array $args = [])
	{
		$reply = $GLOBALS['lfw_reply'];
		$GLOBALS['lfw_last_request'] = ['url' => $url, 'args' => $args];

		if (is_string($reply)) {
			return new WP_Error('http_request_failed', $reply);
		}

		if (! empty($args['stream']) && ! empty($args['filename'])) {
			file_put_contents($args['filename'], $reply['body']);
		}

		return $reply;
	}

	function is_wp_error($thing): bool
	{
		return $thing instanceof WP_Error;
	}

	function wp_remote_retrieve_response_code(array $reply): int
	{
		return $reply['code'];
	}

	function wp_remote_retrieve_headers(array $reply): array
	{
		return $reply['headers'];
	}

	function wp_remote_retrieve_body(array $reply): string
	{
		return $reply['body'];
	}
}
