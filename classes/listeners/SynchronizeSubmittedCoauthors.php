<?php

/**
 * @file plugins/generic/coAuthorParticipants/classes/listeners/SynchronizeSubmittedCoauthors.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class SynchronizeSubmittedCoauthors
 *
 * @brief Links the co-authors once a submission has been completed.
 */

namespace APP\plugins\generic\coAuthorParticipants\classes\listeners;

use APP\plugins\generic\coAuthorParticipants\classes\SyncOptions;
use APP\plugins\generic\coAuthorParticipants\CoAuthorParticipantsPlugin;
use PKP\observers\events\SubmissionSubmitted;
use Throwable;

class SynchronizeSubmittedCoauthors
{
    public function __construct(protected CoAuthorParticipantsPlugin $plugin)
    {
    }

    public function handle(SubmissionSubmitted $event): void
    {
        try {
            $contextId = (int) $event->context->getId();
            if (!$this->plugin->getEnabled($contextId) || !$this->plugin->getJournalSetting($contextId, 'autoLink')) {
                return;
            }

            $this->plugin->getService()->synchronizeSubmission(
                $event->submission,
                $event->context,
                SyncOptions::fromJournalSettings($this->plugin, $contextId, SyncOptions::SOURCE_AUTOMATIC)
            );
        } catch (Throwable $e) {
            // Repository::submit() does not protect its listeners: an exception
            // here would fail the author's request after the submission was saved.
            error_log('[coAuthorParticipants] Automatic linking failed for submission ' . $event->submission->getId() . ': ' . $e->getMessage());
        }
    }
}
