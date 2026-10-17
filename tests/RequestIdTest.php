<?php

namespace Torskint\LinguaFlowWp\Tests;

use PHPUnit\Framework\TestCase;
use Torskint\LinguaFlowWp\Exceptions\HubException;
use Torskint\LinguaFlowWp\Http\WpHubClient;

require_once __DIR__.'/Support/wp-stubs.php';

/**
 * Identifiant de requête (X-Request-Id) : il suit l'erreur du hub jusqu'au message affiché, sans jamais accepter une valeur douteuse.
 */
class RequestIdTest extends TestCase
{
	/** Identifiant donné par le hub dans les essais. */
	private const ID = '0b9c5a3e-6f1d-4c7e-9a52-3d8f1e2b7a64';

	/**
	 * Prépare la réponse simulée du hub : code, corps JSON d'erreur et en-têtes.
	 */
	private function reply(int $code, array $body = [], array $headers = []): void
	{
		$GLOBALS['lfw_reply'] = ['code' => $code, 'headers' => $headers, 'body' => json_encode($body)];
	}

	/**
	 * Corps d'erreur du hub.
	 */
	private function error(string $code, string $message, ?string $requestId = null): array
	{
		return ['error' => array_filter(['code' => $code, 'message' => $message, 'request_id' => $requestId])];
	}

	/**
	 * Client sur l'adresse de test.
	 */
	private function client(): WpHubClient
	{
		return new WpHubClient('https://hub.example.com', 'jeton');
	}

	/**
	 * L'identifiant de l'en-tête termine le message et reste disponible sur l'exception.
	 */
	public function test_request_id_from_header_is_in_the_message(): void
	{
		$this->reply(401, $this->error('unauthenticated', 'Jeton absent ou refusé.'), ['X-Request-Id' => self::ID]);

		try {
			$this->client()->health();
			$this->fail('Une réponse 401 doit lever une exception.');
		} catch (HubException $e) {
			$this->assertSame(401, $e->status);
			$this->assertSame('unauthenticated', $e->errorCode);
			$this->assertSame(self::ID, $e->requestId);
			$this->assertSame('Jeton absent ou refusé. [X-Request-Id : '.self::ID.']', $e->getMessage());
		}
	}

	/**
	 * Sans en-tête, l'identifiant du corps JSON est utilisé.
	 */
	public function test_request_id_falls_back_to_the_body(): void
	{
		$this->reply(500, $this->error('server_error', 'Erreur interne.', self::ID));

		try {
			$this->client()->status('ref12345');
			$this->fail();
		} catch (HubException $e) {
			$this->assertSame(self::ID, $e->requestId);
			$this->assertStringEndsWith('[X-Request-Id : '.self::ID.']', $e->getMessage());
		}
	}

	/**
	 * Le téléchargement du résultat porte aussi l'identifiant ; « pas prêt » (409 run_not_ready) reste un simple null.
	 */
	public function test_result_errors_carry_the_id_and_not_ready_is_null(): void
	{
		$this->reply(409, $this->error('run_not_ready', 'Pas prêt.'), ['X-Request-Id' => self::ID]);
		$this->assertNull($this->client()->result('ref12345'));

		$this->reply(403, $this->error('forbidden', 'Droit manquant.'), ['X-Request-Id' => self::ID]);

		try {
			$this->client()->result('ref12345');
			$this->fail();
		} catch (HubException $e) {
			$this->assertSame(self::ID, $e->requestId);
			$this->assertSame(403, $e->status);
		}
	}

	/**
	 * Un identifiant douteux (espaces, balises, trop court) est ignoré : le message reste celui du hub.
	 */
	public function test_doubtful_ids_are_ignored(): void
	{
		foreach (['<script>alert(1)</script>', 'court', 'avec des espaces et plus de huit', str_repeat('a', 100)] as $bad) {
			$this->reply(404, $this->error('not_found', 'Introuvable.'), ['X-Request-Id' => $bad]);

			try {
				$this->client()->status('ref12345');
				$this->fail();
			} catch (HubException $e) {
				$this->assertNull($e->requestId);
				$this->assertSame('Introuvable.', $e->getMessage());
			}
		}
	}

	/**
	 * Une panne réseau n'a pas d'identifiant ; une réponse réussie ne lève rien.
	 */
	public function test_network_failure_has_no_id_and_success_passes(): void
	{
		$GLOBALS['lfw_reply'] = 'connexion refusée';

		try {
			$this->client()->health();
			$this->fail();
		} catch (HubException $e) {
			$this->assertNull($e->requestId);
			$this->assertStringContainsString('connexion refusée', $e->getMessage());
		}

		$this->reply(200, ['data' => ['status' => 'ok']], ['X-Request-Id' => self::ID]);
		$this->assertSame(['data' => ['status' => 'ok']], $this->client()->health());
	}

	/**
	 * Un message vide du hub est remplacé par un texte par défaut, avec l'identifiant.
	 */
	public function test_empty_hub_message_gets_a_default_text(): void
	{
		$e = HubException::fromHub(502, null, '', self::ID);

		$this->assertSame('Réponse inattendue du hub (502). [X-Request-Id : '.self::ID.']', $e->getMessage());
		$this->assertSame('Erreur.', HubException::fromHub(500, null, 'Erreur.')->getMessage());
	}
}
