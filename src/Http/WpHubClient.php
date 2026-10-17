<?php

namespace Torskint\LinguaFlowWp\Http;

use Torskint\LinguaFlowWp\Archive\ArchiveBuilder;
use Torskint\LinguaFlowWp\Exceptions\HubException;

/**
 * Client HTTP du hub avec les fonctions HTTP de WordPress : délai, version du protocole, erreurs du hub en exceptions.
 * Les nouveaux essais sont gérés par la file de synchronisation (délai qui s'allonge), pas ici.
 */
class WpHubClient implements HubClient
{
	// Constructeur : adresse du hub, clé d'API et délai d'une requête (s).
	public function __construct(private string $baseUrl, private string $apiKey, private int $timeout = 60) {}

	/**
	 * {@inheritDoc}
	 */
	public function create(string $zipPath, string $idempotencyKey): array
	{
		$multipart = Multipart::file('archive', 'archive.zip', (string) file_get_contents($zipPath), 'application/zip');
		$response = $this->send('POST', 'jobs', ['body' => $multipart['body'], 'headers' => ['Content-Type' => $multipart['contentType'], 'Idempotency-Key' => $idempotencyKey]]);

		return ['job' => (array) ($response['json']['data'] ?? []), 'created' => $response['status'] === 201];
	}

	/**
	 * {@inheritDoc}
	 */
	public function status(string $reference): array
	{
		return (array) ($this->send('GET', 'jobs/'.rawurlencode($reference))['json']['data'] ?? []);
	}

	/**
	 * {@inheritDoc}
	 */
	public function result(string $reference): ?array
	{
		$path = tempnam(sys_get_temp_dir(), 'lfwres');
		$response = $this->send('GET', 'jobs/'.rawurlencode($reference).'/result', ['stream' => true, 'filename' => $path], false);

		if ($response['status'] === 409) {
			$error = json_decode((string) @file_get_contents($path), true);
			@unlink($path);

			if (($error['error']['code'] ?? null) === 'run_not_ready') {
				return null;
			}

			throw HubException::fromHub(409, $error['error']['code'] ?? null, (string) ($error['error']['message'] ?? ''), $this->requestId($response['headers'], $error));
		}

		if ($response['status'] < 200 || $response['status'] >= 300) {
			$error = json_decode((string) @file_get_contents($path), true);
			@unlink($path);

			throw HubException::fromHub($response['status'], $error['error']['code'] ?? null, (string) ($error['error']['message'] ?? ''), $this->requestId($response['headers'], $error));
		}

		return ['path' => $path, 'missing' => array_values(array_filter(explode(',', (string) ($response['headers']['x-missing-languages'] ?? ''))))];
	}

	/**
	 * {@inheritDoc}
	 */
	public function ack(string $reference): void
	{
		$this->send('POST', 'jobs/'.rawurlencode($reference).'/ack');
	}

	/**
	 * {@inheritDoc}
	 */
	public function cancel(string $reference): array
	{
		return (array) ($this->send('DELETE', 'jobs/'.rawurlencode($reference))['json']['data'] ?? []);
	}

	/**
	 * {@inheritDoc}
	 */
	public function health(): array
	{
		return (array) $this->send('GET', 'health')['json'];
	}

	/**
	 * Envoie une requête et renvoie statut, en-têtes et JSON décodé. Une réponse hors 2xx devient une HubException (sauf `$check` faux).
	 *
	 * @return array{status: int, headers: array, json: ?array}
	 */
	private function send(string $method, string $path, array $args = [], bool $check = true): array
	{
		$args = array_replace_recursive([
			'method' => $method,
			'timeout' => $this->timeout,
			'redirection' => 0,
			'headers' => [
				'Authorization' => 'Bearer '.$this->apiKey,
				'Accept' => 'application/json',
				'X-LinguaFlow-Protocol' => (string) ArchiveBuilder::PROTOCOL,
				'User-Agent' => 'LinguaflowForWordPress/1',
			],
		], $args);

		$reply = wp_remote_request(rtrim($this->baseUrl, '/').'/api/v1/'.ltrim($path, '/'), $args);

		if (is_wp_error($reply)) {
			throw new HubException('Hub injoignable : '.$reply->get_error_message());
		}

		$status = (int) wp_remote_retrieve_response_code($reply);
		$headers = [];

		foreach ((array) wp_remote_retrieve_headers($reply) as $name => $value) {
			$headers[strtolower((string) $name)] = is_array($value) ? implode(',', $value) : (string) $value;
		}

		$json = empty($args['stream']) ? json_decode((string) wp_remote_retrieve_body($reply), true) : null;

		if ($check && ($status < 200 || $status >= 300)) {
			throw HubException::fromHub($status, is_array($json) ? ($json['error']['code'] ?? null) : null, is_array($json) ? (string) ($json['error']['message'] ?? '') : '', $this->requestId($headers, $json));
		}

		return ['status' => $status, 'headers' => $headers, 'json' => is_array($json) ? $json : null];
	}

	/**
	 * Identifiant de la requête donné par le hub : l'en-tête X-Request-Id, ou à défaut `error.request_id` du corps.
	 *
	 * @param  array<string, string>  $headers  en-têtes en minuscules
	 */
	private function requestId(array $headers, mixed $json): ?string
	{
		$id = $headers['x-request-id'] ?? (is_array($json) ? ($json['error']['request_id'] ?? null) : null);

		return is_string($id) && $id !== '' ? $id : null;
	}
}
