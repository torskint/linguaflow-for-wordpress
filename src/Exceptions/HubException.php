<?php

namespace Torskint\LinguaFlowWp\Exceptions;

use RuntimeException;

/**
 * Erreur du plugin (configuration, fichier de langue illisible, réponse du hub inattendue).
 */
class HubException extends RuntimeException
{
	/** Code HTTP de la réponse du hub, quand l'erreur en vient. */
	public ?int $status = null;

	/** Code d'erreur stable renvoyé par le hub (error.code), s'il y en a un. */
	public ?string $errorCode = null;

	/**
	 * Erreur issue d'une réponse du hub.
	 */
	public static function fromHub(int $status, ?string $code, string $message): self
	{
		$e = new self($message !== '' ? $message : 'Réponse inattendue du hub ('.$status.').');
		$e->status = $status;
		$e->errorCode = $code;

		return $e;
	}
}
