<?php

namespace Torskint\LinguaFlowWp\Source;

use Throwable;
use Torskint\LinguaFlowWp\Exceptions\HubException;
use Torskint\LinguaFlowWp\Merge\MergeReport;
use Torskint\LinguaFlowWp\Support\FileWriter;
use Torskint\LinguaFlowWp\Support\MoWriter;
use Torskint\LinguaFlowWp\Support\Placeholders;
use Torskint\LinguaFlowWp\Support\PoParser;

/**
 * Fichiers de langue du thème actif (et du thème parent) : le .po (ou .pot) source du dossier `languages/` du thème et celui de
 * `wp-content/languages/themes/`. Un « fichier » est identifié par son chemin dans l'archive ; le fichier d'une langue cible
 * porte le même nom, la langue source étant remplacée par la cible.
 *
 * Clé d'une entrée = msgid, précédé de « msgctxt » et de l'octet 0x04 quand il y a un contexte. La forme plurielle porte en
 * plus le suffixe PLURAL_SUFFIX. Pour la langue source, le texte est le msgid lui-même.
 */
class ThemeSource
{
	/** Suffixe de la clé de la forme plurielle (identique à celui du hub). */
	public const PLURAL_SUFFIX = "\0plural";

	/** Forme d'un code de langue accepté pour écrire un fichier (évite toute sortie du dossier de langue). */
	private const LOCALE = '/^[a-z]{2,3}([_-][A-Za-z0-9]{2,8}){0,2}$/';

	/** Fichiers source trouvés, mis en cache : chemin d'archive => ['dir' => ..., 'prefix' => ..., 'source' => ...]. */
	private ?array $files = null;

	/**
	 * @param  list<array{slug: string, dir: string}>  $themes  thème actif d'abord, puis le thème parent s'il y en a un
	 */
	public function __construct(
		private array $themes,
		private string $globalDir,
		private string $source,
		private FileWriter $writer,
		private PoParser $parser = new PoParser,
		private MoWriter $mo = new MoWriter,
	) {}

	/**
	 * Chemins d'archive des fichiers source.
	 *
	 * @return list<string>
	 */
	public function archivePaths(): array
	{
		return array_keys($this->files());
	}

	/**
	 * Vrai si le chemin d'archive désigne un fichier de ce thème.
	 */
	public function knows(string $archivePath): bool
	{
		return isset($this->files()[$archivePath]);
	}

	/**
	 * Textes d'un fichier pour une langue : clé => texte (vide si le fichier de la langue n'existe pas encore).
	 *
	 * @return array<string, string>
	 */
	public function entries(string $archivePath, string $locale): array
	{
		$path = $this->path($archivePath, $locale);

		if ($path === null || ! is_file($path)) {
			return [];
		}

		$texts = [];

		foreach ($this->parser->parse((string) file_get_contents($path)) as $entry) {
			if (! $this->translatable($entry)) {
				continue;
			}

			$key = $this->requestKey($entry);
			$plural = ! empty($entry['msgid_plural']);

			if ($locale === $this->source) {
				$texts[$key] = (string) $entry['msgid'];
				$plural && $texts[$key.self::PLURAL_SUFFIX] = (string) $entry['msgid_plural'];
			} elseif ($plural) {
				$forms = $entry['msgstr_plural'] ?? [];
				$texts[$key] = (string) ($forms[0] ?? '');
				$texts[$key.self::PLURAL_SUFFIX] = (string) ($forms[1] ?? '');
			} else {
				$texts[$key] = (string) $entry['msgstr'];
			}
		}

		return $texts;
	}

	/**
	 * Contenu du fichier source réduit aux clés demandées (en-tête conservé), prêt à être joint à l'archive.
	 *
	 * @param  list<string>  $keys
	 */
	public function export(string $archivePath, array $keys): string
	{
		$wanted = array_flip($keys);
		$selected = [];

		foreach ($this->parser->parse((string) file_get_contents($this->files()[$archivePath]['source'])) as $entry) {
			if ($this->isHeader($entry) || ($this->translatable($entry) && isset($wanted[$this->requestKey($entry)]))) {
				$selected[] = $entry;
			}
		}

		return $this->parser->render($selected);
	}

	/**
	 * Fusionne les textes traduits dans le fichier de la langue (jamais d'écrasement d'une valeur non vide), puis compile les
	 * .mo et .l10n.php. Rejouer la même fusion ne change rien.
	 *
	 * @param  array<string, string>  $texts
	 */
	public function merge(string $archivePath, string $locale, array $texts): MergeReport
	{
		$report = new MergeReport;
		$path = $this->path($archivePath, $locale);

		if ($path === null) {
			$report->errors[] = 'Fichier ou langue refusés : '.$archivePath.' / '.$locale;

			return $report;
		}

		try {
			$sourceEntries = $this->parser->parse((string) file_get_contents($this->files()[$archivePath]['source']));
			$entries = is_file($path) ? $this->parser->parse((string) file_get_contents($path)) : $this->blank($sourceEntries, $locale);
		} catch (Throwable $e) {
			$report->errors[] = $path.' : '.$e->getMessage();

			return $report;
		}

		$sourceByKey = [];
		foreach ($sourceEntries as $entry) {
			$this->translatable($entry) && $sourceByKey[$this->requestKey($entry)] = $entry;
		}

		$index = [];
		foreach ($entries as $i => $entry) {
			$this->translatable($entry) && $index[$this->requestKey($entry)] = $i;
		}

		$forms = $this->pluralCount($entries);

		foreach ($texts as $key => $text) {
			$key = (string) $key;
			$text = (string) $text;
			$plural = str_ends_with($key, self::PLURAL_SUFFIX);
			$base = $plural ? substr($key, 0, -strlen(self::PLURAL_SUFFIX)) : $key;
			$src = $sourceByKey[$base] ?? null;

			if ($src === null) {
				$report->rejected[] = [$path, $key, 'clé inconnue de la source'];

				continue;
			}

			if (trim($text) === '') {
				$report->rejected[] = [$path, $key, 'traduction vide'];

				continue;
			}

			$original = $plural ? (string) $src['msgid_plural'] : (string) $src['msgid'];

			if (! Placeholders::same($original, $text)) {
				$report->rejected[] = [$path, $key, 'marqueurs différents de la source'];

				continue;
			}

			if (! isset($index[$base])) {
				$entries[] = $this->cleared($src);
				$index[$base] = array_key_last($entries);
			}

			$entry = &$entries[$index[$base]];

			if ($plural || ! empty($entry['msgid_plural'])) {
				$entry['msgstr_plural'] = $entry['msgstr_plural'] + array_fill(0, $forms, '');
				$slots = $plural && $forms > 1 ? range(1, $forms - 1) : [0];
				$free = array_filter($slots, fn ($i) => trim((string) $entry['msgstr_plural'][$i]) === '');

				if ($free === []) {
					$report->kept++;
				} else {
					foreach ($free as $i) {
						$entry['msgstr_plural'][$i] = $text;
					}
					ksort($entry['msgstr_plural']);
					$report->merged++;
				}
			} elseif (trim((string) $entry['msgstr']) !== '') {
				$report->kept++;
			} else {
				$entry['msgstr'] = $text;
				$entry['comments'] = array_values(array_filter($entry['comments'] ?? [], fn ($c) => ! preg_match('/^#,\s*fuzzy\b/', $c)));
				$report->merged++;
			}

			unset($entry);
		}

		try {
			if ($report->merged > 0) {
				$this->writer->write($path, $this->parser->render($entries));
				$report->written[] = $path;
			}

			// Les .mo / .l10n.php sont recompilés à chaque fusion et s'ils manquent ou sont plus anciens que le .po
			if ($report->merged > 0 || $this->staleCompiled($path)) {
				$this->compile($path, $entries, $report);
			}
		} catch (HubException $e) {
			$report->errors[] = $e->getMessage();
			$report->merged = 0;
		}

		return $report;
	}

	/**
	 * Écrit le .mo et le .l10n.php à côté du .po.
	 *
	 * @param  list<array>  $entries
	 */
	private function compile(string $poPath, array $entries, MergeReport $report): void
	{
		$base = preg_replace('/\.po$/', '', $poPath);

		$this->writer->write($base.'.mo', $this->mo->mo($entries));
		$this->writer->write($base.'.l10n.php', $this->mo->l10n($entries));
		$report->written[] = $base.'.mo';
	}

	/**
	 * Vrai si le .po existe et que son .mo manque ou est plus ancien.
	 */
	private function staleCompiled(string $poPath): bool
	{
		$mo = preg_replace('/\.po$/', '.mo', $poPath);

		return is_file($poPath) && (! is_file($mo) || filemtime($mo) < filemtime($poPath));
	}

	/**
	 * Fichiers source du thème actif et du thème parent, trouvés une seule fois.
	 *
	 * @return array<string, array{dir: string, prefix: string, source: string}>
	 */
	private function files(): array
	{
		if ($this->files !== null) {
			return $this->files;
		}

		$files = [];
		$suffix = $this->source.'.po';

		foreach ($this->themes as $theme) {
			$dir = rtrim($theme['dir'], '/\\').'/languages';
			$candidates = [$dir.'/'.$theme['slug'].'-'.$suffix, $dir.'/'.$suffix];
			$found = null;

			foreach ($candidates as $candidate) {
				if (is_file($candidate)) {
					$found = $candidate;

					break;
				}
			}

			if ($found === null && is_dir($dir)) {
				$pots = glob($dir.'/*.pot') ?: [];
				sort($pots);
				$found = $pots[0] ?? null;
			}

			if ($found !== null) {
				$files['themes/'.$theme['slug'].'/languages/'.basename($found)] = ['dir' => $dir, 'prefix' => $this->prefix($found), 'source' => $found];
			}

			$global = rtrim($this->globalDir, '/\\').'/themes/'.$theme['slug'].'-'.$suffix;

			if (is_file($global)) {
				$files['languages/themes/'.basename($global)] = ['dir' => dirname($global), 'prefix' => $this->prefix($global), 'source' => $global];
			}
		}

		return $this->files = $files;
	}

	/**
	 * Début du nom des fichiers de langue : ce qui précède la langue source (« theme- », ou vide), ou le nom du .pot suivi de « - ».
	 */
	private function prefix(string $file): string
	{
		$name = basename($file);

		if (str_ends_with($name, '.pot')) {
			return substr($name, 0, -4).'-';
		}

		return substr($name, 0, -strlen($this->source.'.po'));
	}

	/**
	 * Chemin absolu du fichier d'une langue, ou null si le fichier ou la langue sont refusés.
	 */
	private function path(string $archivePath, string $locale): ?string
	{
		$file = $this->files()[$archivePath] ?? null;

		if ($file === null || preg_match(self::LOCALE, $locale) !== 1) {
			return null;
		}

		return $locale === $this->source && ! str_ends_with($file['source'], '.pot')
			? $file['source']
			: $file['dir'].'/'.$file['prefix'].$locale.'.po';
	}

	/**
	 * Clé d'une entrée : msgid, précédé du contexte et de 0x04.
	 */
	private function requestKey(array $entry): string
	{
		$context = (string) ($entry['msgctxt'] ?? '');

		return ($context !== '' ? $context."\x04" : '').(string) ($entry['msgid'] ?? '');
	}

	/**
	 * Vrai si l'entrée est une vraie entrée à traduire (ni en-tête, ni commentaire isolé, ni texte sans lettre).
	 */
	private function translatable(array $entry): bool
	{
		return empty($entry['raw']) && ! $this->isHeader($entry) && preg_match('/\p{L}/u', (string) ($entry['msgid'] ?? '')) === 1;
	}

	/**
	 * Vrai pour l'en-tête du fichier (msgid vide, sans contexte).
	 */
	private function isHeader(array $entry): bool
	{
		return empty($entry['raw']) && ($entry['msgid'] ?? '') === '' && empty($entry['msgctxt']);
	}

	/**
	 * Fichier cible neuf : en-tête de la source (langue mise à jour) et entrées sans traduction.
	 */
	private function blank(array $sourceEntries, string $locale): array
	{
		$entries = [];

		foreach ($sourceEntries as $entry) {
			if (! empty($entry['raw'])) {
				continue;
			}

			if ($this->isHeader($entry)) {
				$header = (string) $entry['msgstr'];
				$header = preg_match('/^Language:/m', $header) ? preg_replace('/^Language:.*$/m', 'Language: '.$locale, $header) : $header."Language: {$locale}\n";
				$header = preg_replace('/^Plural-Forms:.*\n?/m', '', $header);
				$plural = $this->pluralForms($locale);
				$entry['msgstr'] = $header.($plural !== null ? 'Plural-Forms: '.$plural."\n" : '');
				$entries[] = $entry;
			} elseif ($this->translatable($entry)) {
				$entries[] = $this->cleared($entry);
			}
		}

		return $entries;
	}

	/**
	 * Règle des formes plurielles d'une langue courante (null si elle n'est pas connue : WordPress prend alors deux formes).
	 */
	private function pluralForms(string $locale): ?string
	{
		$language = strtolower(preg_split('/[_-]/', $locale)[0]);

		return match (true) {
			in_array($language, ['ja', 'ko', 'zh', 'vi', 'th', 'id', 'ms'], true) => 'nplurals=1; plural=0;',
			$language === 'fr', $language === 'tr', $locale === 'pt_BR', $locale === 'pt-BR' => 'nplurals=2; plural=(n > 1);',
			in_array($language, ['en', 'de', 'es', 'it', 'pt', 'nl', 'sv', 'da', 'fi', 'el', 'nb', 'no', 'bg', 'hu', 'ca', 'et'], true) => 'nplurals=2; plural=(n != 1);',
			in_array($language, ['ru', 'uk'], true) => 'nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);',
			$language === 'pl' => 'nplurals=3; plural=(n==1 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);',
			$language === 'cs' => 'nplurals=3; plural=(n==1) ? 0 : (n>=2 && n<=4) ? 1 : 2;',
			default => null,
		};
	}

	/**
	 * Copie d'une entrée sans traduction.
	 */
	private function cleared(array $entry): array
	{
		$entry['msgstr'] = '';
		$entry['msgstr_plural'] = [];
		$entry['comments'] = array_values(array_filter($entry['comments'] ?? [], fn ($c) => ! preg_match('/^#,\s*fuzzy\b/', $c)));

		return $entry;
	}

	/**
	 * Nombre de formes plurielles de la langue, lu dans l'en-tête (2 à défaut).
	 */
	private function pluralCount(array $entries): int
	{
		foreach ($entries as $entry) {
			if ($this->isHeader($entry) && preg_match('/nplurals\s*=\s*(\d+)/', (string) $entry['msgstr'], $m)) {
				return max(1, (int) $m[1]);
			}
		}

		return 2;
	}
}
