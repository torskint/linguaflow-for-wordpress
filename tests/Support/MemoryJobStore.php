<?php

namespace Torskint\LinguaFlowWp\Tests\Support;

use Torskint\LinguaFlowWp\Store\JobStore;

/**
 * Suivi des travaux en mémoire (tests, sans base WordPress).
 */
class MemoryJobStore implements JobStore
{
	/** Lignes par identifiant. @var array<int, array> */
	public array $rows = [];

	/**
	 * {@inheritDoc}
	 */
	public function create(string $checksum, array $locales, int $keys, string $archivePath): int
	{
		$id = count($this->rows) + 1;
		$this->rows[$id] = [
			'id' => $id, 'uuid' => bin2hex(random_bytes(16)), 'reference' => null, 'checksum' => $checksum, 'locales' => $locales,
			'keys_count' => $keys, 'archive_path' => $archivePath, 'status' => 'new', 'hub_status' => null, 'attempts' => 0,
			'next_poll_at' => 0, 'sent_at' => null, 'merged_at' => null, 'error' => null, 'report' => null, 'created_at' => time(), 'updated_at' => time(),
		];

		return $id;
	}

	/**
	 * {@inheritDoc}
	 */
	public function find(int $id): ?array
	{
		return $this->rows[$id] ?? null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function findByReference(string $reference): ?array
	{
		foreach ($this->rows as $row) {
			if ($row['reference'] === $reference) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function activeByChecksum(string $checksum): ?array
	{
		foreach ($this->rows as $row) {
			if ($row['checksum'] === $checksum && ! in_array($row['status'], self::FINAL, true)) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function update(int $id, array $values): void
	{
		$this->rows[$id] = $values + $this->rows[$id];
	}

	/**
	 * {@inheritDoc}
	 */
	public function due(?int $now = null): array
	{
		return array_values(array_filter($this->rows, fn ($r) => ! in_array($r['status'], self::FINAL, true) && $r['next_poll_at'] <= ($now ?? time())));
	}

	/**
	 * {@inheritDoc}
	 */
	public function all(int $limit = 50): array
	{
		return array_slice(array_reverse(array_values($this->rows)), 0, $limit);
	}

	/**
	 * {@inheritDoc}
	 */
	public function prune(int $days): int
	{
		return 0;
	}
}
