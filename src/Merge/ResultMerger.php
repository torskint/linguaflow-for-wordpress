<?php

namespace Torskint\LinguaFlowWp\Merge;

use Torskint\LinguaFlowWp\Exceptions\HubException;
use Torskint\LinguaFlowWp\Source\ThemeSource;
use ZipArchive;

/**
 * Fusionne le résultat du hub (`result.json` dans un zip) dans les fichiers de langue du thème.
 * Règles : une valeur non vide n'est jamais écrasée, une traduction dont les marqueurs diffèrent est refusée,
 * l'écriture est atomique avec sauvegarde. Rejouer la même fusion ne change rien.
 */
class ResultMerger
{
	// Constructeur : source du thème.
	public function __construct(private ThemeSource $theme) {}

	/**
	 * Lit le zip de résultat et fusionne toutes les langues. Renvoie le bilan et les textes signalés par le hub.
	 *
	 * @return array{report: MergeReport, flagged: array<string, list<array>>, languages: list<string>}
	 */
	public function mergeZip(string $zipPath): array
	{
		$zip = new ZipArchive;

		if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
			throw new HubException('Résultat illisible (zip invalide).');
		}

		$raw = $zip->getFromName('result.json');
		$zip->close();
		$result = is_string($raw) ? json_decode($raw, true) : null;

		if (! is_array($result) || (int) ($result['protocol'] ?? 0) !== 1 || ! isset($result['languages'])) {
			throw new HubException('Résultat inattendu : result.json absent ou de version inconnue.');
		}

		$report = new MergeReport;
		$flagged = [];

		foreach ((array) $result['languages'] as $locale => $data) {
			foreach ((array) ($data['files'] ?? []) as $archivePath => $texts) {
				$report->add($this->mergeFile((string) $locale, (string) $archivePath, (array) $texts));
			}

			if (! empty($data['flagged'])) {
				$flagged[(string) $locale] = $data['flagged'];
			}
		}

		return ['report' => $report, 'flagged' => $flagged, 'languages' => array_map('strval', array_keys((array) $result['languages']))];
	}

	/**
	 * Fusionne les textes d'un fichier d'une langue.
	 *
	 * @param  array<string, string>  $texts
	 */
	private function mergeFile(string $locale, string $archivePath, array $texts): MergeReport
	{
		if ($this->theme->knows($archivePath)) {
			return $this->theme->merge($archivePath, $locale, $texts);
		}

		$report = new MergeReport;
		$report->errors[] = 'Fichier inconnu du thème : '.$archivePath;

		return $report;
	}
}
