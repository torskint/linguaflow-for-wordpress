# Linguaflow For WordPress

Plugin WordPress autonome (aucune dépendance Composer à l'exécution) qui parle le protocole v1 du Translation Hub
(`X-LinguaFlow-Protocol: 1`, `framework: "wordpress"`).

## Ce qu'il fait

1. Lit les fichiers de langue du **thème actif** (et du thème parent si c'est un thème enfant) :
   `{thème}/languages/{thème}-{langue}.po`, `{thème}/languages/{langue}.po` ou, à défaut, le premier `.pot` ;
   `wp-content/languages/themes/{thème}-{langue}.po`.
2. Calcule le **delta** : pour chaque langue cible, les entrées absentes ou vides (clé = msgid, précédé du contexte et de `0x04` ; pluriels inclus).
3. Envoie au hub une archive zip (fichier source réduit aux clés demandées + `.linguaflow/manifest.json`) avec une `Idempotency-Key` ;
   la **référence** reçue est gardée dans la table `{préfixe}linguaflow_jobs`. Plusieurs envois peuvent tourner en parallèle.
4. Récupère le résultat : **sondage périodique** (wp-cron, toutes les 5 minutes, délai qui s'allonge en cas d'échec) ou **webhook signé**
   (`POST /wp-json/linguaflow/v1/webhook`, HMAC-SHA256 `X-Hub-Signature`, date contrôlée à 5 minutes, corps = référence seulement).
5. Fusionne sans jamais rien écraser : une valeur non vide n'est pas touchée, une traduction dont les marqueurs (`%s`, `%1$d`, `{nom}`, balises) diffèrent
   de la source est refusée, l'écriture est atomique avec une copie de l'ancien fichier (`uploads/linguaflow-for-wordpress/backups`). Rejouer la fusion ne change rien.
6. Compile les **`.mo`** et les **`.l10n.php`** (WordPress 6.5+) à côté du `.po`, avec la règle de pluriel de la langue, puis confirme au hub (`ack`).

Langues : codes WordPress partout (`fr_FR`, `de_DE`, `pt_BR`, `ja`…). La langue source se règle dans l'écran du plugin.

## Installation

Copier le dossier (ou le zip) dans `wp-content/plugins/linguaflow-for-wordpress`, activer le plugin, puis **Outils → Linguaflow For WordPress** :
adresse du hub, clé API (jeton Sanctum du hub), mode de livraison, langue source, langues cibles. « Tester la connexion » vérifie l'adresse et la clé.
En mode webhook, copier l'adresse affichée dans l'accès API du hub et saisir ici le secret `whsec_…` du client.

La clé API et le secret du webhook sont **chiffrés** (AES-256-GCM, clé dérivée des sels de `wp-config.php`). Si les sels changent, ressaisir les deux secrets.

## Signaler une erreur du hub

Quand le hub refuse ou échoue une requête, le message affiché (test de connexion, colonne « Erreur » des envois) se termine par `[X-Request-Id : …]`.
Citer cet identifiant au support : il retrouve l'appel exact dans l'**Activité API** du hub (recherche par identifiant de requête). Une panne réseau
n'a pas d'identifiant, puisque le hub n'a pas répondu.

## Limites connues (v1)

- Seuls les fichiers du thème actif sont traduits (pas les plugins, pas le cœur de WordPress).
- Les traductions JavaScript (`.json` de `wp_set_script_translations`) ne sont pas gérées.
- L'écran de réglages est en français uniquement ; textes TRAD_n dans `languages/linguaflow-for-wordpress-fr_FR.po`.
- Règles de pluriel connues pour les langues courantes ; pour une langue inconnue, WordPress retombe sur deux formes.

## Développement

```bash
composer install
vendor/bin/phpunit            # cœur du plugin, sans WordPress (hub simulé)
php tools/build-mo.php        # recompile le .mo français après modification du .po
```

Le test `SettingsTest` vérifie que chaque `TRAD_n` utilisé dans le code existe en français et que le `.mo` est à jour.
Les fichiers sont indentés à la tabulation (4).
