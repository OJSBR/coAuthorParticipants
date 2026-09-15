<?php

/**
 * @file plugins/generic/coAuthorParticipants/classes/jobs/SendCoauthorAssignmentEmail.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class SendCoauthorAssignmentEmail
 *
 * @brief Queue job that sends the assignment email of one control row, so the
 *  author completing a submission never waits for SMTP.
 */

namespace APP\plugins\generic\coAuthorParticipants\classes\jobs;

use APP\plugins\generic\coAuthorParticipants\classes\CoauthorNotificationService;
use PKP\jobs\BaseJob;

class SendCoauthorAssignmentEmail extends BaseJob
{
    /** Seconds before a failed delivery is tried again by the worker. */
    public int $backoff = 300;

    public function __construct(
        protected int $logId,
        protected int $maxAttempts,
        protected int $passwordLinkDays
    ) {
        parent::__construct();
        $this->tries = $maxAttempts;
    }

    /**
     * @copydoc BaseJob::handle()
     */
    public function handle(): void
    {
        (new CoauthorNotificationService())->send($this->logId, $this->maxAttempts, $this->passwordLinkDays, true);
    }
}
