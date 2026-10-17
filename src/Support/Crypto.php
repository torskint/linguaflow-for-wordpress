<?php

namespace Torskint\LinguaFlowWp\Support;

/**
 * Chiffre les secrets stockés en base (clé d'API, secret du webhook) : AES-256-GCM, clé dérivée des sels de WordPress.
 */
class Crypto
{
	/** Préfixe d'une valeur chiffrée (permet de reconnaître une valeur déjà chiffrée). */
	private const PREFIX = 'lfw1:';

	// Constructeur : matière de la clé (sels de WordPress).
	public function __construct(private string $secret) {}

	/**
	 * Chiffre une valeur ; une valeur vide reste vide.
	 */
	public function encrypt(string $plain): string
	{
		if ($plain === '') {
			return '';
		}

		$iv = random_bytes(12);
		$cipher = openssl_encrypt($plain, 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, $iv, $tag);

		return self::PREFIX.base64_encode($iv.$tag.$cipher);
	}

	/**
	 * Déchiffre une valeur ; renvoie une chaîne vide si elle est altérée ou si la clé a changé.
	 */
	public function decrypt(string $stored): string
	{
		if (! str_starts_with($stored, self::PREFIX)) {
			return '';
		}

		$raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);

		if ($raw === false || strlen($raw) < 29) {
			return '';
		}

		$plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));

		return $plain === false ? '' : $plain;
	}

	/**
	 * Clé de 32 octets dérivée du secret.
	 */
	private function key(): string
	{
		return hash('sha256', 'linguaflow-for-wordpress|'.$this->secret, true);
	}
}
