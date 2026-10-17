<?php

namespace Torskint\LinguaFlowWp\Tests;

use PHPUnit\Framework\TestCase;
use Torskint\LinguaFlowWp\Source\ThemeSource;
use Torskint\LinguaFlowWp\Support\MoWriter;
use Torskint\LinguaFlowWp\Support\PoParser;
use Torskint\LinguaFlowWp\Sync\Sender;
use Torskint\LinguaFlowWp\Sync\Syncer;
use Torskint\LinguaFlowWp\Tests\Support\FakeHub;
use Torskint\LinguaFlowWp\Tests\Support\MemoryJobStore;
use Torskint\LinguaFlowWp\Tests\Support\ThemeFixture;
use ZipArchive;

/**
 * Cycle complet du plugin sur un thème de test : delta, archive, envoi, synchronisation, fusion sans écrasement, .mo.
 */
class ThemeFlowTest extends TestCase
{
	use ThemeFixture;

	/** Clés du thème de test. */
	private const CTX = "bouton\x04Voir";

	/**
	 * Nettoie le dossier temporaire après chaque test.
	 */
	protected function tearDown(): void
	{
		$this->cleanTheme();
	}

	/**
	 * Le delta liste les trois entrées pour une langue sans fichier, et rien pour la langue source.
	 */
	public function test_delta_lists_missing_keys(): void
	{
		$delta = $this->detector($this->makeTheme())->detect(['fr_FR', 'de_DE']);

		$this->assertSame(['de_DE'], $delta->locales());
		$this->assertSame(['de_DE' => [$this->file => ['Ajouter au panier', self::CTX, '%d article']]], $delta->targets());
		$this->assertSame(3, $delta->count());
	}

	/**
	 * L'archive porte le manifeste WordPress et le .po réduit aux clés demandées.
	 */
	public function test_archive_has_wordpress_manifest_and_trimmed_po(): void
	{
		$theme = $this->makeTheme();
		$delta = $this->detector($theme)->detect(['de_DE']);
		$delta->items[0]['keys'] = ['Ajouter au panier'];

		$built = $this->archive($theme)->build($delta, 'fr_FR', ['client_ref' => 'site-1', 'delivery' => 'local', 'webhook_url' => null, 'full' => false]);
		$zip = new ZipArchive;
		$zip->open($built['path']);
		$manifest = json_decode($zip->getFromName('.linguaflow/manifest.json'), true);
		$po = $zip->getFromName($this->file);
		$zip->close();

		$this->assertSame('wordpress', $manifest['framework']);
		$this->assertSame('fr_FR', $manifest['source_locale']);
		$this->assertSame(['de_DE' => [$this->file => ['Ajouter au panier']]], $manifest['targets']);
		$this->assertStringContainsString('Ajouter au panier', $po);
		$this->assertStringNotContainsString('"Voir"', $po);
		$this->assertStringContainsString('Language: fr_FR', $po);
	}

	/**
	 * La fusion crée le .po de la langue, compile le .mo et le .l10n.php, et n'écrase jamais une valeur existante.
	 */
	public function test_merge_writes_po_mo_and_never_overwrites(): void
	{
		$theme = $this->makeTheme();
		$texts = ['Ajouter au panier' => 'In den Warenkorb', self::CTX => 'Ansehen', '%d article' => '%d Artikel', '%d article'.ThemeSource::PLURAL_SUFFIX => '%d Artikel'];

		$report = $theme->merge($this->file, 'de_DE', $texts);

		$dir = $this->root.'/themes/mytheme/languages/';
		$this->assertSame(4, $report->merged);
		$this->assertFileExists($dir.'mytheme-de_DE.po');
		$this->assertFileExists($dir.'mytheme-de_DE.mo');
		$this->assertFileExists($dir.'mytheme-de_DE.l10n.php');
		$this->assertStringContainsString('Language: de_DE', file_get_contents($dir.'mytheme-de_DE.po'));

		// Seconde fusion avec d'autres textes : rien n'est écrasé
		$again = $theme->merge($this->file, 'de_DE', ['Ajouter au panier' => 'Autre texte']);
		$this->assertSame(0, $again->merged);
		$this->assertSame(1, $again->kept);
		$this->assertStringContainsString('In den Warenkorb', file_get_contents($dir.'mytheme-de_DE.po'));

		// Le .mo contient bien les traductions
		$mo = $this->readMo(file_get_contents($dir.'mytheme-de_DE.mo'));
		$this->assertSame('In den Warenkorb', $mo['Ajouter au panier']);
		$this->assertSame('Ansehen', $mo[self::CTX]);
		$this->assertSame("%d Artikel\0%d Artikel", $mo["%d article\0%d articles"]);

		$l10n = include $dir.'mytheme-de_DE.l10n.php';
		$this->assertSame('In den Warenkorb', $l10n['messages']['Ajouter au panier']);
	}

	/**
	 * Une traduction vide, aux marqueurs différents, d'une clé inconnue ou d'une langue dangereuse est refusée.
	 */
	public function test_bad_translations_and_paths_are_rejected(): void
	{
		$theme = $this->makeTheme();

		$report = $theme->merge($this->file, 'de_DE', ['Ajouter au panier' => '  ', '%d article' => 'Artikel', 'Inconnu' => 'Unbekannt']);
		$this->assertSame(0, $report->merged);
		$this->assertCount(3, $report->rejected);

		$report = $theme->merge($this->file, '../../evil', ['Ajouter au panier' => 'x']);
		$this->assertNotSame([], $report->errors);
		$this->assertFileDoesNotExist($this->root.'/themes/evil.po');

		$report = $theme->merge('themes/autre/languages/x.po', 'de_DE', ['a' => 'b']);
		$this->assertNotSame([], $report->errors);
	}

	/**
	 * Cycle complet : envoi, interrogation tant que le hub travaille, fusion à la fin, confirmation, travail terminé.
	 */
	public function test_full_cycle_send_sync_merge_ack(): void
	{
		$theme = $this->makeTheme();
		$hub = new FakeHub;
		$store = new MemoryJobStore;
		$sender = new Sender($this->detector($theme), $this->archive($theme), $hub, $store, [
			'client_ref' => 'site-1', 'delivery' => 'local', 'webhook_url' => null, 'source' => 'fr_FR', 'locales' => ['de_DE'],
			'outbox' => $this->root.'/outbox', 'interval' => 60,
		]);
		$syncer = new Syncer($hub, $store, $this->merger($theme), $sender, ['interval' => 60, 'max_interval' => 3600, 'max_age_days' => 14]);

		$sent = $sender->send();
		$this->assertSame('sent', $sent['outcome']);
		$this->assertCount(1, $hub->received);

		// Même contenu déjà en cours : pas de second envoi
		$this->assertSame('duplicate', $sender->send()['outcome']);

		// Le hub travaille encore : on attend
		$store->update(1, ['next_poll_at' => 0]);
		$this->assertSame('waiting', $syncer->run()[0]['state']);

		// Terminé : fusion + confirmation
		$hub->status = 'completed';
		$hub->translations = ['de_DE' => [$this->file => ['Ajouter au panier' => 'In den Warenkorb', self::CTX => 'Ansehen', '%d article' => '%d Artikel', '%d article'.ThemeSource::PLURAL_SUFFIX => '%d Artikel']]];
		$store->update(1, ['next_poll_at' => 0]);

		$this->assertSame('merged', $syncer->run()[0]['state']);
		$this->assertSame('merged', $store->find(1)['status']);
		$this->assertSame(1, $hub->acks);
		$this->assertSame(4, $store->find(1)['report']['merged']);

		// Plus rien à traduire
		$this->assertSame('nothing', $sender->send()['outcome']);
	}

	/**
	 * Hub injoignable : l'archive reste en boîte d'envoi, puis part avec la même clé d'idempotence au passage suivant.
	 */
	public function test_unreachable_hub_queues_then_retries(): void
	{
		$theme = $this->makeTheme();
		$hub = new FakeHub;
		$hub->down = true;
		$store = new MemoryJobStore;
		$sender = new Sender($this->detector($theme), $this->archive($theme), $hub, $store, [
			'client_ref' => null, 'delivery' => 'local', 'webhook_url' => null, 'source' => 'fr_FR', 'locales' => ['de_DE'],
			'outbox' => $this->root.'/outbox', 'interval' => 60,
		]);
		$syncer = new Syncer($hub, $store, $this->merger($theme), $sender, ['interval' => 60, 'max_interval' => 3600, 'max_age_days' => 14]);

		$this->assertSame('queued', $sender->send()['outcome']);
		$this->assertSame('new', $store->find(1)['status']);
		$this->assertFileExists($store->find(1)['archive_path']);

		$hub->down = false;
		$store->update(1, ['next_poll_at' => 0]);
		$this->assertSame('sent', $syncer->run()[0]['state']);
		$this->assertSame('sent', $store->find(1)['status']);
		$this->assertArrayHasKey($store->find(1)['uuid'], $hub->received);
	}

	/**
	 * Le .po reconstruit après fusion se relit sans perte (ordre, contexte, pluriel).
	 */
	public function test_merged_po_round_trips(): void
	{
		$theme = $this->makeTheme();
		$theme->merge($this->file, 'es_ES', ['Ajouter au panier' => 'Añadir al carrito']);

		$entries = (new PoParser)->parse(file_get_contents($this->root.'/themes/mytheme/languages/mytheme-es_ES.po'));
		$messages = (new MoWriter)->messages($entries);

		$this->assertSame('Añadir al carrito', $messages['Ajouter au panier']);
		$this->assertArrayNotHasKey(self::CTX, $messages);
	}

	/**
	 * Lit un .mo (petit-boutiste) : clé gettext => traduction.
	 *
	 * @return array<string, string>
	 */
	private function readMo(string $binary): array
	{
		$head = unpack('Vmagic/Vrev/Vcount/VidTable/VstrTable', $binary);
		$this->assertSame(0x950412de, $head['magic']);
		$out = [];

		for ($i = 0; $i < $head['count']; $i++) {
			$id = unpack('Vlen/Voff', substr($binary, $head['idTable'] + $i * 8, 8));
			$str = unpack('Vlen/Voff', substr($binary, $head['strTable'] + $i * 8, 8));
			$out[substr($binary, $id['off'], $id['len'])] = substr($binary, $str['off'], $str['len']);
		}

		return $out;
	}

	/**
	 * Un nouveau fichier de langue reçoit la règle de pluriel de sa langue (le .mo et le .l10n.php la reprennent).
	 */
	public function test_new_file_gets_plural_rule(): void
	{
		$theme = $this->makeTheme();
		$theme->merge($this->file, 'ru_RU', ['%d article' => '%d товар', '%d article'.ThemeSource::PLURAL_SUFFIX => '%d товаров']);
		$theme->merge($this->file, 'ja', ['Ajouter au panier' => 'カートに追加']);

		$dir = $this->root.'/themes/mytheme/languages/';
		$this->assertStringContainsString('nplurals=3', file_get_contents($dir.'mytheme-ru_RU.po'));
		$this->assertStringContainsString('nplurals=1; plural=0;', file_get_contents($dir.'mytheme-ja.po'));
		$this->assertSame('nplurals=1; plural=0;', (include $dir.'mytheme-ja.l10n.php')['plural-forms']);
	}
}
