<?php

namespace Torskint\LinguaFlowWp\Sync;

use Throwable;
use Torskint\LinguaFlowWp\Archive\ArchiveBuilder;
use Torskint\LinguaFlowWp\Delta\Delta;
use Torskint\LinguaFlowWp\Delta\DeltaDetector;
use Torskint\LinguaFlowWp\Exceptions\HubException;
use Torskint\LinguaFlowWp\Http\HubClient;
use Torskint\LinguaFlowWp\Store\JobStore;

/**
 * Détecte les clés manquantes, prépare l'archive, la remet au hub et enregistre la référence reçue.
 * Une archive qui n'a pas pu partir reste dans la boîte d'envoi : la synchronisation la renvoie avec la même clé d'idempotence.
 */
class Sender
{
	/**
	 * @param  array{client_ref: ?string, delivery: string, webhook_url: ?string, source: string, locales: list<string>, outbox: string, interval: int}  $config
	 */
	public function __construct(
		private DeltaDetector $detector,
		private ArchiveBuilder $builder,
		private HubClient $hub,
		private JobStore $store,
		private array $config,
	) {}

	/**
	 * Calcul seul : ce qui serait envoyé.
	 *
	 * @param  list<string>|null  $locales
	 */
	public function plan(?array $locales = null, bool $full = false): Delta
	{
		return $this->detector->detect($locales ?: $this->config['locales'], $full);
	}

	/**
	 * Envoie le delta. Renvoie la ligne du travail et une issue : « sent », « queued » (hub injoignable, renvoi plus tard),
	 * « duplicate » (même contenu déjà en cours) ou « nothing » (rien à traduire).
	 *
	 * @param  list<string>|null  $locales
	 * @return array{outcome: string, job: ?array, delta: Delta, error: ?string}
	 */
	public function send(?array $locales = null, bool $full = false): array
	{
		$delta = $this->plan($locales, $full);

		if ($delta->isEmpty()) {
			return ['outcome' => 'nothing', 'job' => null, 'delta' => $delta, 'error' => null];
		}

		$archive = $this->builder->build($delta, $this->config['source'], [
			'client_ref' => $this->config['client_ref'],
			'delivery' => $this->config['delivery'],
			'webhook_url' => $this->config['webhook_url'],
			'full' => $full,
		]);

		if ($existing = $this->store->activeByChecksum($archive['checksum'])) {
			@unlink($archive['path']);

			return ['outcome' => 'duplicate', 'job' => $existing, 'delta' => $delta, 'error' => null];
		}

		$outbox = rtrim($this->config['outbox'], '/\\');
		is_dir($outbox) || @mkdir($outbox, 0775, true);
		$kept = $outbox.DIRECTORY_SEPARATOR.$archive['checksum'].'.zip';
		rename($archive['path'], $kept);

		$id = $this->store->create($archive['checksum'], $delta->locales(), $delta->count(), $kept);
		$error = $this->push($id);

		return ['outcome' => $error === null ? 'sent' : 'queued', 'job' => $this->store->find($id), 'delta' => $delta, 'error' => $error];
	}

	/**
	 * Remet au hub l'archive d'une ligne « new ». Renvoie le message d'erreur, ou null si le hub l'a acceptée.
	 */
	public function push(int $id): ?string
	{
		$job = $this->store->find($id);

		if ($job === null || $job['status'] !== 'new' || ! is_file((string) $job['archive_path'])) {
			return $job === null ? 'Travail introuvable.' : 'Archive absente de la boîte d\'envoi.';
		}

		try {
			$reply = $this->hub->create($job['archive_path'], $job['uuid'])['job'];
		} catch (HubException $e) {
			// Refus définitif du hub (4xx) : inutile de renvoyer la même archive
			if ($e->status !== null && $e->status >= 400 && $e->status < 500) {
				$this->store->update($id, ['status' => 'failed', 'error' => $e->getMessage()]);
				@unlink($job['archive_path']);
			} else {
				$this->store->update($id, ['attempts' => $job['attempts'] + 1, 'error' => $e->getMessage(), 'next_poll_at' => time() + $this->config['interval']]);
			}

			return $e->getMessage();
		} catch (Throwable $e) {
			$this->store->update($id, ['attempts' => $job['attempts'] + 1, 'error' => $e->getMessage(), 'next_poll_at' => time() + $this->config['interval']]);

			return $e->getMessage();
		}

		$this->store->update($id, [
			'reference' => $reply['reference'] ?? null,
			'status' => 'sent',
			'hub_status' => $reply['status'] ?? null,
			'sent_at' => time(),
			'attempts' => 0,
			'error' => null,
			'archive_path' => null,
			'next_poll_at' => time() + $this->config['interval'],
		]);
		@unlink($job['archive_path']);

		return null;
	}
}
