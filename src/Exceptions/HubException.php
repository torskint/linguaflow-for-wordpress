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

	/** Identifiant de la requête (en-tête X-Request-Id du hub), à citer au support pour retrouver l'appel dans l'Activité API. */
	public ?string $requestId = null;

	/**
	 * Erreur issue d'une réponse du hub. Le message se termine par l'identifiant de la requête quand le hub en a donné un :
	 * il s'affiche ainsi partout où l'erreur est montrée (console, écran, base locale des envois).
	 */
	public static function fromHub(int $status, ?string $code, string $message, ?string $requestId = null): self
	{
		$requestId = $requestId !== null && preg_match('/^[A-Za-z0-9._-]{8,64}$/', $requestId) === 1 ? $requestId : null;
		$text = $message !== '' ? $message : 'Réponse inattendue du hub ('.$status.').';
		$e = new self($requestId !== null ? $text.' [X-Request-Id : '.$requestId.']' : $text);
		$e->status = $status;
		$e->errorCode = $code;
		$e->requestId = $requestId;

		return $e;
	}
}
