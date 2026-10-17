<?php

namespace Torskint\LinguaFlowWp\Sync;

use Throwable;
use Torskint\LinguaFlowWp\Exceptions\HubException;
use Torskint\LinguaFlowWp\Http\HubClient;
use Torskint\LinguaFlowWp\Merge\ResultMerger;
use Torskint\LinguaFlowWp\Store\JobStore;

/**
 * Interroge le hub pour chaque travail en cours : renvoie les archives en attente, récupère les résultats prêts,
 * les fusionne dans les fichiers de langue puis confirme au hub. Chaque travail est traité séparément (envois en parallèle).
 */
class Syncer
{
	/** Statuts du hub où le travail est terminé et son résultat disponible. */
	private const DONE = ['completed'];

	/** Statuts du hub où le travail ne donnera rien. */
	private const DEAD = ['failed', 'cancelled'];

	/**
	 * @param  array{interval: int, max_interval: int, max_age_days: int}  $config
	 */
	public function __construct(
		private HubClient $hub,
		private JobStore $store,
		private ResultMerger $merger,
		private Sender $sender,
		private array $config,
	) {}

	/**
	 * Traite tous les travaux dont l'heure est venue (ou celui de la référence donnée, sans attendre l'heure).
	 *
	 * @return list<array{id: int, reference: ?string, state: string, message: ?string}> bilan par travail
	 */
	public function run(?string $reference = null): array
	{
		$jobs = $reference !== null
			? array_filter([$this->store->findByReference($reference)], fn ($j) => $j !== null && ! in_array($j['status'], JobStore::FINAL, true))
			: $this->store->due();

		$out = [];

		foreach ($jobs as $job) {
			$out[] = $this->process($job);
		}

		return $out;
	}

	/**
	 * Traite un travail et renvoie son bilan.
	 *
	 * @return array{id: int, reference: ?string, state: string, message: ?string}
	 */
	public function process(array $job): array
	{
		$id = (int) $job['id'];

		if ($job['status'] === 'new') {
			$error = $this->sender->push($id);

			return $this->report($id, $error === null ? 'sent' : 'waiting', $error);
		}

		if (time() - (int) $job['created_at'] > $this->config['max_age_days'] * 86400) {
			$this->store->update($id, ['status' => 'failed', 'error' => 'Délai dépassé sans résultat.']);

			return $this->report($id, 'failed', 'Délai dépassé sans résultat.');
		}

		try {
			$status = $this->hub->status((string) $job['reference']);
			$hubStatus = (string) ($status['status'] ?? '');
			$this->store->update($id, ['hub_status' => $hubStatus]);

			if (in_array($hubStatus, self::DEAD, true)) {
				$this->store->update($id, ['status' => $hubStatus === 'cancelled' ? 'cancelled' : 'failed', 'error' => (string) ($status['error'] ?? $hubStatus)]);

				return $this->report($id, $hubStatus, null);
			}

			if (! in_array($hubStatus, self::DONE, true)) {
				return $this->later($job, 'waiting');
			}

			$result = $this->hub->result((string) $job['reference']);

			if ($result === null) {
				return $this->later($job, 'waiting');
			}

			try {
				$merged = $this->merger->mergeZip($result['path']);
			} finally {
				@unlink($result['path']);
			}

			$summary = $merged['report']->toArray() + ['flagged' => $merged['flagged']];

			// Une erreur de fichier (illisible, non inscriptible) : on garde le travail ouvert pour un nouvel essai
			if (! $merged['report']->ok()) {
				$this->store->update($id, ['report' => $summary, 'error' => implode(' | ', $merged['report']->errors)]);

				return $this->later($job, 'error', implode(' | ', $merged['report']->errors));
			}

			$this->hub->ack((string) $job['reference']);
			$this->store->update($id, ['status' => 'merged', 'merged_at' => time(), 'error' => null, 'report' => $summary]);

			return $this->report($id, 'merged', null);
		} catch (HubException $e) {
			if ($e->status === 404) {
				$this->store->update($id, ['status' => 'failed', 'error' => 'Travail inconnu du hub.']);

				return $this->report($id, 'failed', 'Travail inconnu du hub.');
			}

			return $this->later($job, 'error', $e->getMessage());
		} catch (Throwable $e) {
			return $this->later($job, 'error', $e->getMessage());
		}
	}

	/**
	 * Reprogramme l'interrogation avec un délai qui s'allonge (borné) à chaque passage.
	 *
	 * @return array{id: int, reference: ?string, state: string, message: ?string}
	 */
	private function later(array $job, string $state, ?string $message = null): array
	{
		$attempts = (int) $job['attempts'] + 1;
		$delay = min($this->config['max_interval'], $this->config['interval'] * (2 ** intdiv($attempts, 3)));

		$this->store->update((int) $job['id'], ['attempts' => $attempts, 'next_poll_at' => time() + $delay, 'error' => $message]);

		return $this->report((int) $job['id'], $state, $message);
	}

	/**
	 * Bilan d'un travail traité.
	 *
	 * @return array{id: int, reference: ?string, state: string, message: ?string}
	 */
	private function report(int $id, string $state, ?string $message): array
	{
		return ['id' => $id, 'reference' => $this->store->find($id)['reference'] ?? null, 'state' => $state, 'message' => $message];
	}
}
