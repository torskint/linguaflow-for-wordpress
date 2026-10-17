<?php

namespace Torskint\LinguaFlowWp\Store;

/**
 * Contrat du suivi local des travaux envoyés au hub. Un travail = une ligne : référence du hub, empreinte, état, prochaine interrogation.
 */
interface JobStore
{
	/** États terminaux : le travail n'est plus interrogé. */
	public const FINAL = ['merged', 'failed', 'cancelled'];

	/**
	 * Crée une ligne « new » (archive prête, pas encore acceptée par le hub) et renvoie son identifiant.
	 */
	public function create(string $checksum, array $locales, int $keys, string $archivePath): int;

	/**
	 * Ligne par identifiant.
	 */
	public function find(int $id): ?array;

	/**
	 * Ligne par référence du hub.
	 */
	public function findByReference(string $reference): ?array;

	/**
	 * Travail non terminé qui porte la même empreinte (même contenu déjà en cours).
	 */
	public function activeByChecksum(string $checksum): ?array;

	/**
	 * Met à jour une ligne.
	 */
	public function update(int $id, array $values): void;

	/**
	 * Travaux non terminés dont l'heure d'interrogation est passée.
	 *
	 * @return list<array>
	 */
	public function due(?int $now = null): array;

	/**
	 * Tous les travaux, du plus récent au plus ancien.
	 *
	 * @return list<array>
	 */
	public function all(int $limit = 50): array;

	/**
	 * Supprime les lignes terminées depuis plus de `$days` jours.
	 */
	public function prune(int $days): int;
}
