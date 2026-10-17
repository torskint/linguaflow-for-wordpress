<?php

namespace Torskint\LinguaFlowWp\Rest;

use Torskint\LinguaFlowWp\Plugin;
use Torskint\LinguaFlowWp\Webhook\WebhookVerifier;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Route REST `linguaflow/v1/webhook` : reçoit les notifications du hub. L'accès est ouvert mais chaque requête doit porter
 * une signature HMAC valide ; la récupération du résultat se fait ensuite en tâche de fond (wp-cron).
 */
class WebhookRoute
{
	// Constructeur : plugin (réglages et noms des tâches).
	public function __construct(private Plugin $plugin) {}

	/**
	 * Déclare la route.
	 */
	public function register(): void
	{
		register_rest_route('linguaflow/v1', '/webhook', [
			'methods' => 'POST',
			'callback' => [$this, 'handle'],
			'permission_callback' => '__return_true',
		]);
	}

	/**
	 * Contrôle la requête puis planifie la synchronisation du travail annoncé.
	 */
	public function handle(WP_REST_Request $request): WP_REST_Response
	{
		$verifier = new WebhookVerifier((string) $this->plugin->settings()->get('webhook_secret'));
		$check = $verifier->check($request->get_body(), (string) $request->get_header('X-Hub-Signature'));

		if (! $check['ok']) {
			return new WP_REST_Response(['error' => $check['error']], $check['status']);
		}

		if (! wp_next_scheduled(Plugin::CRON_ONE, [$check['reference']])) {
			wp_schedule_single_event(time(), Plugin::CRON_ONE, [$check['reference']]);
			spawn_cron();
		}

		return new WP_REST_Response(['accepted' => true], 202);
	}
}
