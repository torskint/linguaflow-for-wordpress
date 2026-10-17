<?php

namespace Torskint\LinguaFlowWp\Support;

/**
 * Compile des entrées gettext (analysées par PoParser) en fichier binaire .mo et en fichier .l10n.php (WordPress 6.5+).
 * Seules les entrées traduites et non « fuzzy » sont retenues ; l'en-tête est conservé.
 */
class MoWriter
{
	/** Octet qui sépare le contexte du msgid. */
	private const CONTEXT = "\x04";

	/**
	 * Messages retenus : clé gettext (contexte + 0x04 + msgid, + 0x00 + msgid_plural) => traduction (formes séparées par 0x00).
	 *
	 * @param  list<array>  $entries
	 * @return array<string, string>
	 */
	public function messages(array $entries): array
	{
		$out = [];

		foreach ($entries as $entry) {
			if (! empty($entry['raw']) || $this->fuzzy($entry)) {
				continue;
			}

			$key = (($entry['msgctxt'] ?? '') !== '' ? $entry['msgctxt'].self::CONTEXT : '').(string) ($entry['msgid'] ?? '');

			if (! empty($entry['msgid_plural'])) {
				$forms = $entry['msgstr_plural'] ?? [];
				ksort($forms);

				if (trim(implode('', $forms)) === '') {
					continue;
				}

				$out[$key."\0".$entry['msgid_plural']] = implode("\0", $forms);

				continue;
			}

			$text = (string) ($entry['msgstr'] ?? '');

			if ($text !== '' || ($entry['msgid'] ?? '') === '') {
				$out[$key] = $text;
			}
		}

		ksort($out, SORT_STRING);

		return $out;
	}

	/**
	 * Contenu binaire du fichier .mo (petit-boutiste, sans table de hachage).
	 *
	 * @param  list<array>  $entries
	 */
	public function mo(array $entries): string
	{
		$messages = $this->messages($entries);
		$count = count($messages);
		$ids = '';
		$strings = '';
		$idTable = [];
		$strTable = [];

		foreach ($messages as $id => $string) {
			$idTable[] = [strlen($id), strlen($ids)];
			$ids .= $id."\0";
			$strTable[] = [strlen($string), strlen($strings)];
			$strings .= $string."\0";
		}

		$idsStart = 28 + $count * 16;
		$stringsStart = $idsStart + strlen($ids);
		$binary = pack('V7', 0x950412de, 0, $count, 28, 28 + $count * 8, 0, 0);

		foreach ($idTable as [$length, $offset]) {
			$binary .= pack('VV', $length, $idsStart + $offset);
		}

		foreach ($strTable as [$length, $offset]) {
			$binary .= pack('VV', $length, $stringsStart + $offset);
		}

		return $binary.$ids.$strings;
	}

	/**
	 * Contenu du fichier .l10n.php lu par WordPress 6.5 et plus (en-tête, formes plurielles, messages).
	 *
	 * @param  list<array>  $entries
	 */
	public function l10n(array $entries): string
	{
		$messages = $this->messages($entries);
		$header = $this->headers((string) ($messages[''] ?? ''));
		unset($messages['']);

		$data = ['x-generator' => 'Linguaflow For WordPress'] + array_filter([
			'translation-revision-date' => $header['PO-Revision-Date'] ?? null,
			'plural-forms' => $header['Plural-Forms'] ?? null,
			'language' => $header['Language'] ?? null,
		]) + ['messages' => $messages];

		return "<?php\n\nreturn ".var_export($data, true).";\n";
	}

	/**
	 * Lignes de l'en-tête (« Clé: valeur ») sous forme de tableau.
	 *
	 * @return array<string, string>
	 */
	private function headers(string $header): array
	{
		$out = [];

		foreach (preg_split("/\R/", $header) as $line) {
			if (str_contains($line, ':')) {
				[$name, $value] = explode(':', $line, 2);
				$out[trim($name)] = trim($value);
			}
		}

		return $out;
	}

	/**
	 * Vrai si l'entrée est marquée « fuzzy » (traduction à relire, ignorée par WordPress).
	 */
	private function fuzzy(array $entry): bool
	{
		foreach ($entry['comments'] ?? [] as $comment) {
			if (preg_match('/^#,.*\bfuzzy\b/', $comment)) {
				return true;
			}
		}

		return false;
	}
}
