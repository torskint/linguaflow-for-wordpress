<?php

namespace Torskint\LinguaFlowWp\Merge;

/**
 * Bilan d'une fusion : textes écrits, textes laissés tels quels parce qu'ils existaient déjà, textes refusés et erreurs.
 */
class MergeReport
{
	/** Nombre de textes écrits. */
	public int $merged = 0;

	/** Nombre de textes ignorés parce que la clé avait déjà une valeur non vide. */
	public int $kept = 0;

	/** Fichiers écrits. @var list<string> */
	public array $written = [];

	/** Textes refusés : [fichier, clé, motif]. @var list<array{0: string, 1: string, 2: string}> */
	public array $rejected = [];

	/** Erreurs qui empêchent de traiter un fichier. @var list<string> */
	public array $errors = [];

	/**
	 * Ajoute le bilan d'un autre fichier.
	 */
	public function add(self $other): void
	{
		$this->merged += $other->merged;
		$this->kept += $other->kept;
		$this->written = array_merge($this->written, $other->written);
		$this->rejected = array_merge($this->rejected, $other->rejected);
		$this->errors = array_merge($this->errors, $other->errors);
	}

	/**
	 * Vrai si la fusion s'est faite sans erreur de fichier.
	 */
	public function ok(): bool
	{
		return $this->errors === [];
	}

	/**
	 * Représentation simple pour la base locale.
	 */
	public function toArray(): array
	{
		return [
			'merged' => $this->merged,
			'kept' => $this->kept,
			'written' => $this->written,
			'rejected' => $this->rejected,
			'errors' => $this->errors,
		];
	}
}
