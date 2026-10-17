<?php

namespace Torskint\LinguaFlowWp\Tests;

use PHPUnit\Framework\TestCase;
use Torskint\LinguaFlowWp\Settings;
use Torskint\LinguaFlowWp\Support\Crypto;
use Torskint\LinguaFlowWp\Support\MoWriter;
use Torskint\LinguaFlowWp\Support\PoParser;

/**
 * Réglages (contrôle, chiffrement des secrets) et textes d'interface (toutes les clés utilisées existent en français).
 */
class SettingsTest extends TestCase
{
	/**
	 * Les secrets sont chiffrés à l'enregistrement, relus en clair, et conservés quand le champ est laissé vide.
	 */
	public function test_secrets_are_encrypted_and_kept_when_blank(): void
	{
		$crypto = new Crypto('sels');
		$first = (new Settings([], $crypto))->sanitize(['hub_url' => 'https://hub.example.com/', 'api_key' => 'sk_live_1', 'webhook_secret' => 'whsec_1', 'source_locale' => 'fr_FR', 'locales' => ['de_DE']]);

		$this->assertSame([], $first['errors']);
		$this->assertSame('https://hub.example.com', $first['values']['hub_url']);
		$this->assertStringNotContainsString('sk_live_1', json_encode($first['values']));

		$settings = new Settings($first['values'], $crypto);
		$this->assertSame('sk_live_1', $settings->get('api_key'));
		$this->assertTrue($settings->configured());

		$second = $settings->sanitize(['hub_url' => 'https://hub.example.com', 'api_key' => '', 'webhook_secret' => '', 'source_locale' => 'fr_FR']);
		$this->assertSame('sk_live_1', (new Settings($second['values'], $crypto))->get('api_key'));
	}

	/**
	 * Adresse du hub non sécurisée, langue source invalide : refus avec le numéro du texte ; langues cibles nettoyées.
	 */
	public function test_validation_and_locales(): void
	{
		$settings = new Settings([], new Crypto('sels'));

		$this->assertSame([43], $settings->sanitize(['hub_url' => 'http://hub.example.com', 'source_locale' => 'fr_FR'])['errors']);
		$this->assertSame([], $settings->sanitize(['hub_url' => 'http://localhost:8000', 'source_locale' => 'fr_FR'])['errors']);
		$this->assertSame([44], $settings->sanitize(['hub_url' => '', 'source_locale' => '../x'])['errors']);

		$out = $settings->sanitize(['source_locale' => 'fr_FR', 'locales' => ['de_DE', 'fr_FR', '../etc'], 'other_locales' => 'ja, de_DE; pt-BR  <b>'])['values'];
		$this->assertSame(['de_DE', 'ja', 'pt-BR'], $out['locales']);
	}

	/**
	 * Toute clé TRAD_n utilisée dans le code existe dans le fichier français compilé, et le .mo est à jour avec le .po.
	 */
	public function test_every_used_text_exists_in_french(): void
	{
		$po = dirname(__DIR__).'/languages/linguaflow-for-wordpress-fr_FR.po';
		$parser = new PoParser;
		$defined = [];

		foreach ($parser->parse((string) file_get_contents($po)) as $entry) {
			if (str_starts_with((string) $entry['msgid'], 'TRAD_')) {
				$this->assertNotSame('', trim((string) $entry['msgstr']), $entry['msgid'].' sans valeur');
				$defined[$entry['msgid']] = true;
			}
		}

		$used = [];

		foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__).'/src')) as $file) {
			if ($file->getExtension() === 'php') {
				preg_match_all('/Lang::t\((\d+)/', (string) file_get_contents($file->getPathname()), $m);
				preg_match_all('/\$errors\[\] = (\d+);/', (string) file_get_contents($file->getPathname()), $e);
				preg_match_all('/STATUS_TEXT = \[([^\]]+)\]/', (string) file_get_contents($file->getPathname()), $s);
				preg_match_all('/=> (\d+)/', implode(',', $s[1]), $codes);
				$used = array_merge($used, $m[1], $e[1], $codes[1]);
			}
		}

		// Textes passés par variable (en-têtes, boutons) : relevés dans l'écran d'administration
		$admin = (string) file_get_contents(dirname(__DIR__).'/src/Admin/AdminPage.php');
		preg_match_all('/\[((?:\d+,\s*)+\d+)\] as \$heading/', $admin, $h);
		preg_match_all('/\'(?:local|webhook|send|sync)\' => (\d+)/', $admin, $b);
		$used = array_merge($used, $b[1], ...array_map(fn ($list) => preg_split('/,\s*/', $list), $h[1]));

		$this->assertNotSame([], $used);

		foreach (array_unique($used) as $id) {
			$this->assertArrayHasKey('TRAD_'.$id, $defined, 'TRAD_'.$id.' manque dans le fichier français');
		}

		$compiled = (new MoWriter)->messages($parser->parse((string) file_get_contents($po)));
		$this->assertSame($compiled, (new MoWriter)->messages($this->readMoEntries(dirname($po).'/linguaflow-for-wordpress-fr_FR.mo')), 'Le .mo n\'est pas à jour : php tools/build-mo.php');
	}

	/**
	 * Relit un .mo sous forme d'entrées minimales (msgid, msgstr) pour comparer avec le .po.
	 *
	 * @return list<array>
	 */
	private function readMoEntries(string $path): array
	{
		$binary = (string) file_get_contents($path);
		$head = unpack('Vmagic/Vrev/Vcount/VidTable/VstrTable', $binary);
		$entries = [];

		for ($i = 0; $i < $head['count']; $i++) {
			$id = unpack('Vlen/Voff', substr($binary, $head['idTable'] + $i * 8, 8));
			$str = unpack('Vlen/Voff', substr($binary, $head['strTable'] + $i * 8, 8));
			$entries[] = ['msgid' => substr($binary, $id['off'], $id['len']), 'msgstr' => substr($binary, $str['off'], $str['len']), 'comments' => []];
		}

		return $entries;
	}
}
