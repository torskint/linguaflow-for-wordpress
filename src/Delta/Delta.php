<?php

namespace Torskint\LinguaFlowWp\Delta;

/**
 * Textes à faire traduire : pour chaque langue cible et chaque fichier, les clés manquantes.
 */
class Delta
{
	/** Éléments : chemin d'archive du fichier, langue, clés. @var list<array{file: string, locale: string, keys: list<string>}> */
	public array $items = [];

	/**
	 * Ajoute les clés manquantes d'un fichier pour une langue.
	 *
	 * @param  list<string>  $keys
	 */
	public function add(string $file, string $locale, array $keys): void
	{
		if ($keys !== []) {
			$this->items[] = ['file' => $file, 'locale' => $locale, 'keys' => array_values($keys)];
		}
	}

	/**
	 * Vrai si rien n'est à traduire.
	 */
	public function isEmpty(): bool
	{
		return $this->items === [];
	}

	/**
	 * Langues cibles concernées.
	 *
	 * @return list<string>
	 */
	public function locales(): array
	{
		return array_values(array_unique(array_column($this->items, 'locale')));
	}

	/**
	 * Nombre total de clés à traduire (toutes langues).
	 */
	public function count(): int
	{
		return array_sum(array_map(fn ($i) => count($i['keys']), $this->items));
	}

	/**
	 * Demande au format du manifeste : langue => chemin dans l'archive => clés.
	 *
	 * @return array<string, array<string, list<string>>>
	 */
	public function targets(): array
	{
		$targets = [];

		foreach ($this->items as $item) {
			$targets[$item['locale']][$item['file']] = $item['keys'];
		}

		ksort($targets);

		return $targets;
	}

	/**
	 * Fichiers source à joindre à l'archive, avec l'ensemble des clés demandées (toutes langues confondues).
	 *
	 * @return array<string, list<string>>
	 */
	public function files(): array
	{
		$files = [];

		foreach ($this->items as $item) {
			$files[$item['file']] = array_values(array_unique(array_merge($files[$item['file']] ?? [], $item['keys'])));
		}

		return $files;
	}
}
