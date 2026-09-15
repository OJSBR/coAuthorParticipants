<?php

/**
 * @file plugins/generic/coAuthorParticipants/classes/CoauthorEmailLogEventType.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @enum CoauthorEmailLogEventType
 *
 * @brief Event type of the assignment email in the submission's email log.
 *
 * The value sits outside the ranges used by PKP\log\SubmissionEmailLogEventType.
 */

namespace APP\plugins\generic\coAuthorParticipants\classes;

use PKP\log\core\EmailLogEventType;

enum CoauthorEmailLogEventType: int implements EmailLogEventType
{
    case COAUTHOR_PARTICIPANT_ASSIGNED = 0x20F00001;
}
