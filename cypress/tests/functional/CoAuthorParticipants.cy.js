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
	let submissionId = Cypress.env('submissionId') || null;

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

	const openSettings = () => {
		cy.visit(pluginsUrl);
		cy.get('button[id="plugins-button"]').click();
		cy.waitJQuery();
		cy.get('tr[id*="coauthorparticipantsplugin"] a.show_extras').click();
		cy.get('a[id*="coauthorparticipantsplugin-settings"]').click();
		// The modal is position: fixed, which Cypress does not count as visible.
		cy.get(settingsForm).should('exist');
	};

	const saveSettings = () => {
		cy.intercept('POST', /settings-plugin-grid/).as('saveSettings');
		cy.get(settingsForm + ' button[id^="submitFormButton-"]').scrollIntoView().click();
		cy.wait('@saveSettings').its('response.statusCode').should('eq', 200);
		cy.waitJQuery();
	};

	it('Enables the plugin and validates and persists its settings', function() {
		cy.login(adminUser, adminPassword, contextPath);

		cy.visit(pluginsUrl);
		cy.get('button[id="plugins-button"]').click();
		cy.waitJQuery();
		cy.get(enableCheckbox).then(($checkbox) => {
			if (!$checkbox.is(':checked')) {
				cy.wrap($checkbox).click();
				cy.waitJQuery();
			}
		});
		cy.get(enableCheckbox).should('be.checked');

		openSettings();
		cy.get('#coAuthorParticipantsAcknowledgement').should('exist');
		cy.get(settingsForm + ' input[name="autoLink"]').should('be.checked');
		cy.get(settingsForm + ' input[name="createAccounts"]').should('be.checked');
		cy.get(settingsForm + ' input[name="emailMaxAttempts"]').should('have.value', '3');
		cy.get(settingsForm + ' input[name="passwordLinkDays"]').should('have.value', '7');

		// Out of range: the server refuses it and nothing is stored.
		cy.get(settingsForm + ' input[name="passwordLinkDays"]').scrollIntoView().clear().type('0', {delay: 0});
		saveSettings();
		openSettings();
		cy.get(settingsForm + ' input[name="passwordLinkDays"]').should('have.value', '7');

		cy.get(settingsForm + ' input[name="passwordLinkDays"]').scrollIntoView().clear().type('10', {delay: 0});
		saveSettings();
		openSettings();
		cy.get(settingsForm + ' input[name="passwordLinkDays"]').should('have.value', '10');

		cy.get(settingsForm + ' input[name="passwordLinkDays"]').scrollIntoView().clear().type('7', {delay: 0});
		saveSettings();
	});

	it('Links the co-authors when the submission is completed', function() {
		cy.login(adminUser, adminPassword, contextPath);
		getCsrfToken();

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
					userGroupId: Cypress.env('authorUserGroupId'),
				}],
			};
			cy.then(() => cy.createSubmissionWithApi(data, csrfToken));
			cy.get('@submissionId').then((id) => { submissionId = id; });
		}

		cy.then(() => participantEmails(submissionId)).should('not.include', coauthorEmail);

		cy.then(() => request('PUT', `/submissions/${submissionId}/submit`, {}))
			.its('status').should('eq', 200);

		cy.then(() => participantEmails(submissionId)).should('include', coauthorEmail);
	});

	it('Links a contributor added after the submission was completed', function() {
		cy.login(adminUser, adminPassword, contextPath);
		getCsrfToken();

		cy.then(() => request('GET', `/submissions/${submissionId}`)).then((response) => {
			expect(response.status).to.eq(200);
			const publicationId = response.body.currentPublicationId;
			const authorGroupId = response.body.publications[0].authors?.[0]?.userGroupId || Cypress.env('authorUserGroupId');
			return request('POST', `/submissions/${submissionId}/publications/${publicationId}/contributors`, {
				givenName: {[response.body.locale]: 'Late'},
				familyName: {[response.body.locale]: 'Contributor'},
				country: 'BR',
				email: lateEmail,
				userGroupId: authorGroupId,
			});
		}).its('status').should('eq', 200);

		cy.then(() => participantEmails(submissionId)).should('include', lateEmail);
	});
});
