<?php

namespace Torskint\LinguaFlowWp\Tests\Support;

use Torskint\LinguaFlowWp\Exceptions\HubException;
use Torskint\LinguaFlowWp\Http\HubClient;
use ZipArchive;

/**
 * Faux hub (tests) : garde les archives reçues, répond avec le statut voulu et fabrique un result.json au format du protocole.
 */
class FakeHub implements HubClient
{
	/** Archives reçues : clé d'idempotence => chemin copié. @var array<string, string> */
	public array $received = [];

	/** Statut renvoyé par status(). */
	public string $status = 'running';

	/** Traductions rendues : langue => chemin d'archive => clé => texte. */
	public array $translations = [];

	/** Si vrai, create() échoue comme un hub injoignable. */
	public bool $down = false;

	/** Nombre d'appels à ack(). */
	public int $acks = 0;

	/**
	 * {@inheritDoc}
	 */
	public function create(string $zipPath, string $idempotencyKey): array
	{
		if ($this->down) {
			throw new HubException('Hub injoignable.');
		}

		$copy = tempnam(sys_get_temp_dir(), 'lfwfake');
		copy($zipPath, $copy);
		$existing = isset($this->received[$idempotencyKey]);
		$this->received[$idempotencyKey] = $copy;

		return ['job' => ['reference' => 'ref'.substr(md5($idempotencyKey), 0, 8), 'status' => 'running'], 'created' => ! $existing];
	}

	/**
	 * {@inheritDoc}
	 */
	public function status(string $reference): array
	{
		return ['status' => $this->status];
	}

	/**
	 * {@inheritDoc}
	 */
	public function result(string $reference): ?array
	{
		$path = tempnam(sys_get_temp_dir(), 'lfwres');
		$languages = [];

		foreach ($this->translations as $locale => $files) {
			$languages[$locale] = ['files' => $files];
		}

		$zip = new ZipArchive;
		$zip->open($path, ZipArchive::OVERWRITE);
		$zip->addFromString('result.json', json_encode(['protocol' => 1, 'languages' => $languages]));
		$zip->close();

		return ['path' => $path, 'missing' => []];
	}

	/**
	 * {@inheritDoc}
	 */
	public function ack(string $reference): void
	{
		$this->acks++;
	}

	/**
	 * {@inheritDoc}
	 */
	public function cancel(string $reference): array
	{
		return ['status' => 'cancelled'];
	}

	/**
	 * {@inheritDoc}
	 */
	public function health(): array
	{
		return ['status' => 'ok'];
	}
}
