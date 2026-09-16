/**
 * @file cypress/tests/functional/CoAuthorParticipants.cy.js
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Functional tests: the plugin settings, the automatic linking when a
 * submission is completed, and the linking of a contributor added afterwards.
 *
 * The submission is completed through the same REST endpoint the wizard uses
 * (PUT /submissions/{id}/submit), so the SubmissionSubmitted event and the core
 * listeners run exactly as in production. Assertions read the participants
 * endpoint by email, never labels, so the spec runs in any language.
 *
 * Defaults target the PKP test data. Another installation can pass through
 * --env: contextPath, adminUser, adminPassword, and submissionId (a complete
 * submission still in the wizard, whose contributors include coauthorEmail,
 * an address with no account). Captcha on login must be off for the run.
 * The co-author email is an unroutable address: no message leaves the server.
 */

describe('Coauthor Participants plugin', function() {
	const contextPath = Cypress.env('contextPath') || 'publicknowledge';
	const adminUser = Cypress.env('adminUser') || 'admin';
	const adminPassword = Cypress.env('adminPassword') || 'admin';
	const stamp = Date.now();
	const coauthorEmail = Cypress.env('coauthorEmail') || `coauthor.${stamp}@example.invalid`;
	const lateEmail = `late.${stamp}@example.invalid`;

	const api = '/index.php/' + contextPath + '/api/v1';
	const pluginsUrl = '/index.php/' + contextPath + '/management/settings/website';
	const settingsForm = 'form[id="coAuthorParticipantsSettingsForm"]';
	const enableCheckbox = 'input[id^="select-cell-coauthorparticipantsplugin-enabled"]';

	let csrfToken = null;
	// The author role of this journal, taken from an article that already has one:
	// there is no user groups endpoint, and the id differs between installations.
	let authorGroupId = Cypress.env('authorUserGroupId') || null;
	let submissionId = Cypress.env('submissionId') || null;

	// ---- OJSBR spec helpers (padrão v2): work on OJS/OMP 3.3, 3.4 and 3.5 and in PKP's CI ----

	const pageUrl = (path) => '/index.php/' + contextPath + (path ? '/' + path : '');

	// Same as PKP's waitJQuery(), which the support files of OJS 3.3 test sites may lack.
	// jQuery may not be on the page yet when this runs, so the check retries on the window
	// itself instead of on a property that would resolve as undefined.
	const waitJQuery = () => cy.window({timeout: 60000}).should((win) => {
		expect(win.jQuery && win.jQuery.active, 'pending jQuery requests').to.eq(0);
	});

	// The form is only the plugin's once its PKP handler is attached: a Save clicked before
	// that submits the form natively and leaves the page for the grid's manage URL. After a
	// failed validation the modal replaces the form, so this is checked before every save.
	const waitFormHandler = (formSelector) => cy.window({timeout: 30000}).should((win) => {
		expect(win.jQuery(formSelector).data('pkp.handler'), 'form handler').to.exist;
	});

	// Signs in through requests (the login page can re-render while it is typed into), then
	// falls back to the form when the session did not stick (OJS 3.3 cookie handling).
	const login = (username, password) => {
		cy.clearCookies();
		cy.request(pageUrl('login')).then((response) => {
			const token = /name="csrfToken" value="([^"]+)"/.exec(response.body)[1];
			// The form posts to the URL with the language: a redirect would turn the POST into a GET.
			const action = /<form[^>]*id="login"[^>]*action="([^"]+)"/.exec(response.body)[1];
			cy.request({method: 'POST', url: action, form: true, body: {csrfToken: token, username: username, password: password}, log: false});
		});
		cy.visit(pageUrl('submissions') + '?reload=' + Date.now());
		cy.get('body').then(($body) => {
			if ($body.find('form#login').length) {
				cy.get('form#login input[name="username"]').type(username, {delay: 0});
				cy.get('form#login input[name="password"]').type(password, {delay: 0, log: false});
				cy.get('form#login').submit();
				cy.get('form#login', {timeout: 30000}).should('not.exist');
			}
		});
	};

	// Resolves the author role once, from a published article of the journal.
	const withAuthorGroup = (callback) => {
		if (authorGroupId) {
			return cy.wrap(authorGroupId, {log: false}).then(callback);
		}
		cy.request(pageUrl('api/v1/submissions?status=3&count=5')).then((listing) => {
			const items = (typeof listing.body === 'string' ? JSON.parse(listing.body) : listing.body).items;
			expect(items, 'a published article to read the author role from').to.have.length.at.least(1);
			cy.request({
				url: pageUrl('api/v1/submissions/' + items[0].id),
				headers: {'X-Csrf-Token': csrfToken},
			}).then((detail) => {
				const body = typeof detail.body === 'string' ? JSON.parse(detail.body) : detail.body;
				const author = (body.publications || []).flatMap((publication) => publication.authors || [])
					.find((candidate) => candidate.userGroupId);
				expect(author, 'an author with a role').to.not.be.undefined;
				authorGroupId = author.userGroupId;
				callback(authorGroupId);
			});
		});
	};

	// ---- end of helpers ----

	const getCsrfToken = () => {
		cy.visit('/index.php/' + contextPath + '/submissions');
		return cy.window().then((win) => {
			csrfToken = win.pkp.currentUser.csrfToken;
		});
	};

	const request = (method, url, body) => cy.request({
		method,
		url: api + url,
		headers: {'X-Csrf-Token': csrfToken},
		body,
		failOnStatusCode: false,
	});

	const participantEmails = (id) => request('GET', `/submissions/${id}/participants`)
		.then((response) => {
			expect(response.status).to.eq(200);
			return response.body.map((participant) => participant.email.toLowerCase());
		});

	// The Plugins tab, loaded once per test: loading it again while its plugin gallery
	// request is pending stalls the web server of PKP's CI.
	const openPluginsTab = () => {
		cy.visit(pluginsUrl + '?reload=' + Date.now() + '#plugins');
		cy.get('button[id="plugins-button"]', {timeout: 60000}).click();
		cy.get('button[id="plugins-button"]').should('have.attr', 'aria-selected', 'true');
		waitJQuery();
	};

	// Opens the settings modal from the grid on the page already loaded.
	const openSettings = () => {
		cy.get('tr[id*="coauthorparticipantsplugin"]', {timeout: 30000}).then(($row) => {
			if (!$row.find('a[id*="coauthorparticipantsplugin-settings"]:visible').length) {
				cy.get('tr[id*="coauthorparticipantsplugin"] a.show_extras').first().click();
			}
		});
		cy.get('a[id*="coauthorparticipantsplugin-settings"]').first().click({force: true});
		// The modal is position: fixed, which Cypress does not count as visible.
		cy.get(settingsForm).should('exist');
		waitFormHandler(settingsForm);
	};

	// closes: whether this save is expected to succeed. A refused one leaves the
	// modal open with its errors, and the form is the same one on screen.
	const saveSettings = ({closes = true} = {}) => {
		waitFormHandler(settingsForm);
		cy.intercept('POST', /settings-plugin-grid/).as('saveSettings');
		cy.get(settingsForm + ' button[id^="submitFormButton-"]').scrollIntoView().click();
		cy.wait('@saveSettings').its('response.statusCode').should('eq', 200);
		waitJQuery();
		if (closes) {
			// Reopening the modal before the old form is gone would read the values
			// still on screen.
			cy.get(settingsForm).should('not.exist');
		}
	};

	it('Enables the plugin and validates and persists its settings', function() {
		login(adminUser, adminPassword);

		openPluginsTab();
		cy.get(enableCheckbox, {timeout: 30000}).then(($checkbox) => {
			if (!$checkbox.is(':checked')) {
				cy.wrap($checkbox).click();
				waitJQuery();
			}
		});
		cy.get(enableCheckbox).should('be.checked');

		openSettings();
		cy.get('#coAuthorParticipantsAcknowledgement').should('exist');
		cy.get(settingsForm + ' input[name="autoLink"]').should('be.checked');
		cy.get(settingsForm + ' input[name="createAccounts"]').should('be.checked');
		cy.get(settingsForm + ' input[name="emailMaxAttempts"]').should('have.value', '3');
		cy.get(settingsForm + ' input[name="passwordLinkDays"]').should('have.value', '7');

		// Out of range: the server refuses it, the modal stays open with the error
		// and nothing is stored.
		cy.get(settingsForm + ' input[name="passwordLinkDays"]').scrollIntoView().clear().type('0', {delay: 0});
		saveSettings({closes: false});
		// A refused save keeps the modal open; an accepted one closes it.
		cy.get(settingsForm).should('exist');

		cy.get(settingsForm + ' input[name="passwordLinkDays"]').scrollIntoView().clear().type('10', {delay: 0});
		saveSettings();
		openSettings();
		cy.get(settingsForm + ' input[name="passwordLinkDays"]').should('have.value', '10');

		// Put it back as it was.
		cy.get(settingsForm + ' input[name="passwordLinkDays"]').scrollIntoView().clear().type('7', {delay: 0});
		saveSettings();
	});

	it('Links the co-authors when the submission is completed', function() {
		login(adminUser, adminPassword);
		getCsrfToken();
		cy.then(() => withAuthorGroup(() => {}));

		if (!submissionId) {
			// PKP test data: a complete submission with one extra contributor.
			const data = {
				sectionId: 1,
				title: 'Coauthor Participants ' + stamp,
				abstract: 'Abstract of the Coauthor Participants functional test.',
				files: [{file: 'dummy.pdf', fileName: 'dummy.pdf', mimeType: 'application/pdf', genre: 'Article Text'}],
				additionalAuthors: [{
					givenName: {en: 'Cypress'},
					familyName: {en: 'Coauthor'},
					country: 'BR',
					email: coauthorEmail,
					userGroupId: authorGroupId,
				}],
			};
			cy.then(() => cy.createSubmissionWithApi(data, csrfToken));
			cy.get('@submissionId').then((id) => { submissionId = id; });
		}

		cy.then(() => participantEmails(submissionId)).should('not.include', coauthorEmail);

		cy.then(() => request('PUT', `/submissions/${submissionId}/submit`, {}))
			.its('status').should('eq', 200);

		// What the journal actually has at this point, so that a failure here says
		// whether the co-author was on the publication and with which role. The
		// submission response carries publications without their authors: the
		// authors come from the publication's own entry.
		cy.then(() => request('GET', `/submissions/${submissionId}`)).then((response) => {
			const publicationId = response.body.currentPublicationId;
			cy.then(() => request('GET', `/submissions/${submissionId}/publications/${publicationId}`)).then((pub) => {
				cy.log('DIAG autores: ' + (pub.body.authors || []).map((a) => a.email + '/' + a.userGroupId).join(' | '));
			});
		});
		cy.then(() => request('GET', `/submissions/${submissionId}/participants`)).then((response) => {
			cy.log('DIAG participantes: ' + (response.body || []).map((p) => p.email).join(' | '));
		});
		// Whether the plugin is registered at all on an API request: it adds its
		// mailable in the same place where it starts listening for the submission.
		cy.then(() => request('GET', '/mailables')).then((response) => {
			const found = JSON.stringify(response.body || []).includes('CoauthorParticipantAssigned');
			cy.log('DIAG plugin carregado na API: ' + found);
		});

		cy.then(() => participantEmails(submissionId)).should('include', coauthorEmail);
	});

	it('Links a contributor added after the submission was completed', function() {
		login(adminUser, adminPassword);
		getCsrfToken();

		cy.then(() => request('GET', `/submissions/${submissionId}`)).then((response) => {
			expect(response.status).to.eq(200);
			const publicationId = response.body.currentPublicationId;
			const groupId = response.body.publications[0].authors?.[0]?.userGroupId || authorGroupId;

			return request('POST', `/submissions/${submissionId}/publications/${publicationId}/contributors`, {
				givenName: {[response.body.locale]: 'Late'},
				familyName: {[response.body.locale]: 'Contributor'},
				country: 'BR',
				email: lateEmail,
				userGroupId: groupId,
			});
		}).its('status').should('eq', 200);

		cy.then(() => participantEmails(submissionId)).should('include', lateEmail);
	});
});
