<?php

namespace Torskint\LinguaFlowWp\Support;

use Torskint\LinguaFlowWp\Exceptions\HubException;

/**
 * Écrit un fichier de façon atomique (temporaire du même dossier, puis remplacement), avec une copie de sauvegarde de l'ancien contenu.
 */
class FileWriter
{
	// Constructeur : dossier des sauvegardes (null = pas de sauvegarde).
	public function __construct(private ?string $backupDir = null) {}

	/**
	 * Écrit le fichier ; l'ancien contenu est sauvegardé avant le remplacement.
	 */
	public function write(string $path, string $content): void
	{
		$dir = dirname($path);

		if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
			throw new HubException('Dossier impossible à créer : '.$dir);
		}

		if (is_file($path) && $this->backupDir !== null) {
			$this->backup($path);
		}

		$tmp = $path.'.'.bin2hex(random_bytes(4)).'.tmp';

		if (@file_put_contents($tmp, $content, LOCK_EX) === false) {
			throw new HubException('Écriture impossible : '.$path);
		}

		if (! @rename($tmp, $path)) {
			@unlink($tmp);

			throw new HubException('Remplacement impossible : '.$path);
		}
	}

	/**
	 * Copie l'ancien fichier dans le dossier des sauvegardes.
	 */
	private function backup(string $path): void
	{
		if (! is_dir($this->backupDir) && ! @mkdir($this->backupDir, 0775, true) && ! is_dir($this->backupDir)) {
			throw new HubException('Dossier de sauvegarde impossible à créer : '.$this->backupDir);
		}

		$name = date('Ymd-His').'-'.substr(md5($path), 0, 6).'-'.basename($path);
		copy($path, rtrim($this->backupDir, '/\\').DIRECTORY_SEPARATOR.$name);
	}
}
