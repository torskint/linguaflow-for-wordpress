<?php

namespace Torskint\LinguaFlowWp\Admin;

use Throwable;
use Torskint\LinguaFlowWp\Plugin;
use Torskint\LinguaFlowWp\Settings;
use Torskint\LinguaFlowWp\Store\JobStore;
use Torskint\LinguaFlowWp\Support\Lang;

/**
 * Écran d'administration (Outils → Linguaflow) : réglages, envoi des textes manquants, synchronisation et liste des envois.
 * Toutes les actions passent par admin-post.php (nonce + droit `manage_options`) ; aucun texte n'est écrit en dur.
 */
class AdminPage
{
	/** Identifiant de l'écran. */
	public const SLUG = 'linguaflow-for-wordpress';

	/** Clé des textes de statut d'un envoi. */
	private const STATUS_TEXT = ['new' => 30, 'sent' => 31, 'merged' => 32, 'failed' => 33, 'cancelled' => 34];

	// Constructeur : plugin (réglages, envois).
	public function __construct(private Plugin $plugin) {}

	/**
	 * Branche l'écran, ses actions et ses fichiers CSS/JS.
	 */
	public function register(): void
	{
		add_action('admin_menu', [$this, 'menu']);
		add_action('admin_enqueue_scripts', [$this, 'assets']);

		foreach (['save', 'send', 'sync', 'cancel'] as $action) {
			add_action('admin_post_linguaflow_wp_'.$action, [$this, $action]);
		}

		add_action('wp_ajax_linguaflow_wp_test', [$this, 'test']);
	}

	/**
	 * Ajoute l'écran au menu Outils.
	 */
	public function menu(): void
	{
		add_management_page(Lang::t(1), Lang::t(1), 'manage_options', self::SLUG, [$this, 'render']);
	}

	/**
	 * Charge le CSS et le JS sur l'écran du plugin seulement.
	 */
	public function assets(string $hook): void
	{
		if ($hook !== 'tools_page_'.self::SLUG) {
			return;
		}

		$version = '1.0.0';
		$file = $this->plugin_file();
		wp_enqueue_style(self::SLUG, plugins_url('assets/admin.css', $file), [], $version);
		wp_enqueue_script(self::SLUG, plugins_url('assets/admin.js', $file), [], $version, true);
	}

	/**
	 * Affiche l'écran.
	 */
	public function render(): void
	{
		if (! current_user_can('manage_options')) {
			wp_die(esc_html(Lang::t(45)));
		}

		$settings = $this->plugin->settings();
		$jobs = $this->plugin->store()->all(30);
		$notice = get_transient($this->noticeKey());
		delete_transient($this->noticeKey());

		echo '<div class="wrap lfw">';
		echo '<h1>'.esc_html(Lang::t(1)).'</h1>';

		if (is_array($notice)) {
			echo '<div class="notice notice-'.esc_attr($notice['type']).' is-dismissible"><p>'.esc_html($notice['text']).'</p></div>';
		}

		echo '<p class="lfw-theme">'.esc_html(Lang::t(22, wp_get_theme()->get('Name'))).'</p>';
		$this->renderSettings($settings);
		$this->renderActions($settings);
		$this->renderJobs($jobs);
		echo '</div>';
	}

	/**
	 * Formulaire des réglages.
	 */
	private function renderSettings(Settings $settings): void
	{
		$selected = (array) $settings->get('locales');
		$custom = array_diff($selected, Settings::COMMON_LOCALES);
		$delivery = (string) $settings->get('delivery');
		$hasKey = $settings->get('api_key') !== '';
		$hasSecret = $settings->get('webhook_secret') !== '';

		echo '<form class="lfw-card" method="post" action="'.esc_url(admin_url('admin-post.php')).'" novalidate autocomplete="off" data-lfw-form>';
		echo '<input type="hidden" name="action" value="linguaflow_wp_save">';
		wp_nonce_field('linguaflow_wp_save');
		echo '<h2>'.esc_html(Lang::t(2)).'</h2>';

		echo '<label class="lfw-field"><span>'.esc_html(Lang::t(3)).'</span>';
		echo '<input type="url" name="hub_url" value="'.esc_attr((string) $settings->get('hub_url')).'" autocomplete="off"></label>';

		echo '<label class="lfw-field"><span>'.esc_html(Lang::t(4)).'</span>';
		echo '<input type="password" name="api_key" value="" autocomplete="new-password" placeholder="'.esc_attr($hasKey ? Lang::t(41) : '').'"></label>';

		echo '<fieldset class="lfw-field"><legend>'.esc_html(Lang::t(6)).'</legend><div class="lfw-choices">';
		foreach (['local' => 7, 'webhook' => 8] as $mode => $label) {
			echo '<label class="lfw-choice"><input type="radio" name="delivery" value="'.esc_attr($mode).'"'.checked($delivery, $mode, false).'><span>'.esc_html(Lang::t($label)).'</span></label>';
		}
		echo '</div></fieldset>';

		echo '<label class="lfw-field"><span>'.esc_html(Lang::t(5)).'</span>';
		echo '<input type="password" name="webhook_secret" value="" autocomplete="new-password" placeholder="'.esc_attr($hasSecret ? Lang::t(41) : '').'"></label>';
		echo '<p class="description">'.esc_html(Lang::t(40, $this->plugin->webhookUrl())).'</p>';

		echo '<label class="lfw-field"><span>'.esc_html(Lang::t(23)).'</span>';
		echo '<input type="text" name="source_locale" value="'.esc_attr((string) $settings->get('source_locale')).'" autocomplete="off"></label>';

		echo '<fieldset class="lfw-field"><legend>'.esc_html(Lang::t(9)).'</legend><div class="lfw-chips">';
		foreach (Settings::COMMON_LOCALES as $code) {
			echo '<label class="lfw-chip"><input type="checkbox" name="locales[]" value="'.esc_attr($code).'"'.checked(in_array($code, $selected, true), true, false).'><span>'.esc_html($this->localeName($code)).'</span></label>';
		}
		echo '</div></fieldset>';

		echo '<label class="lfw-field"><span>'.esc_html(Lang::t(24)).'</span>';
		echo '<input type="text" name="other_locales" value="'.esc_attr(implode(', ', $custom)).'" autocomplete="off"></label>';

		echo '<div class="lfw-row">';
		echo '<button type="submit" class="button button-primary" data-lfw-lock><span class="lfw-spinner" aria-hidden="true"></span>'.esc_html(Lang::t(21)).'</button>';
		echo '<button type="button" class="button" data-lfw-test data-url="'.esc_url(admin_url('admin-ajax.php')).'" data-nonce="'.esc_attr(wp_create_nonce('linguaflow_wp_test')).'"><span class="lfw-spinner" aria-hidden="true"></span>'.esc_html(Lang::t(25)).'</button>';
		echo '<span class="lfw-test-result" data-lfw-test-result role="status"></span>';
		echo '</div></form>';
	}

	/**
	 * Boutons d'envoi et de synchronisation.
	 */
	private function renderActions(Settings $settings): void
	{
		echo '<div class="lfw-card lfw-row">';

		foreach (['send' => 10, 'sync' => 11] as $action => $label) {
			echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" novalidate autocomplete="off" data-lfw-form>';
			echo '<input type="hidden" name="action" value="linguaflow_wp_'.esc_attr($action).'">';
			wp_nonce_field('linguaflow_wp_'.$action);
			echo '<button type="submit" class="button'.($action === 'send' ? ' button-primary' : '').'" data-lfw-lock'.($settings->configured() ? '' : ' disabled').'>';
			echo '<span class="lfw-spinner" aria-hidden="true"></span>'.esc_html(Lang::t($label)).'</button></form>';
		}

		echo '</div>';
	}

	/**
	 * Tableau des envois.
	 *
	 * @param  list<array>  $jobs
	 */
	private function renderJobs(array $jobs): void
	{
		echo '<div class="lfw-card"><h2>'.esc_html(Lang::t(12)).'</h2>';

		if ($jobs === []) {
			echo '<p>'.esc_html(Lang::t(27)).'</p></div>';

			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		foreach ([13, 14, 28, 29, 15, 46, 16] as $heading) {
			echo '<th>'.esc_html(Lang::t($heading)).'</th>';
		}
		echo '</tr></thead><tbody>';

		$format = get_option('date_format').' '.get_option('time_format');

		foreach ($jobs as $job) {
			$final = in_array($job['status'], JobStore::FINAL, true);
			echo '<tr>';
			echo '<td>'.esc_html((string) ($job['reference'] ?? '—')).'</td>';
			echo '<td>'.esc_html(Lang::t(self::STATUS_TEXT[$job['status']] ?? 31)).'</td>';
			echo '<td>'.esc_html(implode(', ', $job['locales'])).'</td>';
			echo '<td>'.esc_html(number_format_i18n((int) $job['keys_count'])).'</td>';
			echo '<td>'.esc_html($job['sent_at'] ? wp_date($format, (int) $job['sent_at']) : '—').'</td>';
			echo '<td>'.esc_html((string) ($job['error'] ?? '')).'</td>';
			echo '<td>';

			if (! $final) {
				echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" novalidate autocomplete="off" data-lfw-form>';
				echo '<input type="hidden" name="action" value="linguaflow_wp_cancel"><input type="hidden" name="job" value="'.esc_attr((string) $job['id']).'">';
				wp_nonce_field('linguaflow_wp_cancel');
				echo '<button type="submit" class="button button-small" data-lfw-lock><span class="lfw-spinner" aria-hidden="true"></span>'.esc_html(Lang::t(16)).'</button></form>';
			}

			echo '</td></tr>';
		}

		echo '</tbody></table></div>';
	}

	/**
	 * Enregistre les réglages.
	 */
	public function save(): void
	{
		$this->guard('linguaflow_wp_save');
		$result = $this->plugin->settings()->sanitize(wp_unslash($_POST));

		if ($result['errors'] !== []) {
			$this->back('error', Lang::t($result['errors'][0]));
		}

		$this->plugin->saveSettings($result['values']);
		$this->back('success', Lang::t(26));
	}

	/**
	 * Envoie les textes manquants au hub.
	 */
	public function send(): void
	{
		$this->guard('linguaflow_wp_send');
		$settings = $this->plugin->settings();

		if (! $settings->configured()) {
			$this->back('error', Lang::t(47));
		}

		if ((array) $settings->get('locales') === []) {
			$this->back('error', Lang::t(48));
		}

		if ($this->plugin->theme()->archivePaths() === []) {
			$this->back('error', Lang::t(39));
		}

		try {
			$sent = $this->plugin->sender()->send();
		} catch (Throwable $e) {
			$this->back('error', $e->getMessage());
		}

		$reference = (string) ($sent['job']['reference'] ?? '');

		match ($sent['outcome']) {
			'sent' => $this->back('success', Lang::t(18, $reference)),
			'queued' => $this->back('warning', Lang::t(37, (string) $sent['error'])),
			'duplicate' => $this->back('info', Lang::t(38, $reference)),
			default => $this->back('info', Lang::t(17)),
		};
	}

	/**
	 * Interroge le hub tout de suite et fusionne les résultats prêts.
	 */
	public function sync(): void
	{
		$this->guard('linguaflow_wp_sync');

		if (! $this->plugin->settings()->configured()) {
			$this->back('error', Lang::t(47));
		}

		// Chaque envoi non terminé est traité sans attendre son heure
		$syncer = $this->plugin->syncer();
		$count = 0;

		foreach ($this->plugin->store()->all(200) as $job) {
			if (! in_array($job['status'], JobStore::FINAL, true)) {
				$syncer->process($job);
				$count++;
			}
		}

		$this->back('success', Lang::t(35, $count));
	}

	/**
	 * Annule un envoi non terminé.
	 */
	public function cancel(): void
	{
		$this->guard('linguaflow_wp_cancel');
		$store = $this->plugin->store();
		$job = $store->find((int) ($_POST['job'] ?? 0));

		if ($job !== null && ! in_array($job['status'], JobStore::FINAL, true)) {
			try {
				if ($job['reference']) {
					$this->plugin->hub()->cancel((string) $job['reference']);
				}

				$store->update($job['id'], ['status' => 'cancelled', 'error' => null]);
				$job['archive_path'] && @unlink((string) $job['archive_path']);
			} catch (Throwable $e) {
				$this->back('error', $e->getMessage());
			}
		}

		$this->back('success', Lang::t(36));
	}

	/**
	 * Teste l'adresse du hub et la clé d'API (appel AJAX) ; renvoie un message prêt à afficher.
	 */
	public function test(): void
	{
		check_ajax_referer('linguaflow_wp_test', 'nonce');

		if (! current_user_can('manage_options')) {
			wp_send_json_error(['message' => Lang::t(45)], 403);
		}

		try {
			$this->plugin->hub()->health();
			wp_send_json_success(['message' => Lang::t(19)]);
		} catch (Throwable $e) {
			wp_send_json_error(['message' => Lang::t(20, $e->getMessage())]);
		}
	}

	/**
	 * Contrôle le droit et le nonce d'une action admin-post.
	 */
	private function guard(string $action): void
	{
		if (! current_user_can('manage_options')) {
			wp_die(esc_html(Lang::t(45)), '', ['response' => 403]);
		}

		check_admin_referer($action);
	}

	/**
	 * Retourne à l'écran du plugin avec un message.
	 */
	private function back(string $type, string $text): never
	{
		set_transient($this->noticeKey(), ['type' => $type, 'text' => $text], 60);
		wp_safe_redirect(admin_url('tools.php?page='.self::SLUG));
		exit;
	}

	/**
	 * Clé du message à afficher au prochain chargement, propre à l'utilisateur.
	 */
	private function noticeKey(): string
	{
		return 'linguaflow_wp_notice_'.get_current_user_id();
	}

	/**
	 * Nom d'une langue d'après son code (en français), ou le code lui-même.
	 */
	private function localeName(string $code): string
	{
		if (class_exists(\Locale::class)) {
			$name = (string) \Locale::getDisplayName(str_replace('-', '_', $code), 'fr');

			if ($name !== '' && $name !== $code) {
				return mb_strtoupper(mb_substr($name, 0, 1)).mb_substr($name, 1).' ('.$code.')';
			}
		}

		return $code;
	}

	/**
	 * Chemin du fichier principal du plugin (pour les adresses des fichiers CSS/JS).
	 */
	private function plugin_file(): string
	{
		return dirname(__DIR__, 2).'/linguaflow-for-wordpress.php';
	}
}
