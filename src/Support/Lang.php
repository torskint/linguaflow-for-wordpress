<?php

namespace Torskint\LinguaFlowWp\Support;

/**
 * Textes de l'interface : clés TRAD_n du domaine du plugin, toujours en français (le fichier .mo du plugin est chargé tel quel).
 */
class Lang
{
	/** Domaine de texte du plugin. */
	public const DOMAIN = 'linguaflow-for-wordpress';

	/**
	 * Texte d'une clé, avec ses arguments (marqueurs %s).
	 */
	public static function t(int $id, string|int|float ...$args): string
	{
		$text = function_exists('__') ? __('TRAD_'.$id, self::DOMAIN) : 'TRAD_'.$id;

		return $args === [] ? $text : vsprintf($text, $args);
	}
}
