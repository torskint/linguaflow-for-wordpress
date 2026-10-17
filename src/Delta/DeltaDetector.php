<?php

namespace Torskint\LinguaFlowWp\Delta;

use Torskint\LinguaFlowWp\Source\ThemeSource;

/**
 * Compare la langue source à chaque langue cible et relève les clés manquantes (absentes ou vides).
 */
class DeltaDetector
{
	// Constructeur : source du thème et langue source.
	public function __construct(private ThemeSource $theme, private string $source) {}

	/**
	 * Clés à traduire pour les langues données ; `$full` demande tout le contenu source quelle que soit la cible.
	 *
	 * @param  list<string>  $locales
	 */
	public function detect(array $locales, bool $full = false): Delta
	{
		$delta = new Delta;

		foreach (array_values(array_diff($locales, [$this->source])) as $locale) {
			foreach ($this->theme->archivePaths() as $file) {
				$source = $this->theme->entries($file, $this->source);
				$target = $this->theme->entries($file, $locale);
				$missing = [];

				foreach ($source as $key => $text) {
					if ($full || trim((string) ($target[$key] ?? '')) === '') {
						// La forme plurielle suit l'entrée : le hub la déduit du msgid
						$missing[str_ends_with($key, ThemeSource::PLURAL_SUFFIX) ? substr($key, 0, -strlen(ThemeSource::PLURAL_SUFFIX)) : $key] = true;
					}
				}

				$delta->add($file, $locale, array_map('strval', array_keys($missing)));
			}
		}

		return $delta;
	}
}
