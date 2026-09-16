<?php

/**
 * @file plugins/generic/coAuthorParticipants/classes/ParticipantLog.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ParticipantLog
 *
 * @brief Reads and writes the control table.
 */

namespace APP\plugins\generic\coAuthorParticipants\classes;

use APP\plugins\generic\coAuthorParticipants\classes\migrations\CoauthorParticipantLogMigration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PKP\core\Core;

class ParticipantLog
{
    public const EMAIL_PENDING = 'pending';
    public const EMAIL_SENT = 'sent';
    public const EMAIL_FAILED = 'failed';
    public const EMAIL_SUPPRESSED = 'suppressed';
    /** Transient: a process is delivering the message right now. */
    public const EMAIL_SENDING = 'sending';

    public const TABLE = CoauthorParticipantLogMigration::TABLE;
    public const KEY = 'coauthor_participant_log_id';

    public function find(int $id): ?object
    {
        return DB::table(self::TABLE)->where(self::KEY, $id)->first();
    }

    /**
     * The row of a participant, or of a pending contributor without an account.
     */
    public function findFor(int $submissionId, ?int $userId, ?int $authorId): ?object
    {
        return DB::table(self::TABLE)
            ->where('submission_id', $submissionId)
            ->when(
                $userId !== null,
                fn ($query) => $query->where('user_id', $userId),
                fn ($query) => $query->whereNull('user_id')->where('author_id', $authorId)
            )
            ->first();
    }

    /**
     * Insert or update the row of a participant and return its id.
     *
     * Rows are never downgraded: an email already sent stays sent, and the
     * "created" flags only ever go from false to true.
     */
    public function record(array $data): int
    {
        $now = Core::getCurrentDate();
        $existing = $this->findFor((int) $data['submission_id'], $data['user_id'] ?? null, $data['author_id'] ?? null);

        if ($existing) {
            $update = [
                'author_id' => $data['author_id'] ?? $existing->author_id,
                'user_group_id' => $data['user_group_id'] ?? $existing->user_group_id,
                'status' => $data['status'],
                'account_created' => (bool) $existing->account_created || !empty($data['account_created']),
                'assignment_created' => (bool) $existing->assignment_created || !empty($data['assignment_created']),
                'updated_at' => $now,
            ];
            if (array_key_exists('last_error', $data)) {
                $update['last_error'] = $data['last_error'];
            }
            if (isset($data['email_status']) && !in_array($existing->email_status, [self::EMAIL_SENT, self::EMAIL_SENDING], true)) {
                $update['email_status'] = $data['email_status'];
            }
            DB::table(self::TABLE)->where(self::KEY, $existing->{self::KEY})->update($update);
            return (int) $existing->{self::KEY};
        }

        $row = [
            'context_id' => $data['context_id'],
            'submission_id' => $data['submission_id'],
            'author_id' => $data['author_id'] ?? null,
            'user_id' => $data['user_id'] ?? null,
            'user_group_id' => $data['user_group_id'] ?? null,
            'source' => $data['source'],
            'status' => $data['status'],
            'account_created' => !empty($data['account_created']),
            'assignment_created' => !empty($data['assignment_created']),
            'email_status' => $data['email_status'] ?? self::EMAIL_SUPPRESSED,
            'email_attempts' => 0,
            'last_error' => $data['last_error'] ?? null,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        try {
            // The key of this table is not "id": PostgreSQL needs the column
            // named, or the insert comes back with `returning "id"` and fails.
            return (int) DB::table(self::TABLE)->insertGetId($row, self::KEY);
        } catch (QueryException $e) {
            if (CoauthorAccountService::isConcurrencyError($e)) {
                // Never look the row up again here: inside the same transaction
                // the snapshot would not show it. Let the caller start over.
                throw new ConcurrentUpdateException('Another process recorded the same participant first.', 0, $e);
            }
            throw $e;
        }
    }

    /**
     * Make a row sendable again, for an explicit retry from the command line.
     */
    public function resetForRetry(int $id): void
    {
        $this->update($id, ['email_status' => self::EMAIL_PENDING, 'email_attempts' => 0]);
    }

    public function update(int $id, array $data): void
    {
        $data['updated_at'] = Core::getCurrentDate();
        DB::table(self::TABLE)->where(self::KEY, $id)->update($data);
    }

    /**
     * Claim a row for sending, so two workers never send the same message.
     *
     * @return bool true when this process owns the attempt
     */
    public function claimForSending(int $id, int $maxAttempts): bool
    {
        return DB::table(self::TABLE)
            ->where(self::KEY, $id)
            ->whereIn('email_status', [self::EMAIL_PENDING, self::EMAIL_FAILED])
            ->where('email_attempts', '<', $maxAttempts)
            ->update([
                'email_status' => self::EMAIL_SENDING,
                'email_attempts' => DB::raw('email_attempts + 1'),
                'updated_at' => Core::getCurrentDate(),
            ]) === 1;
    }

    /**
     * Rows whose email failed, is still pending, or was left "sending" by a
     * process that died more than an hour ago, for an explicit retry.
     */
    public function getRetryable(int $contextId, ?int $submissionId = null): array
    {
        $staleBefore = date('Y-m-d H:i:s', strtotime(Core::getCurrentDate()) - 3600);

        return DB::table(self::TABLE)
            ->where('context_id', $contextId)
            ->where(fn ($query) => $query
                ->whereIn('email_status', [self::EMAIL_PENDING, self::EMAIL_FAILED])
                ->orWhere(fn ($query) => $query->where('email_status', self::EMAIL_SENDING)->where('updated_at', '<', $staleBefore)))
            ->whereNotNull('user_id')
            ->when($submissionId, fn ($query) => $query->where('submission_id', $submissionId))
            ->orderBy(self::KEY)
            ->get()
            ->all();
    }
}
