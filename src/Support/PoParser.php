<?php

namespace Torskint\LinguaFlowWp\Support;

/**
 * Lit et réécrit un fichier gettext (.po / .pot) sous forme de liste d'entrées.
 * Chaque entrée : comments, msgctxt, msgid, msgid_plural, msgstr, msgstr_plural.
 */
class PoParser
{
	/**
	 * Analyse le contenu d'un fichier PO : continuations de lignes, commentaires, contexte et pluriels.
	 */
	public function parse(string $content): array
	{
		$content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
		$lines = preg_split("/\R/", $content);
		$entries = [];
		$current = null;
		$pending = [];
		$target = null;

		foreach ($lines as $line) {
			$trimmed = trim($line);

			if ($trimmed === '') {
				continue;
			}

			if (str_starts_with($trimmed, '#')) {
				// Un commentaire après une entrée complète annonce l'entrée suivante.
				if ($current !== null && $target !== 'comment') {
					$entries[] = $current;
					$current = null;
				}
				$pending[] = $trimmed;
				$target = 'comment';

				continue;
			}

			if (str_starts_with($trimmed, 'msgctxt ')) {
				if ($current !== null) {
					$entries[] = $current;
				}
				$current = $this->newEntry($pending);
				$current['msgctxt'] = $this->unquote(substr($trimmed, 8));
				$target = 'msgctxt';

				continue;
			}

			if (str_starts_with($trimmed, 'msgid_plural ')) {
				if ($current !== null) {
					$current['msgid_plural'] = $this->unquote(substr($trimmed, 13));
					$target = 'msgid_plural';
				}

				continue;
			}

			if (str_starts_with($trimmed, 'msgid ')) {
				if ($current !== null && $target !== 'msgctxt') {
					$entries[] = $current;
					$current = $this->newEntry($pending);
				} elseif ($current === null) {
					$current = $this->newEntry($pending);
				}
				$current['msgid'] = $this->unquote(substr($trimmed, 6));
				$target = 'msgid';

				continue;
			}

			if (preg_match('/^msgstr\[(\d+)\]\s+(.*)$/', $trimmed, $m)) {
				if ($current !== null) {
					$current['msgstr_plural'][(int) $m[1]] = $this->unquote($m[2]);
					$target = ['msgstr_plural', (int) $m[1]];
				}

				continue;
			}

			if (str_starts_with($trimmed, 'msgstr ')) {
				if ($current !== null) {
					$current['msgstr'] = $this->unquote(substr($trimmed, 7));
					$target = 'msgstr';
				}

				continue;
			}

			if (str_starts_with($trimmed, '"') && $current !== null && $target !== null && $target !== 'comment') {
				$this->appendContinuation($current, $target, $this->unquote($trimmed));
			}
		}

		if ($current !== null) {
			$entries[] = $current;
		}

		// Commentaires restés sans entrée (entrées obsolètes « #~ » en fin de fichier) : conservés tels quels.
		if ($pending !== []) {
			$entries[] = ['raw' => true, 'comments' => $pending, 'msgctxt' => null, 'msgid' => '', 'msgid_plural' => null, 'msgstr' => '', 'msgstr_plural' => []];
		}

		return $entries;
	}

	/**
	 * Écrit les entrées en fichier PO (en-tête compris, lignes de continuation incluses).
	 */
	public function render(array $entries): string
	{
		$out = [];

		foreach ($entries as $e) {
			foreach ($e['comments'] ?? [] as $comment) {
				$out[] = $comment;
			}

			if (! empty($e['raw'])) {
				$out[] = '';

				continue;
			}

			if (! empty($e['msgctxt'])) {
				$out = array_merge($out, $this->renderField('msgctxt', $e['msgctxt']));
			}

			$out = array_merge($out, $this->renderField('msgid', $e['msgid'] ?? ''));

			if (! empty($e['msgid_plural'])) {
				$out = array_merge($out, $this->renderField('msgid_plural', $e['msgid_plural']));
				foreach (($e['msgstr_plural'] ?? []) as $i => $value) {
					$out = array_merge($out, $this->renderField('msgstr['.$i.']', (string) $value));
				}
			} else {
				$out = array_merge($out, $this->renderField('msgstr', (string) ($e['msgstr'] ?? '')));
			}

			$out[] = '';
		}

		return rtrim(implode("\n", $out), "\n")."\n";
	}

	/**
	 * Crée une entrée vide qui reprend les commentaires en attente.
	 */
	private function newEntry(array &$pending): array
	{
		$entry = [
			'comments' => $pending,
			'msgctxt' => null,
			'msgid' => '',
			'msgid_plural' => null,
			'msgstr' => '',
			'msgstr_plural' => [],
		];
		$pending = [];

		return $entry;
	}

	/**
	 * Ajoute une ligne de continuation au champ en cours de lecture.
	 */
	private function appendContinuation(array &$entry, string|array $target, string $text): void
	{
		if (is_array($target)) {
			$entry['msgstr_plural'][$target[1]] .= $text;

			return;
		}

		$entry[$target] .= $text;
	}

	/**
	 * Rend un champ en une ligne, ou en bloc `mot-clé ""` + continuations s'il contient des retours à la ligne.
	 */
	private function renderField(string $keyword, string $value): array
	{
		if ($value === '') {
			return [$keyword.' ""'];
		}

		if (! str_contains($value, "\n")) {
			return [$keyword.' "'.$this->escape($value).'"'];
		}

		$endsWithNewline = str_ends_with($value, "\n");
		$segments = explode("\n", $endsWithNewline ? substr($value, 0, -1) : $value);
		$last = count($segments) - 1;
		$out = [$keyword.' ""'];

		foreach ($segments as $i => $segment) {
			$suffix = ($i < $last || $endsWithNewline) ? '\n' : '';
			$out[] = '"'.$this->escape($segment).$suffix.'"';
		}

		return $out;
	}

	/**
	 * Retire les guillemets d'une valeur PO et interprète ses échappements.
	 */
	private function unquote(string $text): string
	{
		$text = trim($text);
		if (str_starts_with($text, '"') && str_ends_with($text, '"')) {
			$text = substr($text, 1, -1);
		}

		return stripcslashes($text);
	}

	/**
	 * Échappe une valeur pour l'écriture entre guillemets.
	 */
	private function escape(string $text): string
	{
		return addcslashes($text, "\\\"\n\r\t");
	}
}
