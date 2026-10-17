<?php

namespace Torskint\LinguaFlowWp\Http;

/**
 * Contrat du client HTTP du Translation Hub (API /v1/jobs). Les erreurs sont signalées par HubException.
 */
interface HubClient
{
	/**
	 * Envoie l'archive. La clé d'idempotence garantit qu'un nouvel essai ne crée pas un second travail.
	 *
	 * @return array{job: array, created: bool}
	 */
	public function create(string $zipPath, string $idempotencyKey): array;

	/**
	 * Statut et progression d'un travail.
	 */
	public function status(string $reference): array;

	/**
	 * Télécharge le résultat dans un fichier temporaire ; null si le travail n'est pas encore prêt.
	 *
	 * @return array{path: string, missing: list<string>}|null
	 */
	public function result(string $reference): ?array;

	/**
	 * Confirme au hub que le résultat est fusionné.
	 */
	public function ack(string $reference): void;

	/**
	 * Annule un travail non terminé.
	 */
	public function cancel(string $reference): array;

	/**
	 * État du service : vérifie l'adresse, la clé et la version du protocole.
	 */
	public function health(): array;
}
