<?php

namespace Torskint\LinguaFlowWp\Archive;

use Torskint\LinguaFlowWp\Delta\Delta;
use Torskint\LinguaFlowWp\Exceptions\HubException;
use Torskint\LinguaFlowWp\Source\ThemeSource;
use ZipArchive;

/**
 * Construit l'archive envoyée au hub : fichiers source réduits aux clés demandées et manifeste `.linguaflow/manifest.json`.
 */
class ArchiveBuilder
{
	/** Chemin du manifeste dans l'archive. */
	public const MANIFEST = '.linguaflow/manifest.json';

	/** Version du protocole parlé avec le hub. */
	public const PROTOCOL = 1;

	// Constructeur : source du thème.
	public function __construct(private ThemeSource $theme) {}

	/**
	 * Crée le zip dans un fichier temporaire. Renvoie son chemin et l'empreinte du contenu (clés + textes), qui sert à ne pas
	 * renvoyer deux fois le même travail.
	 *
	 * @param  array{client_ref: ?string, delivery: string, webhook_url: ?string, full: bool}  $options
	 * @return array{path: string, checksum: string, targets: array<string, array<string, list<string>>>}
	 */
	public function build(Delta $delta, string $source, array $options): array
	{
		if ($delta->isEmpty()) {
			throw new HubException('Rien à envoyer.');
		}

		$targets = $delta->targets();
		$manifest = [
			'protocol' => self::PROTOCOL,
			'framework' => 'wordpress',
			'client_ref' => $options['client_ref'],
			'source_locale' => $source,
			'delivery' => array_filter(['mode' => $options['delivery'], 'webhook_url' => $options['webhook_url']], fn ($v) => $v !== null),
			'targets' => $targets,
		];

		if ($options['full']) {
			$manifest['full'] = true;
		}

		$path = tempnam(sys_get_temp_dir(), 'lfwarch');
		$zip = new ZipArchive;

		if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
			throw new HubException('Archive impossible à créer.');
		}

		$hash = hash_init('sha256');
		hash_update($hash, json_encode($targets, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

		foreach ($delta->files() as $name => $keys) {
			$content = $this->theme->export($name, $keys);
			$zip->addFromString($name, $content);
			hash_update($hash, $name."\0".$content);
		}

		$zip->addFromString(self::MANIFEST, json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
		$zip->close();

		return ['path' => $path, 'checksum' => hash_final($hash), 'targets' => $targets];
	}
}
