<?php

namespace Torskint\LinguaFlowWp\Tests;

use PHPUnit\Framework\TestCase;
use Torskint\LinguaFlowWp\Http\Multipart;
use Torskint\LinguaFlowWp\Support\Crypto;
use Torskint\LinguaFlowWp\Webhook\WebhookVerifier;

/**
 * Briques isolées : webhook signé, chiffrement des secrets, corps multipart.
 */
class SupportTest extends TestCase
{
	/**
	 * Un webhook signé et récent donne la référence ; signature fausse, requête ancienne, référence absente ou secret vide : refus.
	 */
	public function test_webhook_verification(): void
	{
		$secret = 'whsec_test';
		$verifier = new WebhookVerifier($secret);
		$now = 1_800_000_000;
		$sign = fn (string $body, string $key = 'whsec_test') => 'sha256='.hash_hmac('sha256', $body, $key);

		$body = json_encode(['created_at' => gmdate('c', $now), 'data' => ['reference' => 'ref12345']]);
		$ok = $verifier->check($body, $sign($body), $now);
		$this->assertTrue($ok['ok']);
		$this->assertSame('ref12345', $ok['reference']);

		$this->assertSame(401, $verifier->check($body, $sign($body, 'mauvais'), $now)['status']);

		$old = json_encode(['created_at' => gmdate('c', $now - 3600), 'data' => ['reference' => 'ref12345']]);
		$this->assertSame('stale_request', $verifier->check($old, $sign($old), $now)['error']);

		$empty = json_encode(['created_at' => gmdate('c', $now), 'data' => []]);
		$this->assertSame(422, $verifier->check($empty, $sign($empty), $now)['status']);

		$this->assertSame(503, (new WebhookVerifier(''))->check($body, $sign($body), $now)['status']);
	}

	/**
	 * Un secret chiffré se relit ; une valeur altérée ou une autre clé donne une chaîne vide.
	 */
	public function test_crypto_round_trip(): void
	{
		$crypto = new Crypto('sels');
		$stored = $crypto->encrypt('whsec_secret');

		$this->assertNotSame('whsec_secret', $stored);
		$this->assertSame('whsec_secret', $crypto->decrypt($stored));
		$this->assertSame('', $crypto->decrypt(substr($stored, 0, -3).'AAA'));
		$this->assertSame('', (new Crypto('autres'))->decrypt($stored));
		$this->assertSame('', $crypto->encrypt(''));
	}

	/**
	 * Le corps multipart contient le champ fichier et se termine par la balise de fin.
	 */
	public function test_multipart_body(): void
	{
		$part = Multipart::file('archive', 'archive.zip', 'ZIPDATA', 'application/zip', 'B0');

		$this->assertSame('multipart/form-data; boundary=B0', $part['contentType']);
		$this->assertStringContainsString('name="archive"; filename="archive.zip"', $part['body']);
		$this->assertStringContainsString("\r\n\r\nZIPDATA\r\n--B0--\r\n", $part['body']);
	}
}
