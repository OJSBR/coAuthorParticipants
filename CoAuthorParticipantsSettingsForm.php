<?php

/**
 * @file plugins/generic/coAuthorParticipants/CoAuthorParticipantsSettingsForm.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class CoAuthorParticipantsSettingsForm
 *
 * @brief Journal settings of the Coauthor Participants plugin.
 */

namespace APP\plugins\generic\coAuthorParticipants;

use APP\template\TemplateManager;
use PKP\context\Context;
use PKP\form\Form;
use PKP\form\validation\FormValidatorCSRF;
use PKP\form\validation\FormValidatorCustom;
use PKP\form\validation\FormValidatorPost;

class CoAuthorParticipantsSettingsForm extends Form
{
    public const BOOLEAN_SETTINGS = ['autoLink', 'sendEmail', 'createAccounts', 'logDetails'];
    public const NUMERIC_SETTINGS = ['authorUserGroupId', 'emailMaxAttempts', 'passwordLinkDays'];

    public function __construct(public CoAuthorParticipantsPlugin $plugin, public Context $context)
    {
        parent::__construct($plugin->getTemplateResource('settingsForm.tpl'));

        $authorGroupIds = array_keys($this->getAuthorGroupOptions());
        $this->addCheck(new FormValidatorCustom(
            $this,
            'authorUserGroupId',
            FormValidatorCustom::FORM_VALIDATOR_OPTIONAL_VALUE,
            'plugins.generic.coAuthorParticipants.settings.authorUserGroupId.invalid',
            fn ($value) => in_array((int) $value, $authorGroupIds, true)
        ));
        $this->addCheck(new FormValidatorCustom(
            $this,
            'emailMaxAttempts',
            FormValidatorCustom::FORM_VALIDATOR_REQUIRED_VALUE,
            'plugins.generic.coAuthorParticipants.settings.emailMaxAttempts.invalid',
            fn ($value) => ctype_digit((string) $value) && (int) $value >= CoAuthorParticipantsPlugin::EMAIL_MAX_ATTEMPTS_RANGE[0] && (int) $value <= CoAuthorParticipantsPlugin::EMAIL_MAX_ATTEMPTS_RANGE[1]
        ));
        $this->addCheck(new FormValidatorCustom(
            $this,
            'passwordLinkDays',
            FormValidatorCustom::FORM_VALIDATOR_REQUIRED_VALUE,
            'plugins.generic.coAuthorParticipants.settings.passwordLinkDays.invalid',
            fn ($value) => ctype_digit((string) $value) && (int) $value >= CoAuthorParticipantsPlugin::PASSWORD_LINK_DAYS_RANGE[0] && (int) $value <= CoAuthorParticipantsPlugin::PASSWORD_LINK_DAYS_RANGE[1]
        ));
        $this->addCheck(new FormValidatorPost($this));
        $this->addCheck(new FormValidatorCSRF($this));
    }

    /**
     * @copydoc Form::initData()
     */
    public function initData()
    {
        foreach (array_merge(self::BOOLEAN_SETTINGS, self::NUMERIC_SETTINGS) as $name) {
            $this->setData($name, $this->plugin->getJournalSetting($this->context->getId(), $name));
        }

        parent::initData();
    }

    /**
     * @copydoc Form::readInputData()
     */
    public function readInputData()
    {
        $this->readUserVars(array_merge(self::BOOLEAN_SETTINGS, self::NUMERIC_SETTINGS));
    }

    /**
     * @copydoc Form::fetch()
     *
     * @param null|mixed $template
     */
    public function fetch($request, $template = null, $display = false)
    {
        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign([
            'pluginName' => $this->plugin->getName(),
            'authorGroupOptions' => ['' => __('plugins.generic.coAuthorParticipants.settings.authorUserGroupId.default')] + $this->getAuthorGroupOptions(),
            'acknowledgementNotice' => $this->getAcknowledgementNotice(),
        ]);

        return parent::fetch($request, $template, $display);
    }

    /**
     * @copydoc Form::execute()
     */
    public function execute(...$functionArgs)
    {
        $contextId = $this->context->getId();

        foreach (self::BOOLEAN_SETTINGS as $name) {
            $this->plugin->updateSetting($contextId, $name, (bool) $this->getData($name), 'bool');
        }

        $authorGroup = (int) $this->getData('authorUserGroupId');
        $this->plugin->updateSetting($contextId, 'authorUserGroupId', $authorGroup ?: null, 'int');
        $this->plugin->updateSetting($contextId, 'emailMaxAttempts', (int) $this->getData('emailMaxAttempts'), 'int');
        $this->plugin->updateSetting($contextId, 'passwordLinkDays', (int) $this->getData('passwordLinkDays'), 'int');

        parent::execute(...$functionArgs);
    }

    /**
     * Author-role groups of the journal, as select options.
     *
     * @return array<int, string>
     */
    public function getAuthorGroupOptions(): array
    {
        $options = [];
        foreach ($this->plugin->getService()->getAuthorGroups((int) $this->context->getId()) as $id => $group) {
            $options[(int) $id] = $group->getLocalizedData('name');
        }

        return $options;
    }

    /**
     * What the plugin did, or must warn about, with the native acknowledgement.
     */
    public function getAcknowledgementNotice(): string
    {
        $current = $this->context->getData('submissionAcknowledgement');
        $previous = $this->plugin->getSetting($this->context->getId(), CoAuthorParticipantsPlugin::SETTING_PREVIOUS_ACK);

        if ($current === Context::SUBMISSION_ACKNOWLEDGEMENT_ALL_AUTHORS) {
            return __('plugins.generic.coAuthorParticipants.settings.acknowledgement.allAuthors');
        }
        if ($previous) {
            return __('plugins.generic.coAuthorParticipants.settings.acknowledgement.changed');
        }

        return __('plugins.generic.coAuthorParticipants.settings.acknowledgement.compatible');
    }
}

if (!PKP_STRICT_MODE) {
    class_alias('\APP\plugins\generic\coAuthorParticipants\CoAuthorParticipantsSettingsForm', '\CoAuthorParticipantsSettingsForm');
}
