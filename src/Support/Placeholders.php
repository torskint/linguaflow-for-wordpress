<?php

namespace Torskint\LinguaFlowWp\Support;

/**
 * Contrôle structurel des marqueurs d'un texte (variables, balises) : une traduction doit garder exactement ceux de la source.
 */
class Placeholders
{
	/** Marqueurs reconnus : « :nom », « %s », « %1$d », « {nom} », « {{ nom }} », « <b> ». */
	private const PATTERN = '/%(?:\d+\$)?[-+ 0#]*\d*(?:\.\d+)?[sdifeEgGxXobcu]|(?<![\w:\/]):[A-Za-z_][A-Za-z0-9_]*|\{\{\s*[\w.]+\s*\}\}|\{[\w.]+\}|<\/?[A-Za-z][^>]*>/u';

	/**
	 * Marqueurs d'un texte, triés (comparables entre eux).
	 *
	 * @return list<string>
	 */
	public static function of(string $text): array
	{
		preg_match_all(self::PATTERN, $text, $m);
		$found = $m[0];
		sort($found);

		return $found;
	}

	/**
	 * Vrai si la traduction porte les mêmes marqueurs que la source.
	 */
	public static function same(string $source, string $translation): bool
	{
		return self::of($source) === self::of($translation);
	}
}
