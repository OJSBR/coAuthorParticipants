<?php

/**
 * @file plugins/generic/coAuthorParticipants/tools/backfill.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class CoauthorParticipantsBackfillTool
 *
 * @brief Links the co-authors of submissions that were completed before the
 *  plugin was enabled and are still in the editorial workflow.
 *
 * Exit codes: 0 finished without errors, 1 finished with partial errors,
 * 2 invalid parameters or initialization failure.
 */

require dirname(__FILE__, 5) . '/tools/bootstrap.php';

use APP\core\Application;
use APP\core\PageRouter;
use APP\plugins\generic\coAuthorParticipants\classes\BackfillOptions;
use APP\plugins\generic\coAuthorParticipants\classes\CoauthorNotificationService;
use APP\plugins\generic\coAuthorParticipants\classes\CoauthorParticipantService;
use APP\plugins\generic\coAuthorParticipants\classes\ParticipantLog;
use APP\plugins\generic\coAuthorParticipants\classes\SyncOptions;
use APP\plugins\generic\coAuthorParticipants\classes\SyncResult;
use APP\plugins\generic\coAuthorParticipants\CoAuthorParticipantsPlugin;
use Illuminate\Support\Facades\DB;
use PKP\cliTool\CommandLineTool;
use PKP\context\Context;
use PKP\plugins\PluginRegistry;

class CoauthorParticipantsBackfillTool extends CommandLineTool
{
    public const EXIT_OK = 0;
    public const EXIT_PARTIAL = 1;
    public const EXIT_INVALID = 2;

    /** @var array<string, string|bool> */
    public array $options = [];

    public function __construct($argv = [])
    {
        parent::__construct($argv);
    }

    /**
     * @copydoc CommandLineTool::usage()
     */
    public function usage()
    {
        echo <<<'USAGE'
Links the co-authors of completed submissions that are still in the editorial
workflow (queued or scheduled, not yet published) as participants.

Usage:
  php plugins/generic/coAuthorParticipants/tools/backfill.php --journal=<path or id> (--dry-run | --execute) [options]

Required:
  --journal=<path or id>    Journal to process. All journals are never processed implicitly.
  --dry-run                 Only report what would be done. Nothing is written or sent.
  --execute                 Apply the changes. Mutually exclusive with --dry-run.

Options:
  --submission-id=<id>      Only this submission.
  --after-id=<id>           Only submissions with a higher id (resume a previous run).
  --batch-size=<n>          Submissions per batch. Default 100, maximum 500.
  --send-email=all|new|none Notify every newly linked participant, only the accounts
                            created by the plugin, or nobody. Default: none.
  --retry-failed-emails     Only send again the emails that failed or are pending.
                            No contributor is linked in this mode.
  --from-date=YYYY-MM-DD    Submitted on or after this date.
  --to-date=YYYY-MM-DD      Submitted on or before this date.
  --output=<file.json>      Save the detailed report as JSON.

Exit codes: 0 without errors, 1 with partial errors, 2 invalid parameters.

USAGE;
    }

    /**
     * Run the tool and return the exit code.
     */
    public function run(): int
    {
        $options = BackfillOptions::parse($this->argv);
        if ($options === null || empty($options) || isset($options['help'])) {
            $this->usage();
            return self::EXIT_INVALID;
        }

        $errors = BackfillOptions::validate($options);
        if ($errors) {
            fwrite(STDERR, implode("\n", $errors) . "\n\n");
            $this->usage();
            return self::EXIT_INVALID;
        }
        $this->options = $options;

        $context = $this->findJournal((string) $options['journal']);
        if (!$context) {
            fwrite(STDERR, "Journal not found: {$options['journal']}\n");
            return self::EXIT_INVALID;
        }
        $this->useContext($context);

        $plugin = $this->getPlugin();
        if (!$plugin) {
            fwrite(STDERR, "The Coauthor Participants plugin could not be loaded.\n");
            return self::EXIT_INVALID;
        }
        if (!$plugin->getEnabled($context->getId())) {
            fwrite(STDERR, 'The plugin is not enabled in the journal ' . $context->getPath() . ". Enable it before running the backfill.\n");
            return self::EXIT_INVALID;
        }

        $syncOptions = $this->buildSyncOptions($plugin, $context);
        $result = isset($options['retry-failed-emails'])
            ? $this->retryEmails($plugin, $context, $syncOptions)
            : $this->processSubmissions($plugin, $context, $syncOptions);

        $this->printSummary($context, $syncOptions, $result);

        if (isset($options['output']) && !$this->writeReport((string) $options['output'], $context, $syncOptions, $result)) {
            return self::EXIT_PARTIAL;
        }

        return $result->hasErrors() ? self::EXIT_PARTIAL : self::EXIT_OK;
    }

    /**
     * @copydoc CommandLineTool::execute()
     */
    public function execute()
    {
        exit($this->run());
    }

    protected function findJournal(string $pathOrId): ?Context
    {
        $contextDao = Application::getContextDAO();
        $context = ctype_digit($pathOrId) ? $contextDao->getById((int) $pathOrId) : null;

        return $context ?? $contextDao->getByPath($pathOrId);
    }

    /**
     * URLs in the email and the journal locale need the request to know the
     * journal, which a command line request does not.
     */
    protected function useContext(Context $context): void
    {
        $application = Application::get();
        $router = new class () extends PageRouter {
            public ?Context $fixedContext = null;

            public function getContext(\PKP\core\PKPRequest $request, bool $forceReload = false): ?Context
            {
                return $this->fixedContext;
            }
        };
        $router->fixedContext = $context;
        $router->setApplication($application);
        $application->getRequest()->setRouter($router);
    }

    protected function getPlugin(): ?CoAuthorParticipantsPlugin
    {
        $plugin = PluginRegistry::getPlugin('generic', 'coauthorparticipantsplugin')
            ?? PluginRegistry::loadPlugin('generic', 'coAuthorParticipants');

        return $plugin instanceof CoAuthorParticipantsPlugin ? $plugin : null;
    }

    protected function buildSyncOptions(CoAuthorParticipantsPlugin $plugin, Context $context): SyncOptions
    {
        $syncOptions = SyncOptions::fromJournalSettings($plugin, (int) $context->getId(), SyncOptions::SOURCE_CLI);
        $syncOptions->dryRun = isset($this->options['dry-run']);
        $syncOptions->sendEmail = (string) ($this->options['send-email'] ?? SyncOptions::EMAIL_NONE);
        $syncOptions->delivery = SyncOptions::DELIVERY_IMMEDIATE;
        // The command line prints the report; it does not fill the error log
        // with one line per contributor unless something fails.
        $syncOptions->logDetails = false;

        return $syncOptions;
    }

    /**
     * Select the candidates with a positive list of statuses, in id order and
     * in batches, and run the service on each one.
     */
    protected function processSubmissions(CoAuthorParticipantsPlugin $plugin, Context $context, SyncOptions $syncOptions): SyncResult
    {
        $result = new SyncResult();
        $service = $plugin->getService();
        $batchSize = (int) ($this->options['batch-size'] ?? BackfillOptions::DEFAULT_BATCH_SIZE);
        $cursor = (int) ($this->options['after-id'] ?? 0);

        do {
            $ids = $this->selectCandidateIds((int) $context->getId(), $cursor, $batchSize);
            foreach ($ids as $submissionId) {
                $submission = \APP\facades\Repo::submission()->get($submissionId);
                if (!$submission) {
                    continue;
                }
                $result->merge($service->synchronizeSubmission($submission, $context, $syncOptions));
                $cursor = $submissionId;
            }
            if ($ids) {
                fwrite(STDOUT, sprintf("... processed up to submission %d\n", $cursor));
            }
        } while (count($ids) === $batchSize && !isset($this->options['submission-id']));

        return $result;
    }

    /**
     * @return int[]
     */
    public function selectCandidateIds(int $contextId, int $afterId, int $limit): array
    {
        $query = DB::table('submissions')
            ->where('context_id', $contextId)
            ->where('submission_id', '>', $afterId)
            ->orderBy('submission_id')
            ->limit($limit);

        if (isset($this->options['submission-id'])) {
            // An explicit submission is examined even if it is not eligible, so
            // the report can say why it was left alone.
            return $query->where('submission_id', (int) $this->options['submission-id'])->pluck('submission_id')->map(fn ($id) => (int) $id)->all();
        }

        $query->whereIn('status', CoauthorParticipantService::ELIGIBLE_STATUSES)
            ->whereNotNull('date_submitted')
            ->where(fn ($q) => $q->whereNull('submission_progress')->orWhere('submission_progress', ''));

        if (isset($this->options['from-date'])) {
            $query->where('date_submitted', '>=', $this->options['from-date'] . ' 00:00:00');
        }
        if (isset($this->options['to-date'])) {
            $query->where('date_submitted', '<=', $this->options['to-date'] . ' 23:59:59');
        }

        return $query->pluck('submission_id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Send again the emails that failed or are still pending.
     */
    protected function retryEmails(CoAuthorParticipantsPlugin $plugin, Context $context, SyncOptions $syncOptions): SyncResult
    {
        $result = new SyncResult();
        $log = new ParticipantLog();
        $notifications = new CoauthorNotificationService($plugin);
        $submissionId = isset($this->options['submission-id']) ? (int) $this->options['submission-id'] : null;

        foreach ($log->getRetryable((int) $context->getId(), $submissionId) as $row) {
            $rowId = (int) $row->{ParticipantLog::KEY};
            $detail = ['submissionId' => (int) $row->submission_id, 'userId' => (int) $row->user_id, 'outcome' => 'email_retry'];
            if ($syncOptions->dryRun) {
                $result->increment('emailsPendingOrFailed');
                $result->addDetail($detail + ['dryRun' => true, 'emailStatus' => $row->email_status]);
                continue;
            }
            $log->resetForRetry($rowId);
            $status = $notifications->send($rowId, $syncOptions->emailMaxAttempts, $syncOptions->passwordLinkDays);
            $result->increment($status === ParticipantLog::EMAIL_SENT ? 'emailsSent' : 'emailsPendingOrFailed');
            if ($status === ParticipantLog::EMAIL_FAILED) {
                $result->increment('errors');
            }
            $result->addDetail($detail + ['emailStatus' => $status]);
            $result->lastSubmissionId = (int) $row->submission_id;
        }

        return $result;
    }

    protected function printSummary(Context $context, SyncOptions $syncOptions, SyncResult $result): void
    {
        $labels = [
            'submissionsExamined' => 'Submissions examined',
            'submissionsEligible' => 'Submissions eligible',
            'contributorsExamined' => 'Contributors examined',
            'existingUsersFound' => 'Existing users found',
            'usersCreated' => 'Users created',
            'authorRolesAssigned' => 'Author roles assigned',
            'participationsCreated' => 'Participations created',
            'participationsExisting' => 'Participations already existing',
            'invalidEmails' => 'Skipped: invalid email',
            'invalidGroups' => 'Skipped: the journal has no author group with workflow access',
            'disabledAccounts' => 'Disabled accounts found (pending)',
            'pendingWithoutAccount' => 'Pending: no account and account creation off',
            'emailsQueued' => 'Emails that would be sent',
            'emailsSent' => 'Emails sent',
            'emailsPendingOrFailed' => 'Emails pending or failed',
            'errors' => 'Errors',
        ];

        printf(
            "\nCoauthor Participants backfill: journal %s (%d), %s, email: %s\n\n",
            $context->getPath(),
            $context->getId(),
            $syncOptions->dryRun ? 'DRY RUN, nothing was changed' : 'executed',
            $syncOptions->sendEmail
        );
        foreach ($labels as $counter => $label) {
            printf("  %-46s %d\n", $label, $result->counters[$counter] ?? 0);
        }
        printf("  %-46s %s\n\n", 'Last submission id processed', $result->lastSubmissionId ?? '-');

        foreach ($result->details as $detail) {
            if (isset($detail['error'])) {
                printf("  ERROR submission %s, author %s: %s\n", $detail['submissionId'] ?? '-', $detail['authorId'] ?? '-', $detail['error']);
            }
        }
    }

    protected function writeReport(string $path, Context $context, SyncOptions $syncOptions, SyncResult $result): bool
    {
        $report = [
            'journal' => ['id' => (int) $context->getId(), 'path' => $context->getPath()],
            'dryRun' => $syncOptions->dryRun,
            'sendEmail' => $syncOptions->sendEmail,
            'generatedAt' => date('c'),
        ] + $result->toArray();

        $path = str_starts_with($path, '/') ? $path : PWD . '/' . $path;
        if (@file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) === false) {
            fwrite(STDERR, "Could not write the report to {$path}\n");
            return false;
        }
        printf("Report written to %s\n", $path);

        return true;
    }
}

try {
    $tool = new CoauthorParticipantsBackfillTool($argv ?? []);
    $tool->execute();
} catch (Throwable $e) {
    fwrite(STDERR, 'Initialization failed: ' . $e->getMessage() . "\n");
    exit(CoauthorParticipantsBackfillTool::EXIT_INVALID);
}
