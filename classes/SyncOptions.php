<?php

/**
 * @file plugins/generic/coAuthorParticipants/classes/SyncOptions.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class SyncOptions
 *
 * @brief How one synchronization run behaves. Built from the journal settings
 *  for the automatic runs and from the command line options for the backfill.
 */

namespace APP\plugins\generic\coAuthorParticipants\classes;

use APP\plugins\generic\coAuthorParticipants\CoAuthorParticipantsPlugin;

class SyncOptions
{
    public const SOURCE_AUTOMATIC = 'automatic';
    public const SOURCE_CLI = 'cli';

    /** Notify every newly linked participant. */
    public const EMAIL_ALL = 'all';
    /** Notify only the accounts created by the plugin. */
    public const EMAIL_NEW = 'new';
    /** Do not notify anyone. */
    public const EMAIL_NONE = 'none';

    /** How the notification is delivered. */
    public const DELIVERY_QUEUE = 'queue';
    public const DELIVERY_IMMEDIATE = 'immediate';

    public function __construct(
        public string $source = self::SOURCE_AUTOMATIC,
        public bool $dryRun = false,
        public bool $createAccounts = true,
        public ?int $authorUserGroupId = null,
        public string $sendEmail = self::EMAIL_ALL,
        public string $delivery = self::DELIVERY_QUEUE,
        public bool $logDetails = true,
        public int $emailMaxAttempts = 3,
        public int $passwordLinkDays = 7,
    ) {
    }

    /**
     * Options of an automatic run, taken from the journal settings.
     */
    public static function fromJournalSettings(CoAuthorParticipantsPlugin $plugin, int $contextId, string $source): self
    {
        return new self(
            source: $source,
            createAccounts: $plugin->getJournalSetting($contextId, 'createAccounts'),
            authorUserGroupId: $plugin->getJournalSetting($contextId, 'authorUserGroupId'),
            sendEmail: $plugin->getJournalSetting($contextId, 'sendEmail') ? self::EMAIL_ALL : self::EMAIL_NONE,
            delivery: self::DELIVERY_QUEUE,
            logDetails: $plugin->getJournalSetting($contextId, 'logDetails'),
            emailMaxAttempts: $plugin->getJournalSetting($contextId, 'emailMaxAttempts'),
            passwordLinkDays: $plugin->getJournalSetting($contextId, 'passwordLinkDays'),
        );
    }

    /**
     * Whether a newly linked participant should be notified.
     */
    public function shouldNotify(bool $accountCreated): bool
    {
        return match ($this->sendEmail) {
            self::EMAIL_ALL => true,
            self::EMAIL_NEW => $accountCreated,
            default => false,
        };
    }
}
