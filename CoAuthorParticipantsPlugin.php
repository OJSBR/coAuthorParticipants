<?php

/**
 * @file plugins/generic/coAuthorParticipants/CoAuthorParticipantsPlugin.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class CoAuthorParticipantsPlugin
 *
 * @brief Turns the co-authors listed in a submission's contributors into users
 *  taking part in the editorial workflow of that submission, with the author role.
 */

namespace APP\plugins\generic\coAuthorParticipants;

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\coAuthorParticipants\classes\CoauthorParticipantService;
use APP\plugins\generic\coAuthorParticipants\classes\listeners\SynchronizeSubmittedCoauthors;
use APP\plugins\generic\coAuthorParticipants\classes\mailables\CoauthorParticipantAssigned;
use APP\plugins\generic\coAuthorParticipants\classes\migrations\CoauthorParticipantLogMigration;
use APP\plugins\generic\coAuthorParticipants\classes\SyncOptions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use PKP\context\Context;
use PKP\core\JSONMessage;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\observers\events\SubmissionSubmitted;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use Throwable;

class CoAuthorParticipantsPlugin extends GenericPlugin
{
    /** Default values of the journal settings. */
    public const DEFAULTS = [
        'autoLink' => true,
        'sendEmail' => true,
        'createAccounts' => true,
        'authorUserGroupId' => null,
        'logDetails' => true,
        'emailMaxAttempts' => 3,
        'passwordLinkDays' => 7,
    ];

    /** Ranges accepted for the numeric settings. */
    public const EMAIL_MAX_ATTEMPTS_RANGE = [1, 10];
    public const PASSWORD_LINK_DAYS_RANGE = [1, 30];

    /** Setting names used to restore the native submission acknowledgement. */
    public const SETTING_PREVIOUS_ACK = 'previousSubmissionAcknowledgement';
    public const SETTING_APPLIED_ACK = 'appliedSubmissionAcknowledgement';

    /**
     * @copydoc Plugin::getDisplayName()
     */
    public function getDisplayName()
    {
        return __('plugins.generic.coAuthorParticipants.displayName');
    }

    /**
     * @copydoc Plugin::getDescription()
     */
    public function getDescription()
    {
        return __('plugins.generic.coAuthorParticipants.description');
    }

    /**
     * @copydoc Plugin::register()
     *
     * @param null|mixed $mainContextId
     */
    public function register($category, $path, $mainContextId = null)
    {
        if (!parent::register($category, $path, $mainContextId)) {
            return false;
        }

        if (Application::isUnderMaintenance()) {
            return true;
        }

        if ($this->getEnabled($mainContextId)) {
            // Registered after the core listeners, which are discovered when the
            // application boots. The core acknowledgement is sent to every user
            // assigned as author at the moment it runs; linking the co-authors
            // before it would put them in the submitter's acknowledgement.
            Event::listen(SubmissionSubmitted::class, fn (SubmissionSubmitted $event) => (new SynchronizeSubmittedCoauthors($this))->handle($event));

            Hook::add('Author::add', $this->handleAuthorChange(...));
            Hook::add('Author::edit', $this->handleAuthorChange(...));
            Hook::add('Mailer::Mailables', $this->addMailable(...));
        }

        return true;
    }

    /**
     * Create the plugin's own table when it is not there yet.
     *
     * The migration runs when the plugin is installed through the interface or
     * the gallery; a folder copied onto the server by hand and switched on from
     * the plugins grid never runs it, and every linked contributor would then
     * fail against a table that does not exist. The migration is idempotent, so
     * calling it here is safe.
     */
    public function ensureSchema(): void
    {
        if (Schema::hasTable(CoauthorParticipantLogMigration::TABLE)) {
            return;
        }

        try {
            $this->getInstallMigration()->up();
        } catch (Throwable $e) {
            error_log('[coAuthorParticipants] the log table could not be created: ' . $e->getMessage());
        }
    }

    /**
     * @copydoc Plugin::getInstallMigration()
     */
    public function getInstallMigration()
    {
        return new CoauthorParticipantLogMigration();
    }

    /**
     * @copydoc Plugin::getInstallEmailTemplatesFile()
     */
    public function getInstallEmailTemplatesFile()
    {
        return $this->getPluginPath() . '/emailTemplates.xml';
    }

    /**
     * Enabling the plugin moves the native "all authors" acknowledgement to the
     * submitting author only, so a co-author gets exactly one message; disabling
     * it restores the previous value unless someone changed it in the meantime.
     *
     * @copydoc LazyLoadPlugin::setEnabled()
     */
    public function setEnabled($enabled)
    {
        parent::setEnabled($enabled);

        if ($enabled) {
            $this->ensureSchema();
        }

        $context = Application::get()->getRequest()->getContext();
        if (!$context) {
            return;
        }

        try {
            $enabled
                ? $this->takeOverSubmissionAcknowledgement($context)
                : $this->restoreSubmissionAcknowledgement($context);
        } catch (Throwable $e) {
            error_log('[coAuthorParticipants] Could not adjust the submission acknowledgement setting: ' . $e->getMessage());
        }
    }

    /**
     * Switch "allAuthors" to "submittingAuthor", remembering the previous value.
     */
    public function takeOverSubmissionAcknowledgement(Context $context): void
    {
        $current = $context->getData('submissionAcknowledgement');
        if ($current !== Context::SUBMISSION_ACKNOWLEDGEMENT_ALL_AUTHORS) {
            return;
        }

        $this->updateSetting($context->getId(), self::SETTING_PREVIOUS_ACK, $current, 'string');
        $this->updateSetting($context->getId(), self::SETTING_APPLIED_ACK, Context::SUBMISSION_ACKNOWLEDGEMENT_SUBMITTING_AUTHOR, 'string');
        $this->editContext($context, ['submissionAcknowledgement' => Context::SUBMISSION_ACKNOWLEDGEMENT_SUBMITTING_AUTHOR]);
    }

    /**
     * Put the previous value back, only if the value is still the one applied.
     */
    public function restoreSubmissionAcknowledgement(Context $context): void
    {
        $previous = $this->getSetting($context->getId(), self::SETTING_PREVIOUS_ACK);
        $applied = $this->getSetting($context->getId(), self::SETTING_APPLIED_ACK);
        if (!$previous || !$applied) {
            return;
        }

        if ($context->getData('submissionAcknowledgement') === $applied) {
            $this->editContext($context, ['submissionAcknowledgement' => $previous]);
        }

        $this->updateSetting($context->getId(), self::SETTING_PREVIOUS_ACK, '', 'string');
        $this->updateSetting($context->getId(), self::SETTING_APPLIED_ACK, '', 'string');
    }

    /**
     * Edit context data through the context service, as the settings forms do.
     */
    protected function editContext(Context $context, array $params): void
    {
        app()->get('context')->edit($context, $params, Application::get()->getRequest());
    }

    /**
     * A contributor added to (or edited in) a submission that was already
     * submitted is linked as well, with the same service and rules.
     */
    public function handleAuthorChange(string $hookName, array $args): bool
    {
        try {
            $author = $args[0];
            $publication = Repo::publication()->get((int) $author->getData('publicationId'));
            $submission = $publication ? Repo::submission()->get((int) $publication->getData('submissionId')) : null;
            if (!$submission || !CoauthorParticipantService::isEligible($submission)) {
                return Hook::CONTINUE;
            }

            $context = Application::getContextDAO()->getById((int) $submission->getData('contextId'));
            if (!$context || !$this->getEnabled($context->getId()) || !$this->getJournalSetting($context->getId(), 'autoLink')) {
                return Hook::CONTINUE;
            }

            $this->ensureSchema();
            $this->getService()->synchronizeSubmission(
                $submission,
                $context,
                SyncOptions::fromJournalSettings($this, $context->getId(), SyncOptions::SOURCE_AUTOMATIC)
            );
        } catch (Throwable $e) {
            // Never break the request that saved the contributor.
            error_log('[coAuthorParticipants] ' . $hookName . ' failed: ' . $e->getMessage());
        }

        return Hook::CONTINUE;
    }

    /**
     * Make the email template visible and editable in the journal's emails.
     */
    public function addMailable(string $hookName, array $args): bool
    {
        $mailables = $args[0];
        $mailables->push(CoauthorParticipantAssigned::class);

        return Hook::CONTINUE;
    }

    /**
     * The one service used by the listener, the contributor hooks, the queue
     * job and the command line tool.
     */
    public function getService(): CoauthorParticipantService
    {
        return new CoauthorParticipantService($this);
    }

    /**
     * Read a journal setting with its default and bounds applied.
     */
    public function getJournalSetting(int $contextId, string $name): mixed
    {
        $value = $this->getSetting($contextId, $name);

        return static::normalizeSetting($name, $value);
    }

    /**
     * Apply the default and the accepted range of a setting.
     */
    public static function normalizeSetting(string $name, mixed $value): mixed
    {
        if (!array_key_exists($name, self::DEFAULTS)) {
            return $value;
        }

        if ($value === null || $value === '') {
            return self::DEFAULTS[$name];
        }

        return match ($name) {
            'emailMaxAttempts' => max(self::EMAIL_MAX_ATTEMPTS_RANGE[0], min(self::EMAIL_MAX_ATTEMPTS_RANGE[1], (int) $value)),
            'passwordLinkDays' => max(self::PASSWORD_LINK_DAYS_RANGE[0], min(self::PASSWORD_LINK_DAYS_RANGE[1], (int) $value)),
            'authorUserGroupId' => (int) $value ?: null,
            default => (bool) $value,
        };
    }

    /**
     * @copydoc Plugin::getActions()
     */
    public function getActions($request, $actionArgs)
    {
        $router = $request->getRouter();

        return array_merge(
            $this->getEnabled() ? [
                new LinkAction(
                    'settings',
                    new AjaxModal(
                        $router->url($request, null, null, 'manage', null, [
                            'verb' => 'settings',
                            'plugin' => $this->getName(),
                            'category' => 'generic',
                        ]),
                        $this->getDisplayName()
                    ),
                    __('manager.plugins.settings'),
                    null
                ),
            ] : [],
            parent::getActions($request, $actionArgs)
        );
    }

    /**
     * @copydoc Plugin::manage()
     */
    public function manage($args, $request)
    {
        if ($request->getUserVar('verb') !== 'settings') {
            return parent::manage($args, $request);
        }

        $context = $request->getContext();
        if (!$context) {
            return new JSONMessage(false);
        }

        $form = new CoAuthorParticipantsSettingsForm($this, $context);

        if ($request->getUserVar('save')) {
            $form->readInputData();
            if ($form->validate()) {
                $form->execute();
                return new JSONMessage(true);
            }
        } else {
            $form->initData();
        }

        return new JSONMessage(true, $form->fetch($request));
    }
}

if (!PKP_STRICT_MODE) {
    class_alias('\APP\plugins\generic\coAuthorParticipants\CoAuthorParticipantsPlugin', '\CoAuthorParticipantsPlugin');
}
