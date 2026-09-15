<?php

/**
 * @file plugins/generic/coAuthorParticipants/classes/mailables/CoauthorParticipantAssigned.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class CoauthorParticipantAssigned
 *
 * @brief Tells a co-author that their account now takes part in the submission.
 */

namespace APP\plugins\generic\coAuthorParticipants\classes\mailables;

use APP\submission\Submission;
use PKP\context\Context;
use PKP\mail\Mailable;
use PKP\mail\traits\Configurable;
use PKP\mail\traits\Recipient;
use PKP\security\Role;

class CoauthorParticipantAssigned extends Mailable
{
    use Recipient;
    use Configurable;

    public const ACCOUNT_ACCESS_INSTRUCTIONS = 'accountAccessInstructions';
    public const PASSWORD_RESET_URL = 'passwordResetUrl';

    protected static ?string $name = 'emails.coauthorParticipantAssigned.name';
    protected static ?string $description = 'emails.coauthorParticipantAssigned.description';
    protected static ?string $emailTemplateKey = 'COAUTHOR_PARTICIPANT_ASSIGNED';
    protected static array $groupIds = [self::GROUP_SUBMISSION];
    protected static array $fromRoleIds = [self::FROM_SYSTEM];
    protected static array $toRoleIds = [Role::ROLE_ID_AUTHOR];

    public function __construct(Context $context, Submission $submission)
    {
        parent::__construct(func_get_args());
    }

    /**
     * @copydoc Mailable::getDataDescriptions()
     */
    public static function getDataDescriptions(): array
    {
        return array_merge(parent::getDataDescriptions(), [
            self::ACCOUNT_ACCESS_INSTRUCTIONS => __('emails.coauthorParticipantAssigned.variable.accountAccessInstructions'),
            self::PASSWORD_RESET_URL => __('emails.coauthorParticipantAssigned.variable.passwordResetUrl'),
        ]);
    }
}
