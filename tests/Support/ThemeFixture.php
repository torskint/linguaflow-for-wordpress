<?php

namespace Torskint\LinguaFlowWp\Tests\Support;

use Torskint\LinguaFlowWp\Archive\ArchiveBuilder;
use Torskint\LinguaFlowWp\Delta\DeltaDetector;
use Torskint\LinguaFlowWp\Merge\ResultMerger;
use Torskint\LinguaFlowWp\Source\ThemeSource;
use Torskint\LinguaFlowWp\Support\FileWriter;

/**
 * Thème WordPress de test dans un dossier temporaire : un .po source en français et l'ensemble des objets du plugin câblés dessus.
 */
trait ThemeFixture
{
	/** Racine du dossier temporaire. */
	protected string $root;

	/** Chemin d'archive du .po source du thème. */
	protected string $file = 'themes/mytheme/languages/mytheme-fr_FR.po';

	/**
	 * Crée le thème de test (deux textes, un pluriel avec marqueur, un texte avec contexte).
	 */
	protected function makeTheme(): ThemeSource
	{
		$this->root = sys_get_temp_dir().'/lfw'.bin2hex(random_bytes(4));
		mkdir($this->root.'/themes/mytheme/languages', 0775, true);
		mkdir($this->root.'/wp-lang', 0775, true);

		file_put_contents($this->root.'/themes/mytheme/languages/mytheme-fr_FR.po', <<<'PO'
msgid ""
msgstr ""
"Language: fr_FR\n"
"Content-Type: text/plain; charset=UTF-8\n"
"Plural-Forms: nplurals=2; plural=(n > 1);\n"

msgid "Ajouter au panier"
msgstr "Ajouter au panier"

msgctxt "bouton"
msgid "Voir"
msgstr "Voir"

msgid "%d article"
msgid_plural "%d articles"
msgstr[0] "%d article"
msgstr[1] "%d articles"
PO);

		return $this->theme();
	}

	/**
	 * Source du thème sur le dossier de test.
	 */
	protected function theme(): ThemeSource
	{
		return new ThemeSource(
			[['slug' => 'mytheme', 'dir' => $this->root.'/themes/mytheme']],
			$this->root.'/wp-lang',
			'fr_FR',
			new FileWriter($this->root.'/backups'),
		);
	}

	/**
	 * Détecteur de delta sur la source donnée.
	 */
	protected function detector(ThemeSource $theme): DeltaDetector
	{
		return new DeltaDetector($theme, 'fr_FR');
	}

	/**
	 * Fusionneur de résultat sur la source donnée.
	 */
	protected function merger(ThemeSource $theme): ResultMerger
	{
		return new ResultMerger($theme);
	}

	/**
	 * Constructeur d'archive sur la source donnée.
	 */
	protected function archive(ThemeSource $theme): ArchiveBuilder
	{
		return new ArchiveBuilder($theme);
	}

	/**
	 * Supprime le dossier de test.
	 */
	protected function cleanTheme(): void
	{
		if (! isset($this->root) || ! is_dir($this->root)) {
			return;
		}

		foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $item) {
			$item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
		}

		rmdir($this->root);
	}
}
