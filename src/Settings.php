<?php

namespace Torskint\LinguaFlowWp;

use Torskint\LinguaFlowWp\Support\Crypto;

/**
 * Réglages du plugin : lecture avec valeurs par défaut, contrôle et chiffrement des secrets avant enregistrement.
 * La clé d'API et le secret du webhook sont chiffrés en base ; un champ secret laissé vide conserve l'ancienne valeur.
 */
class Settings
{
	/** Langues proposées d'emblée dans l'écran de réglages. */
	public const COMMON_LOCALES = [
		'en_US', 'de_DE', 'es_ES', 'it_IT', 'pt_PT', 'pt_BR', 'nl_NL', 'pl_PL', 'ru_RU', 'tr_TR',
		'ar', 'ja', 'ko_KR', 'zh_CN', 'sv_SE', 'da_DK', 'fi', 'el', 'cs_CZ', 'ro_RO',
	];

	/** Forme d'un code de langue WordPress accepté (fr_FR, de_DE, ja, pt_BR…). */
	public const LOCALE = '/^[a-z]{2,3}([_-][A-Za-z0-9]{2,8}){0,2}$/';

	/** Réglages par défaut. */
	private const DEFAULTS = [
		'hub_url' => '',
		'api_key' => '',
		'webhook_secret' => '',
		'delivery' => 'local',
		'source_locale' => 'fr_FR',
		'locales' => [],
	];

	// Constructeur : valeurs enregistrées et chiffrement des secrets.
	public function __construct(private array $stored, private Crypto $crypto) {}

	/**
	 * Valeur d'un réglage en clair (les secrets sont déchiffrés).
	 */
	public function get(string $name): mixed
	{
		$value = $this->stored[$name] ?? self::DEFAULTS[$name] ?? null;

		return in_array($name, ['api_key', 'webhook_secret'], true) ? $this->crypto->decrypt((string) $value) : $value;
	}

	/**
	 * Vrai si l'adresse du hub et la clé d'API sont renseignées.
	 */
	public function configured(): bool
	{
		return $this->get('hub_url') !== '' && $this->get('api_key') !== '';
	}

	/**
	 * Contrôle les valeurs saisies et renvoie ce qu'il faut enregistrer, avec les erreurs (numéros de clés de texte).
	 *
	 * @return array{values: array, errors: list<int>}
	 */
	public function sanitize(array $input): array
	{
		$errors = [];
		$values = $this->stored + self::DEFAULTS;

		$url = rtrim(trim((string) ($input['hub_url'] ?? '')), '/');

		if ($url === '' || $this->validUrl($url)) {
			$values['hub_url'] = $url;
		} else {
			$errors[] = 43;
		}

		foreach (['api_key', 'webhook_secret'] as $secret) {
			$typed = trim((string) ($input[$secret] ?? ''));

			if ($typed !== '') {
				$values[$secret] = $this->crypto->encrypt($typed);
			}
		}

		$values['delivery'] = ($input['delivery'] ?? 'local') === 'webhook' ? 'webhook' : 'local';

		$source = trim((string) ($input['source_locale'] ?? ''));

		if (preg_match(self::LOCALE, $source) === 1) {
			$values['source_locale'] = $source;
		} else {
			$errors[] = 44;
		}

		$values['locales'] = $this->locales((array) ($input['locales'] ?? []), (string) ($input['other_locales'] ?? ''), $values['source_locale']);

		return ['values' => $values, 'errors' => $errors];
	}

	/**
	 * Langues cibles : cases cochées plus codes saisis à la main, bien formés, sans doublon et sans la langue source.
	 *
	 * @return list<string>
	 */
	private function locales(array $checked, string $typed, string $source): array
	{
		$all = array_merge($checked, preg_split('/[\s,;]+/', $typed, -1, PREG_SPLIT_NO_EMPTY) ?: []);
		$out = [];

		foreach ($all as $code) {
			$code = trim((string) $code);

			if ($code !== $source && preg_match(self::LOCALE, $code) === 1) {
				$out[$code] = true;
			}
		}

		return array_keys($out);
	}

	/**
	 * Adresse du hub : http(s) avec un hôte ; https obligatoire sauf pour la machine locale.
	 */
	private function validUrl(string $url): bool
	{
		$parts = parse_url($url);

		if (! is_array($parts) || empty($parts['host']) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)) {
			return false;
		}

		return $parts['scheme'] === 'https' || in_array($parts['host'], ['localhost', '127.0.0.1', '[::1]'], true);
	}
}
