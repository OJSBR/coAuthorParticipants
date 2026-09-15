<?php

/**
 * @file plugins/generic/coAuthorParticipants/classes/SyncResult.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class SyncResult
 *
 * @brief Counters and per-contributor details of one or more synchronizations.
 */

namespace APP\plugins\generic\coAuthorParticipants\classes;

class SyncResult
{
    /** Outcomes of one contributor. */
    public const OUTCOME_LINKED = 'linked';
    public const OUTCOME_ALREADY_PARTICIPANT = 'already_participant';
    public const OUTCOME_INVALID_EMAIL = 'invalid_email';
    public const OUTCOME_INVALID_GROUP = 'invalid_group';
    public const OUTCOME_DISABLED_ACCOUNT = 'disabled_account';
    public const OUTCOME_NO_ACCOUNT = 'no_account';
    public const OUTCOME_ERROR = 'error';

    /** @var array<string, int> */
    public array $counters = [
        'submissionsExamined' => 0,
        'submissionsEligible' => 0,
        'contributorsExamined' => 0,
        'existingUsersFound' => 0,
        'usersCreated' => 0,
        'authorRolesAssigned' => 0,
        'participationsCreated' => 0,
        'participationsExisting' => 0,
        'invalidEmails' => 0,
        'invalidGroups' => 0,
        'disabledAccounts' => 0,
        'pendingWithoutAccount' => 0,
        'emailsQueued' => 0,
        'emailsSent' => 0,
        'emailsPendingOrFailed' => 0,
        'errors' => 0,
    ];

    /** @var array<int, array<string, mixed>> */
    public array $details = [];

    public ?int $lastSubmissionId = null;

    public function increment(string $counter, int $by = 1): void
    {
        $this->counters[$counter] = ($this->counters[$counter] ?? 0) + $by;
    }

    public function addDetail(array $detail): void
    {
        $this->details[] = $detail;
    }

    public function hasErrors(): bool
    {
        return $this->counters['errors'] > 0;
    }

    /**
     * Add the counters and details of another result to this one.
     */
    public function merge(SyncResult $other): void
    {
        foreach ($other->counters as $name => $value) {
            $this->increment($name, $value);
        }
        array_push($this->details, ...$other->details);
        $this->lastSubmissionId = $other->lastSubmissionId ?? $this->lastSubmissionId;
    }

    public function toArray(): array
    {
        return [
            'counters' => $this->counters,
            'lastSubmissionId' => $this->lastSubmissionId,
            'details' => $this->details,
        ];
    }
}
