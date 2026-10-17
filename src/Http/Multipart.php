<?php

namespace Torskint\LinguaFlowWp\Http;

/**
 * Corps `multipart/form-data` d'un envoi de fichier (wp_remote_post n'envoie pas de fichier tout seul).
 */
class Multipart
{
	/**
	 * Corps et type de contenu pour un champ fichier.
	 *
	 * @return array{body: string, contentType: string}
	 */
	public static function file(string $field, string $filename, string $content, string $mime, ?string $boundary = null): array
	{
		$boundary ??= 'lfw'.bin2hex(random_bytes(12));

		$body = '--'.$boundary."\r\n"
			.'Content-Disposition: form-data; name="'.$field.'"; filename="'.$filename.'"'."\r\n"
			.'Content-Type: '.$mime."\r\n\r\n"
			.$content."\r\n"
			.'--'.$boundary."--\r\n";

		return ['body' => $body, 'contentType' => 'multipart/form-data; boundary='.$boundary];
	}
}
