<?php
/**
 * Plugin Name: Linguaflow For WordPress
 * Description: Envoie les textes manquants du thème actif au Translation Hub, récupère les traductions (sondage wp-cron ou webhook signé) et les fusionne sans jamais rien écraser.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * Author: Torskint
 * Text Domain: linguaflow-for-wordpress
 * Domain Path: /languages
 * License: Proprietary
 */

if (! defined('ABSPATH')) {
	exit;
}

// Chargeur automatique des classes du plugin (aucune dépendance Composer à l'exécution)
spl_autoload_register(function (string $class): void {
	$prefix = 'Torskint\\LinguaFlowWp\\';

	if (str_starts_with($class, $prefix)) {
		$file = __DIR__.'/src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';

		if (is_file($file)) {
			require_once $file;
		}
	}
});

(new Torskint\LinguaFlowWp\Plugin(__FILE__))->boot();
