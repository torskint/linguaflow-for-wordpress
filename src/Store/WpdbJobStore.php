<?php

namespace Torskint\LinguaFlowWp\Store;

/**
 * Suivi local des travaux dans la table `{préfixe}linguaflow_jobs` de la base WordPress (créée à l'activation du plugin).
 */
class WpdbJobStore implements JobStore
{
	// Constructeur : accès à la base WordPress.
	public function __construct(private \wpdb $db) {}

	/**
	 * Nom complet de la table.
	 */
	public function table(): string
	{
		return $this->db->prefix.'linguaflow_jobs';
	}

	/**
	 * Crée la table (ou la met à jour) ; appelé à l'activation du plugin.
	 */
	public function install(): void
	{
		require_once ABSPATH.'wp-admin/includes/upgrade.php';

		$table = $this->table();
		$charset = $this->db->get_charset_collate();

		dbDelta("CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			uuid varchar(40) NOT NULL,
			reference varchar(40) DEFAULT NULL,
			checksum varchar(64) NOT NULL,
			locales text NOT NULL,
			keys_count int(10) unsigned NOT NULL DEFAULT 0,
			archive_path text DEFAULT NULL,
			status varchar(16) NOT NULL DEFAULT 'new',
			hub_status varchar(24) DEFAULT NULL,
			attempts int(10) unsigned NOT NULL DEFAULT 0,
			next_poll_at int(10) unsigned NOT NULL DEFAULT 0,
			sent_at int(10) unsigned DEFAULT NULL,
			merged_at int(10) unsigned DEFAULT NULL,
			error text DEFAULT NULL,
			report longtext DEFAULT NULL,
			created_at int(10) unsigned NOT NULL,
			updated_at int(10) unsigned NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uuid (uuid),
			UNIQUE KEY reference (reference),
			KEY checksum (checksum),
			KEY status (status)
		) {$charset};");
	}

	/**
	 * {@inheritDoc}
	 */
	public function create(string $checksum, array $locales, int $keys, string $archivePath): int
	{
		$now = time();

		$this->db->insert($this->table(), [
			'uuid' => bin2hex(random_bytes(16)),
			'checksum' => $checksum,
			'locales' => wp_json_encode($locales),
			'keys_count' => $keys,
			'archive_path' => $archivePath,
			'status' => 'new',
			'attempts' => 0,
			'next_poll_at' => $now,
			'created_at' => $now,
			'updated_at' => $now,
		]);

		return (int) $this->db->insert_id;
	}

	/**
	 * {@inheritDoc}
	 */
	public function find(int $id): ?array
	{
		return $this->one($this->db->prepare("SELECT * FROM {$this->table()} WHERE id = %d", $id));
	}

	/**
	 * {@inheritDoc}
	 */
	public function findByReference(string $reference): ?array
	{
		return $this->one($this->db->prepare("SELECT * FROM {$this->table()} WHERE reference = %s", $reference));
	}

	/**
	 * {@inheritDoc}
	 */
	public function activeByChecksum(string $checksum): ?array
	{
		return $this->one($this->db->prepare("SELECT * FROM {$this->table()} WHERE checksum = %s AND status NOT IN ('merged','failed','cancelled') LIMIT 1", $checksum));
	}

	/**
	 * {@inheritDoc}
	 */
	public function update(int $id, array $values): void
	{
		if (isset($values['report']) && is_array($values['report'])) {
			$values['report'] = wp_json_encode($values['report']);
		}

		$this->db->update($this->table(), $values + ['updated_at' => time()], ['id' => $id]);
	}

	/**
	 * {@inheritDoc}
	 */
	public function due(?int $now = null): array
	{
		$rows = $this->db->get_results($this->db->prepare("SELECT * FROM {$this->table()} WHERE status NOT IN ('merged','failed','cancelled') AND next_poll_at <= %d ORDER BY id", $now ?? time()), ARRAY_A);

		return array_map(fn ($row) => $this->decode($row), (array) $rows);
	}

	/**
	 * {@inheritDoc}
	 */
	public function all(int $limit = 50): array
	{
		$rows = $this->db->get_results($this->db->prepare("SELECT * FROM {$this->table()} ORDER BY id DESC LIMIT %d", $limit), ARRAY_A);

		return array_map(fn ($row) => $this->decode($row), (array) $rows);
	}

	/**
	 * {@inheritDoc}
	 */
	public function prune(int $days): int
	{
		return (int) $this->db->query($this->db->prepare("DELETE FROM {$this->table()} WHERE status IN ('merged','failed','cancelled') AND updated_at < %d", time() - $days * 86400));
	}

	/**
	 * Une ligne à partir d'une requête préparée.
	 */
	private function one(string $sql): ?array
	{
		$row = $this->db->get_row($sql, ARRAY_A);

		return $row === null ? null : $this->decode($row);
	}

	/**
	 * Ligne sous forme de tableau, avec les colonnes JSON décodées et les entiers typés.
	 */
	private function decode(array $row): array
	{
		foreach (['id', 'keys_count', 'attempts', 'next_poll_at', 'created_at', 'updated_at'] as $column) {
			$row[$column] = (int) $row[$column];
		}

		$row['locales'] = (array) json_decode((string) $row['locales'], true);
		$row['report'] = $row['report'] !== null ? json_decode((string) $row['report'], true) : null;

		return $row;
	}
}
