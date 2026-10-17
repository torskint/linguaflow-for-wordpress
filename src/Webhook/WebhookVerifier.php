<?php

namespace Torskint\LinguaFlowWp\Webhook;

/**
 * Contrôle d'un webhook du hub : signature HMAC-SHA256 du corps, date récente, référence présente. Le corps ne porte que la
 * référence : le résultat est téléchargé ensuite comme en mode local.
 */
class WebhookVerifier
{
	// Constructeur : secret du webhook (whsec_…) et tolérance de date (secondes).
	public function __construct(private string $secret, private int $tolerance = 300) {}

	/**
	 * Valide la requête. Renvoie la référence du travail, ou un code d'erreur HTTP avec son motif.
	 *
	 * @return array{ok: bool, status: int, error: ?string, reference: ?string}
	 */
	public function check(string $body, string $signature, ?int $now = null): array
	{
		if ($this->secret === '') {
			return $this->fail(503, 'webhook_not_configured');
		}

		if (! hash_equals('sha256='.hash_hmac('sha256', $body, $this->secret), $signature)) {
			return $this->fail(401, 'invalid_signature');
		}

		$payload = json_decode($body, true);
		$sent = strtotime((string) (is_array($payload) ? ($payload['created_at'] ?? '') : '')) ?: 0;

		if (abs(($now ?? time()) - $sent) > $this->tolerance) {
			return $this->fail(401, 'stale_request');
		}

		$reference = (string) ($payload['data']['reference'] ?? '');

		if ($reference === '') {
			return $this->fail(422, 'missing_reference');
		}

		return ['ok' => true, 'status' => 202, 'error' => null, 'reference' => $reference];
	}

	/**
	 * Réponse d'échec.
	 *
	 * @return array{ok: bool, status: int, error: ?string, reference: ?string}
	 */
	private function fail(int $status, string $error): array
	{
		return ['ok' => false, 'status' => $status, 'error' => $error, 'reference' => null];
	}
}
