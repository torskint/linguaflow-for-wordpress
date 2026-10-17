/* Écran d'administration de Linguaflow For WordPress : bouton verrouillé avec indicateur pendant l'envoi d'un formulaire,
 * et test de connexion au hub. Aucun texte ici : les messages viennent du serveur. */
(function () {
	'use strict';

	// Verrouille le bouton d'un formulaire au moment de l'envoi (évite le double clic sur une action lente)
	document.querySelectorAll('[data-lfw-form]').forEach(function (form) {
		form.addEventListener('submit', function () {
			var button = form.querySelector('[data-lfw-lock]');

			if (button) {
				button.classList.add('is-loading');
				button.setAttribute('aria-busy', 'true');
				// Le désactiver tout de suite empêcherait l'envoi : on le fait au tour suivant
				window.setTimeout(function () {
					button.disabled = true;
				}, 0);
			}
		});
	});

	// Test de connexion : appel AJAX, le message de retour est affiché tel quel
	var test = document.querySelector('[data-lfw-test]');
	var result = document.querySelector('[data-lfw-test-result]');

	if (test && result) {
		test.addEventListener('click', function () {
			var body = new FormData();
			body.append('action', 'linguaflow_wp_test');
			body.append('nonce', test.dataset.nonce);

			test.classList.add('is-loading');
			test.disabled = true;
			result.className = 'lfw-test-result';
			result.textContent = '';

			fetch(test.dataset.url, { method: 'POST', credentials: 'same-origin', body: body })
				.then(function (response) {
					return response.json();
				})
				.then(function (json) {
					result.textContent = json && json.data ? json.data.message : '';
					result.classList.add(json && json.success ? 'is-ok' : 'is-error');
				})
				.catch(function () {
					result.classList.add('is-error');
				})
				.finally(function () {
					test.classList.remove('is-loading');
					test.disabled = false;
				});
		});
	}
})();
