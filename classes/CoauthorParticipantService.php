<?php

/**
 * @file plugins/generic/coAuthorParticipants/classes/CoauthorParticipantService.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class CoauthorParticipantService
 *
 * @brief The one implementation of the rule, used by the submission listener,
 *  the contributor hooks and the command line backfill.
 *
 * For each contributor with an author role: find the account by email (or
 * create it), make sure it holds the author group in the journal, and assign it
 * to the submission. Each contributor runs in its own transaction; the email is
 * only handed over after the commit.
 */

namespace APP\plugins\generic\coAuthorParticipants\classes;

use APP\author\Author;
use APP\facades\Repo;
use APP\plugins\generic\coAuthorParticipants\CoAuthorParticipantsPlugin;
use APP\submission\Submission;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PKP\context\Context;
use PKP\security\Role;
use PKP\stageAssignment\StageAssignment;
use PKP\user\User;
use PKP\userGroup\UserGroup;
use Throwable;

class CoauthorParticipantService
{
    /** How many times a contributor is run again after losing a race. */
    public const CONCURRENCY_ATTEMPTS = 3;

    /** Statuses a backfill may process: in the workflow and not yet published. */
    public const ELIGIBLE_STATUSES = [Submission::STATUS_QUEUED, Submission::STATUS_SCHEDULED];

    protected CoauthorAccountService $accounts;
    protected CoauthorNotificationService $notifications;
    protected ParticipantLog $log;

    public function __construct(protected CoAuthorParticipantsPlugin $plugin)
    {
        $this->accounts = new CoauthorAccountService();
        $this->notifications = new CoauthorNotificationService($plugin);
        $this->log = new ParticipantLog();
    }

    /**
     * Whether a submission was completed and is still in the editorial workflow.
     */
    public static function isEligible(Submission $submission): bool
    {
        return static::isEligibleData(
            $submission->getData('dateSubmitted'),
            $submission->getData('submissionProgress'),
            $submission->getData('status')
        );
    }

    public static function isEligibleData(mixed $dateSubmitted, mixed $submissionProgress, mixed $status): bool
    {
        return $dateSubmitted !== null
            && $dateSubmitted !== ''
            && empty($submissionProgress)
            && in_array((int) $status, self::ELIGIBLE_STATUSES, true);
    }

    /**
     * Link the co-authors of one submission.
     */
    public function synchronizeSubmission(Submission $submission, Context $context, SyncOptions $options): SyncResult
    {
        $result = new SyncResult();
        $result->increment('submissionsExamined');
        $result->lastSubmissionId = (int) $submission->getId();

        // Read the submission again: it may have been published or declined
        // since it was selected.
        $submission = Repo::submission()->get((int) $submission->getId());
        if (!$submission || (int) $submission->getData('contextId') !== (int) $context->getId()) {
            $this->addDetail($result, $options, $context, null, null, null, SyncResult::OUTCOME_ERROR, 'Submission not found in this journal.');
            $result->increment('errors');
            return $result;
        }

        if (!static::isEligible($submission)) {
            $this->addDetail($result, $options, $context, $submission->getId(), null, null, 'not_eligible');
            return $result;
        }
        $result->increment('submissionsEligible');

        $publication = $submission->getCurrentPublication();
        if (!$publication) {
            $this->addDetail($result, $options, $context, $submission->getId(), null, null, SyncResult::OUTCOME_ERROR, 'Submission without a current publication.');
            $result->increment('errors');
            return $result;
        }

        $authorGroups = $this->getAuthorGroups((int) $context->getId());
        // Every co-author is linked with the same author group, whatever group
        // was chosen for them in the contributor form.
        $userGroup = $this->pickAuthorGroup($authorGroups, $options->authorUserGroupId);

        $authors = Repo::author()->getCollector()
            ->filterByPublicationIds([$publication->getId()])
            ->getMany();

        foreach ($authors as $author) {
            $result->increment('contributorsExamined');
            try {
                $this->synchronizeContributor($submission, $context, $author, $userGroup, $options, $result);
            } catch (Throwable $e) {
                $result->increment('errors');
                $this->addDetail($result, $options, $context, $submission->getId(), $author->getId(), null, SyncResult::OUTCOME_ERROR, $e->getMessage());
            }
        }

        return $result;
    }

    /**
     * Link one contributor.
     */
    protected function synchronizeContributor(
        Submission $submission,
        Context $context,
        Author $author,
        ?UserGroup $userGroup,
        SyncOptions $options,
        SyncResult $result
    ): void {
        $submissionId = (int) $submission->getId();
        $authorId = (int) $author->getId();

        $email = CoauthorAccountService::normalizeEmail($author->getData('email'));
        if ($email === null) {
            $result->increment('invalidEmails');
            $this->addDetail($result, $options, $context, $submissionId, $authorId, null, SyncResult::OUTCOME_INVALID_EMAIL);
            return;
        }

        if (!$userGroup) {
            $result->increment('invalidGroups');
            $this->addDetail($result, $options, $context, $submissionId, $authorId, null, SyncResult::OUTCOME_INVALID_GROUP);
            return;
        }

        if ($options->dryRun) {
            $this->simulateContributor($submission, $context, $author, $email, $userGroup, $options, $result);
            return;
        }

        $outcome = $this->runWithConcurrencyRetries(
            fn () => DB::transaction(fn () => $this->linkContributor($submission, $context, $author, $email, $userGroup, $options))
        );

        foreach ($outcome['counters'] as $counter) {
            $result->increment($counter);
        }

        // The email is handed over only after the commit, and never inside a
        // transaction held open during SMTP.
        if ($outcome['emailStatus'] === ParticipantLog::EMAIL_PENDING) {
            $delivery = $this->notifications->deliver($outcome['logId'], $options);
            $result->increment($delivery);
        }

        $this->addDetail(
            $result,
            $options,
            $context,
            $submissionId,
            $authorId,
            $outcome['userId'],
            $outcome['status'],
            null,
            [
                'userGroupId' => $userGroup->id,
                'accountCreated' => $outcome['accountCreated'],
                'assignmentCreated' => $outcome['assignmentCreated'],
                'emailStatus' => $outcome['emailStatus'],
            ]
        );
    }

    /**
     * Run a contributor's transaction again when another process wrote the same
     * rows first. Each attempt is a new transaction, so it sees what was committed.
     */
    protected function runWithConcurrencyRetries(callable $work): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $work();
            } catch (ConcurrentUpdateException $e) {
                if ($attempt >= self::CONCURRENCY_ATTEMPTS) {
                    throw $e;
                }
            } catch (QueryException $e) {
                if ($attempt >= self::CONCURRENCY_ATTEMPTS || !CoauthorAccountService::isConcurrencyError($e)) {
                    throw $e;
                }
            }
            usleep(random_int(50_000, 250_000));
        }
    }

    /**
     * The writes for one contributor, run inside a transaction.
     *
     * @return array{status: string, userId: ?int, logId: ?int, accountCreated: bool, assignmentCreated: bool, emailStatus: string, counters: string[]}
     */
    protected function linkContributor(Submission $submission, Context $context, Author $author, string $email, UserGroup $userGroup, SyncOptions $options): array
    {
        $submissionId = (int) $submission->getId();
        $record = [
            'context_id' => $context->getId(),
            'submission_id' => $submissionId,
            'author_id' => $author->getId(),
            'user_group_id' => $userGroup->id,
            'source' => $options->source,
        ];
        $outcome = [
            'status' => SyncResult::OUTCOME_LINKED,
            'userId' => null,
            'logId' => null,
            'accountCreated' => false,
            'assignmentCreated' => false,
            'emailStatus' => ParticipantLog::EMAIL_SUPPRESSED,
            'counters' => [],
        ];

        $user = $this->accounts->findByEmail($email);

        if ($user && $user->getDisabled()) {
            // Never duplicated, never reactivated, never sent an access link.
            $outcome['status'] = SyncResult::OUTCOME_DISABLED_ACCOUNT;
            $outcome['userId'] = (int) $user->getId();
            $outcome['counters'][] = 'disabledAccounts';
            $outcome['logId'] = $this->log->record($record + ['user_id' => $user->getId(), 'status' => $outcome['status'], 'email_status' => ParticipantLog::EMAIL_SUPPRESSED]);
            return $outcome;
        }

        if (!$user) {
            if (!$options->createAccounts) {
                $outcome['status'] = SyncResult::OUTCOME_NO_ACCOUNT;
                $outcome['counters'][] = 'pendingWithoutAccount';
                $outcome['logId'] = $this->log->record($record + ['user_id' => null, 'status' => $outcome['status'], 'email_status' => ParticipantLog::EMAIL_SUPPRESSED]);
                return $outcome;
            }

            $user = $this->accounts->createFromAuthor($author, $email, $context, (string) $submission->getData('locale'));
            $outcome['accountCreated'] = true;
            $outcome['counters'][] = 'usersCreated';
        } else {
            $outcome['counters'][] = 'existingUsersFound';
        }

        $userId = (int) $user->getId();
        $outcome['userId'] = $userId;

        // Serialize concurrent runs for the same person: the group assignment
        // table has no unique key to fall back on.
        DB::table('users')->where('user_id', $userId)->lockForUpdate()->first();

        if (StageAssignment::withSubmissionIds([$submissionId])->withUserId($userId)->exists()) {
            $outcome['status'] = SyncResult::OUTCOME_ALREADY_PARTICIPANT;
            $outcome['counters'][] = 'participationsExisting';
            $existingRow = $this->log->findFor($submissionId, $userId, null);
            $outcome['emailStatus'] = $existingRow->email_status ?? ParticipantLog::EMAIL_SUPPRESSED;
            $outcome['logId'] = $this->log->record($record + [
                'user_id' => $userId,
                'status' => $existingRow ? $existingRow->status : $outcome['status'],
                'account_created' => $outcome['accountCreated'],
            ]);
            // Only a message that was never handed over is still due.
            if ($outcome['emailStatus'] === ParticipantLog::EMAIL_PENDING && (int) ($existingRow->email_attempts ?? 0) > 0) {
                $outcome['emailStatus'] = ParticipantLog::EMAIL_SUPPRESSED;
            }
            return $outcome;
        }

        if (!Repo::userGroup()->userInGroup($userId, (int) $userGroup->id)) {
            Repo::userGroup()->assignUserToGroup($userId, (int) $userGroup->id);
            $outcome['counters'][] = 'authorRolesAssigned';
        }

        try {
            Repo::stageAssignment()->build($submissionId, (int) $userGroup->id, $userId, false, (bool) $userGroup->permitMetadataEdit);
        } catch (QueryException $e) {
            if (CoauthorAccountService::isConcurrencyError($e)) {
                throw new ConcurrentUpdateException('Another process assigned the same person first.', 0, $e);
            }
            throw $e;
        }
        $outcome['assignmentCreated'] = true;
        $outcome['counters'][] = 'participationsCreated';

        $notify = $outcome['assignmentCreated'] && $options->shouldNotify($outcome['accountCreated']);
        $outcome['emailStatus'] = $notify ? ParticipantLog::EMAIL_PENDING : ParticipantLog::EMAIL_SUPPRESSED;
        $outcome['logId'] = $this->log->record($record + [
            'user_id' => $userId,
            'status' => $outcome['status'],
            'account_created' => $outcome['accountCreated'],
            'assignment_created' => $outcome['assignmentCreated'],
            'email_status' => $outcome['emailStatus'],
        ]);

        return $outcome;
    }

    /**
     * What linkContributor() would do, without writing anything.
     */
    protected function simulateContributor(Submission $submission, Context $context, Author $author, string $email, UserGroup $userGroup, SyncOptions $options, SyncResult $result): void
    {
        $submissionId = (int) $submission->getId();
        $user = $this->accounts->findByEmail($email);
        $extra = ['userGroupId' => $userGroup->id, 'dryRun' => true];

        if ($user && $user->getDisabled()) {
            $result->increment('disabledAccounts');
            $this->addDetail($result, $options, $context, $submissionId, $author->getId(), $user->getId(), SyncResult::OUTCOME_DISABLED_ACCOUNT, null, $extra);
            return;
        }

        if (!$user) {
            if (!$options->createAccounts) {
                $result->increment('pendingWithoutAccount');
                $this->addDetail($result, $options, $context, $submissionId, $author->getId(), null, SyncResult::OUTCOME_NO_ACCOUNT, null, $extra);
                return;
            }
            $result->increment('usersCreated');
            $result->increment('authorRolesAssigned');
            $result->increment('participationsCreated');
            if ($options->shouldNotify(true)) {
                $result->increment('emailsQueued');
            }
            $this->addDetail($result, $options, $context, $submissionId, $author->getId(), null, SyncResult::OUTCOME_LINKED, null, $extra + ['accountCreated' => true]);
            return;
        }

        $result->increment('existingUsersFound');
        $userId = (int) $user->getId();
        if (StageAssignment::withSubmissionIds([$submissionId])->withUserId($userId)->exists()) {
            $result->increment('participationsExisting');
            $this->addDetail($result, $options, $context, $submissionId, $author->getId(), $userId, SyncResult::OUTCOME_ALREADY_PARTICIPANT, null, $extra);
            return;
        }

        if (!Repo::userGroup()->userInGroup($userId, (int) $userGroup->id)) {
            $result->increment('authorRolesAssigned');
        }
        $result->increment('participationsCreated');
        if ($options->shouldNotify(false)) {
            $result->increment('emailsQueued');
        }
        $this->addDetail($result, $options, $context, $submissionId, $author->getId(), $userId, SyncResult::OUTCOME_LINKED, null, $extra + ['accountCreated' => false]);
    }

    /**
     * Author-role groups of the journal that give access to the workflow,
     * keyed by id, in id order.
     *
     * Having the author role is not enough: a group that is not assigned to
     * the submission stage (in some journals the "Translator" group has no
     * stage at all) produces an assignment that grants no access and does not
     * even show among the participants. Such groups are never used.
     */
    public function getAuthorGroups(int $contextId): Collection
    {
        return UserGroup::withContextIds([$contextId])
            ->withRoleIds([Role::ROLE_ID_AUTHOR])
            ->whereHas('userGroupStages', fn ($query) => $query->where('stage_id', WORKFLOW_STAGE_ID_SUBMISSION))
            ->orderBy('user_group_id')
            ->get()
            ->keyBy(fn (UserGroup $group) => (int) $group->id);
    }

    /**
     * The group every co-author is linked with: the configured one when it is a
     * valid author group of the journal, otherwise the journal's author group
     * open to self-registration (the "Author" group of a standard install),
     * otherwise its first valid author group.
     */
    public function pickAuthorGroup(Collection $authorGroups, ?int $configuredId): ?UserGroup
    {
        if ($configuredId && $authorGroups->has($configuredId)) {
            return $authorGroups->get($configuredId);
        }

        return $authorGroups->first(fn (UserGroup $group) => (bool) $group->permitSelfRegistration)
            ?? $authorGroups->first();
    }

    /**
     * Record one contributor's outcome in the result and in the application log.
     */
    protected function addDetail(SyncResult $result, SyncOptions $options, Context $context, ?int $submissionId, ?int $authorId, ?int $userId, string $outcome, ?string $error = null, array $extra = []): void
    {
        $detail = [
            'contextId' => (int) $context->getId(),
            'submissionId' => $submissionId,
            'authorId' => $authorId,
            'userId' => $userId,
            'source' => $options->source,
            'outcome' => $outcome,
        ] + $extra + ($error !== null ? ['error' => $error] : []);

        $result->addDetail($detail);

        // Errors are always logged; the rest only when the journal asks for it.
        // Never a password, hash or token: none of them is part of a detail.
        if ($error !== null || ($options->logDetails && !$options->dryRun && $outcome !== 'not_eligible')) {
            error_log('[coAuthorParticipants] ' . json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
    }

    public function getNotificationService(): CoauthorNotificationService
    {
        return $this->notifications;
    }
}
