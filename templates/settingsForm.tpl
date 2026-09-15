{**
 * plugins/generic/coAuthorParticipants/templates/settingsForm.tpl
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Journal settings of the Coauthor Participants plugin.
 *}
<script>
	$(function() {ldelim}
		$('#coAuthorParticipantsSettingsForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
	{rdelim});
</script>

<form
	class="pkp_form"
	id="coAuthorParticipantsSettingsForm"
	method="post"
	action="{url router=PKP\core\PKPApplication::ROUTE_COMPONENT op="manage" category="generic" plugin=$pluginName verb="settings" save=true}"
>
	{csrf}
	{include file="controllers/notification/inPlaceNotification.tpl" notificationId="coAuthorParticipantsSettingsFormNotification"}

	<p class="pkp_help">{translate key="plugins.generic.coAuthorParticipants.settings.description"}</p>

	<div class="pkp_notification" id="coAuthorParticipantsAcknowledgement">
		<div class="notifyInfo">
			<span class="title">{translate key="plugins.generic.coAuthorParticipants.settings.acknowledgement.title"}</span>
			<span class="description">{$acknowledgementNotice|escape}</span>
		</div>
	</div>

	{fbvFormArea id="coAuthorParticipantsLinking" title="plugins.generic.coAuthorParticipants.settings.linkingArea"}
		{fbvFormSection list=true}
			{fbvElement type="checkbox" id="autoLink" name="autoLink" checked=$autoLink label="plugins.generic.coAuthorParticipants.settings.autoLink"}
			{fbvElement type="checkbox" id="createAccounts" name="createAccounts" checked=$createAccounts label="plugins.generic.coAuthorParticipants.settings.createAccounts"}
		{/fbvFormSection}
		{fbvFormSection title="plugins.generic.coAuthorParticipants.settings.authorUserGroupId" description="plugins.generic.coAuthorParticipants.settings.authorUserGroupId.description"}
			{fbvElement type="select" id="authorUserGroupId" name="authorUserGroupId" from=$authorGroupOptions selected=$authorUserGroupId translate=false size=$fbvStyles.size.MEDIUM}
		{/fbvFormSection}
	{/fbvFormArea}

	{fbvFormArea id="coAuthorParticipantsEmail" title="plugins.generic.coAuthorParticipants.settings.emailArea"}
		{fbvFormSection list=true}
			{fbvElement type="checkbox" id="sendEmail" name="sendEmail" checked=$sendEmail label="plugins.generic.coAuthorParticipants.settings.sendEmail"}
		{/fbvFormSection}
		{fbvFormSection title="plugins.generic.coAuthorParticipants.settings.emailMaxAttempts"}
			{fbvElement type="text" id="emailMaxAttempts" name="emailMaxAttempts" value=$emailMaxAttempts size=$fbvStyles.size.SMALL}
		{/fbvFormSection}
		{fbvFormSection title="plugins.generic.coAuthorParticipants.settings.passwordLinkDays" description="plugins.generic.coAuthorParticipants.settings.passwordLinkDays.description"}
			{fbvElement type="text" id="passwordLinkDays" name="passwordLinkDays" value=$passwordLinkDays size=$fbvStyles.size.SMALL}
		{/fbvFormSection}
	{/fbvFormArea}

	{fbvFormArea id="coAuthorParticipantsLog" title="plugins.generic.coAuthorParticipants.settings.logArea"}
		{fbvFormSection list=true}
			{fbvElement type="checkbox" id="logDetails" name="logDetails" checked=$logDetails label="plugins.generic.coAuthorParticipants.settings.logDetails"}
		{/fbvFormSection}
	{/fbvFormArea}

	{fbvFormButtons}
</form>
