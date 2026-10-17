<?php

namespace Torskint\LinguaFlowWp;

use Throwable;
use Torskint\LinguaFlowWp\Admin\AdminPage;
use Torskint\LinguaFlowWp\Archive\ArchiveBuilder;
use Torskint\LinguaFlowWp\Delta\DeltaDetector;
use Torskint\LinguaFlowWp\Http\HubClient;
use Torskint\LinguaFlowWp\Http\WpHubClient;
use Torskint\LinguaFlowWp\Merge\ResultMerger;
use Torskint\LinguaFlowWp\Rest\WebhookRoute;
use Torskint\LinguaFlowWp\Source\ThemeSource;
use Torskint\LinguaFlowWp\Store\JobStore;
use Torskint\LinguaFlowWp\Store\WpdbJobStore;
use Torskint\LinguaFlowWp\Support\Crypto;
use Torskint\LinguaFlowWp\Support\FileWriter;
use Torskint\LinguaFlowWp\Support\Lang;
use Torskint\LinguaFlowWp\Sync\Sender;
use Torskint\LinguaFlowWp\Sync\Syncer;

/**
 * Point d'entrée du plugin : branche les actions WordPress (cron, REST, administration) et assemble les objets du cycle d'envoi.
 */
class Plugin
{
	/** Nom de l'option qui porte les réglages. */
	public const OPTION = 'linguaflow_wp_settings';

	/** Action cron de la synchronisation périodique. */
	public const CRON_SYNC = 'linguaflow_wp_sync';

	/** Action cron de la synchronisation d'un seul travail (après un webhook). */
	public const CRON_ONE = 'linguaflow_wp_sync_one';

	/** Intervalle de la synchronisation périodique (secondes). */
	public const INTERVAL = 300;

	// Constructeur : chemin et adresse du plugin.
	public function __construct(private string $file) {}

	/**
	 * Branche toutes les actions du plugin.
	 */
	public function boot(): void
	{
		register_activation_hook($this->file, [$this, 'activate']);
		register_deactivation_hook($this->file, [$this, 'deactivate']);

		add_action('plugins_loaded', [$this, 'loadTexts']);
		add_filter('cron_schedules', [$this, 'schedules']);
		add_action(self::CRON_SYNC, [$this, 'cronSync']);
		add_action(self::CRON_ONE, [$this, 'cronSyncOne']);
		add_action('rest_api_init', [new WebhookRoute($this), 'register']);

		if (is_admin()) {
			(new AdminPage($this))->register();
		}
	}

	/**
	 * Activation : crée la table des envois et programme la synchronisation périodique.
	 */
	public function activate(): void
	{
		$this->store()->install();

		if (! wp_next_scheduled(self::CRON_SYNC)) {
			wp_schedule_event(time() + 60, 'linguaflow_wp_interval', self::CRON_SYNC);
		}
	}

	/**
	 * Désactivation : retire les tâches planifiées (la table et les réglages restent).
	 */
	public function deactivate(): void
	{
		wp_clear_scheduled_hook(self::CRON_SYNC);
		wp_clear_scheduled_hook(self::CRON_ONE);
	}

	/**
	 * Charge toujours les textes français du plugin, quelle que soit la langue du site.
	 */
	public function loadTexts(): void
	{
		load_textdomain(Lang::DOMAIN, plugin_dir_path($this->file).'languages/'.Lang::DOMAIN.'-fr_FR.mo');
	}

	/**
	 * Ajoute l'intervalle de la synchronisation au planificateur de WordPress.
	 */
	public function schedules(array $schedules): array
	{
		$schedules['linguaflow_wp_interval'] = ['interval' => self::INTERVAL, 'display' => 'Linguaflow'];

		return $schedules;
	}

	/**
	 * Tâche cron : traite les envois dont l'heure est venue (un verrou évite deux passages en même temps).
	 */
	public function cronSync(): void
	{
		$this->locked(fn () => $this->syncer()->run());
	}

	/**
	 * Tâche cron : traite tout de suite un envoi (appelée après un webhook).
	 */
	public function cronSyncOne(string $reference): void
	{
		$this->locked(fn () => $this->syncer()->run($reference));
	}

	/**
	 * Exécute un traitement sous verrou ; une erreur ne doit jamais casser le cron de WordPress.
	 */
	private function locked(callable $work): void
	{
		if (get_transient('linguaflow_wp_lock')) {
			return;
		}

		set_transient('linguaflow_wp_lock', 1, 300);

		try {
			$work();
		} catch (Throwable $e) {
			error_log('[linguaflow-for-wordpress] '.$e->getMessage());
		} finally {
			delete_transient('linguaflow_wp_lock');
		}
	}

	/**
	 * Réglages courants.
	 */
	public function settings(): Settings
	{
		return new Settings((array) get_option(self::OPTION, []), $this->crypto());
	}

	/**
	 * Enregistre des réglages déjà contrôlés.
	 */
	public function saveSettings(array $values): void
	{
		update_option(self::OPTION, $values, false);
	}

	/**
	 * Chiffrement des secrets, avec les sels de WordPress.
	 */
	public function crypto(): Crypto
	{
		return new Crypto(wp_salt('auth').wp_salt('secure_auth'));
	}

	/**
	 * Suivi local des envois.
	 */
	public function store(): WpdbJobStore
	{
		global $wpdb;

		return new WpdbJobStore($wpdb);
	}

	/**
	 * Client du hub avec les réglages courants.
	 */
	public function hub(): HubClient
	{
		$settings = $this->settings();

		return new WpHubClient((string) $settings->get('hub_url'), (string) $settings->get('api_key'));
	}

	/**
	 * Fichiers de langue du thème actif (et du thème parent si c'est un thème enfant).
	 */
	public function theme(): ThemeSource
	{
		$themes = [['slug' => get_stylesheet(), 'dir' => get_stylesheet_directory()]];

		if (get_template() !== get_stylesheet()) {
			$themes[] = ['slug' => get_template(), 'dir' => get_template_directory()];
		}

		return new ThemeSource($themes, WP_LANG_DIR, (string) $this->settings()->get('source_locale'), new FileWriter($this->workDir('backups')));
	}

	/**
	 * Dossier de travail du plugin (boîte d'envoi, sauvegardes), fermé à l'accès web.
	 */
	public function workDir(string $name): string
	{
		$base = wp_upload_dir()['basedir'].'/linguaflow-for-wordpress';
		$dir = $base.'/'.$name;

		if (! is_dir($dir)) {
			wp_mkdir_p($dir);
			@file_put_contents($base.'/index.php', "<?php\n// Silence.\n");
			@file_put_contents($base.'/.htaccess', "Require all denied\nDeny from all\n");
		}

		return $dir;
	}

	/**
	 * Adresse du webhook à donner au hub.
	 */
	public function webhookUrl(): string
	{
		return rest_url('linguaflow/v1/webhook');
	}

	/**
	 * Émetteur des envois, avec les réglages courants.
	 */
	public function sender(?HubClient $hub = null, ?JobStore $store = null): Sender
	{
		$settings = $this->settings();
		$theme = $this->theme();
		$source = (string) $settings->get('source_locale');

		return new Sender(new DeltaDetector($theme, $source), new ArchiveBuilder($theme), $hub ?? $this->hub(), $store ?? $this->store(), [
			'client_ref' => wp_parse_url(home_url(), PHP_URL_HOST) ?: null,
			'delivery' => (string) $settings->get('delivery'),
			'webhook_url' => $settings->get('delivery') === 'webhook' ? $this->webhookUrl() : null,
			'source' => $source,
			'locales' => (array) $settings->get('locales'),
			'outbox' => $this->workDir('outbox'),
			'interval' => self::INTERVAL,
		]);
	}

	/**
	 * Synchroniseur des envois, avec les réglages courants.
	 */
	public function syncer(): Syncer
	{
		$hub = $this->hub();
		$store = $this->store();

		return new Syncer($hub, $store, new ResultMerger($this->theme()), $this->sender($hub, $store), ['interval' => self::INTERVAL, 'max_interval' => 3600, 'max_age_days' => 14]);
	}
}
