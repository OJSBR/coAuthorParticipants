<?php

/**
 * @file plugins/generic/coAuthorParticipants/tests/SubmitLinksCoauthorsTest.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class SubmitLinksCoauthorsTest
 *
 * @brief The whole path the author takes: a submission with a co-author is
 *        completed, and the co-author becomes a participant of it.
 *
 *        This is the same thing the functional test checks in a browser, done
 *        against a real database so that it can be run anywhere. It creates and
 *        deletes its own submission and user, and skips itself where the
 *        installation looks like a live journal.
 */

namespace APP\plugins\generic\coAuthorParticipants\tests;

use APP\core\Application;
use APP\core\PageRouter;
use APP\facades\Repo;
use APP\plugins\generic\coAuthorParticipants\CoAuthorParticipantsPlugin;
use APP\submission\Submission;
use APP\plugins\generic\coAuthorParticipants\classes\migrations\CoauthorParticipantLogMigration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use APP\plugins\generic\coAuthorParticipants\classes\CoauthorEmailLogEventType;
use APP\plugins\generic\coAuthorParticipants\classes\ParticipantLog;
use PKP\core\PKPRequest;
use PKP\plugins\PluginRegistry;
use PKP\security\Role;
use PKP\stageAssignment\StageAssignment;
use PKP\userGroup\UserGroup;

class SubmitLinksCoauthorsTest extends PluginTestCase
{
    private const CONTEXT_ID = 1;
    private const MARKER = '[CAP-TESTS]';
    private const PRODUCTION_LOOKS_LIKE = 100;

    private array $createdSubmissions = [];
    private array $createdUsers = [];
    private string $coauthorEmail = '';

    protected function setUp(): void
    {
        parent::setUp();

        if (!Application::getContextDAO()->getById(self::CONTEXT_ID)) {
            $this->markTestSkipped('journal ' . self::CONTEXT_ID . ' does not exist');
        }
        $published = DB::table('submissions')->where('status', Submission::STATUS_PUBLISHED)->count();
        if ($published > self::PRODUCTION_LOOKS_LIKE) {
            $this->markTestSkipped('this installation has ' . $published . ' published articles: it looks like a live journal');
        }

        $this->pinContext();
        $this->coauthorEmail = 'cap.coauthor.' . time() . '@example.invalid';
    }

    protected function tearDown(): void
    {
        foreach ($this->createdSubmissions as $id) {
            if ($submission = Repo::submission()->get($id)) {
                Repo::submission()->delete($submission);
            }
        }
        foreach ($this->createdUsers as $id) {
            if ($user = Repo::user()->get($id, true)) {
                Repo::user()->delete($user);
            }
        }
        if ($this->coauthorEmail && ($user = Repo::user()->getByEmail($this->coauthorEmail, true))) {
            Repo::user()->delete($user);
        }
        $this->createdSubmissions = [];
        $this->createdUsers = [];
        parent::tearDown();
    }

    /** There is no URL on the command line, so the journal is pinned on the router. */
    private function pinContext(): void
    {
        $request = Application::get()->getRequest();
        $router = new class () extends PageRouter {
            public $pinned;

            public function getContext(PKPRequest $request, bool $forceReload = false): ?\PKP\context\Context
            {
                return $this->pinned;
            }
        };
        $router->setApplication(Application::get());
        $router->pinned = Application::getContextDAO()->getById(self::CONTEXT_ID);
        $request->setRouter($router);
    }

    /**
     * The plugin as the application loads it, with its listeners attached.
     */
    private function loadPlugin(): CoAuthorParticipantsPlugin
    {
        PluginRegistry::loadCategory('generic', true, self::CONTEXT_ID);
        $plugin = PluginRegistry::getPlugin('generic', 'coauthorparticipantsplugin');
        if (!$plugin) {
            $this->markTestSkipped('the plugin is not installed here');
        }
        if (!$plugin->getEnabled(self::CONTEXT_ID)) {
            $this->markTestSkipped('the plugin is not enabled for journal ' . self::CONTEXT_ID);
        }
        return $plugin;
    }

    /**
     * An incomplete submission with a submitting author and one co-author who has
     * no account, exactly as the wizard leaves it before the last step.
     */
    private function submissionAwaitingSubmit(): Submission
    {
        $context = Application::getContextDAO()->getById(self::CONTEXT_ID);
        $userGroup = UserGroup::withContextIds([self::CONTEXT_ID])->withRoleIds([Role::ROLE_ID_AUTHOR])->first();
        $this->assertNotNull($userGroup, 'the journal has no author role');

        $sectionId = DB::table('sections')->where('journal_id', self::CONTEXT_ID)->value('section_id');
        $submitter = Repo::user()->getCollector()->filterByContextIds([self::CONTEXT_ID])->limit(1)->getMany()->first();
        $this->assertNotNull($submitter, 'the journal has no users');

        $submission = Repo::submission()->newDataObject([
            'contextId' => self::CONTEXT_ID,
            'status' => Submission::STATUS_QUEUED,
            'submissionProgress' => 'files',
            'stageId' => WORKFLOW_STAGE_ID_SUBMISSION,
            'locale' => 'en',
        ]);
        $publication = Repo::publication()->newDataObject([
            'title' => ['en' => self::MARKER . ' submit links co-authors'],
            'sectionId' => $sectionId,
            'locale' => 'en',
            'status' => Submission::STATUS_QUEUED,
        ]);
        $submissionId = Repo::submission()->add($submission, $publication, $context);
        $this->createdSubmissions[] = $submissionId;

        $submission = Repo::submission()->get($submissionId);
        $publication = $submission->getCurrentPublication();

        // The submitting author, as the wizard records them.
        Repo::author()->add(Repo::author()->newDataObject([
            'publicationId' => $publication->getId(),
            'givenName' => ['en' => $submitter->getData('givenName')['en'] ?? 'Submitting'],
            'familyName' => ['en' => $submitter->getData('familyName')['en'] ?? 'Author'],
            'userGroupId' => $userGroup->id,
            'seq' => 0,
            'includeInBrowse' => true,
            'email' => $submitter->getEmail(),
            'country' => 'BR',
        ]));
        // The co-author who has no account of their own.
        Repo::author()->add(Repo::author()->newDataObject([
            'publicationId' => $publication->getId(),
            'givenName' => ['en' => 'Cap'],
            'familyName' => ['en' => 'Coauthor'],
            'userGroupId' => $userGroup->id,
            'seq' => 1,
            'includeInBrowse' => true,
            'email' => $this->coauthorEmail,
            'country' => 'BR',
        ]));

        // The submitter is a participant from the start, as the wizard assigns them.
        StageAssignment::create([
            'submissionId' => $submissionId,
            'userGroupId' => $userGroup->id,
            'userId' => $submitter->getId(),
            'stageId' => WORKFLOW_STAGE_ID_SUBMISSION,
            'recommendOnly' => false,
            'canChangeMetadata' => true,
            'dateAssigned' => \PKP\core\Core::getCurrentDate(),
        ]);

        return Repo::submission()->get($submissionId);
    }

    /**
     * @return string[] the e-mails of everyone assigned to the submission
     */
    private function participantEmails(int $submissionId): array
    {
        $emails = [];
        foreach (StageAssignment::withSubmissionIds([$submissionId])->get() as $assignment) {
            if ($user = Repo::user()->get((int) $assignment->userId, true)) {
                $emails[] = strtolower($user->getEmail());
            }
        }
        return array_values(array_unique($emails));
    }

    public function testTheLogTableIsCreatedWhenThePluginIsSwitchedOnWithoutIt(): void
    {
        $plugin = $this->loadPlugin();

        // A plugin copied onto the server by hand never ran its migration: the
        // table is missing and every contributor would fail against it.
        Schema::dropIfExists(CoauthorParticipantLogMigration::TABLE);
        $this->assertFalse(Schema::hasTable(CoauthorParticipantLogMigration::TABLE));

        $plugin->ensureSchema();

        $this->assertTrue(
            Schema::hasTable(CoauthorParticipantLogMigration::TABLE),
            'switching the plugin on must leave it with a table to write to'
        );
    }

    public function testCompletingASubmissionMakesItsCoauthorAParticipant(): void
    {
        $this->loadPlugin();
        $context = Application::getContextDAO()->getById(self::CONTEXT_ID);
        $submission = $this->submissionAwaitingSubmit();

        $this->assertNotContains(
            strtolower($this->coauthorEmail),
            $this->participantEmails($submission->getId()),
            'the co-author must not be a participant before the submission is completed'
        );

        // Exactly what the API route does when the author finishes the wizard.
        Repo::submission()->submit($submission, $context);

        $emails = $this->participantEmails($submission->getId());
        $this->assertContains(
            strtolower($this->coauthorEmail),
            $emails,
            'the co-author was not linked when the submission was completed; participants: ' . implode(', ', $emails)
        );

        // And the account the plugin created for them is a real, usable one.
        $user = Repo::user()->getByEmail($this->coauthorEmail, true);
        $this->assertNotNull($user, 'no account was created for the co-author');
        $this->assertSame(strtolower($this->coauthorEmail), strtolower($user->getEmail()));
    }

    /**
     * What was done for the co-author has to be visible afterwards: the role that
     * lets them open the submission, the row that says what happened, and either
     * the message in the e-mail log of the submission or the failure written down.
     *
     * Silence is the failure this guards against: a co-author who was linked,
     * never told, and left no trace of it.
     */
    public function testWhatWasDoneForTheCoauthorIsWrittenDownAndCanBeAudited(): void
    {
        $this->loadPlugin();
        $context = Application::getContextDAO()->getById(self::CONTEXT_ID);
        $submission = $this->submissionAwaitingSubmit();

        Repo::submission()->submit($submission, $context);

        $user = Repo::user()->getByEmail($this->coauthorEmail, true);
        $this->assertNotNull($user, 'no account was created for the co-author');

        // The role of the journal, without which being a participant is of no use.
        $groups = DB::table('user_user_groups as uug')
            ->join('user_groups as ug', 'ug.user_group_id', '=', 'uug.user_group_id')
            ->where('uug.user_id', $user->getId())
            ->where('ug.context_id', self::CONTEXT_ID)
            ->pluck('ug.role_id')
            ->all();
        $this->assertContains(
            Role::ROLE_ID_AUTHOR,
            array_map('intval', $groups),
            'the co-author was not given an author role of the journal; roles: ' . implode(', ', $groups)
        );

        // One row of the log, saying what was done.
        $row = DB::table(ParticipantLog::TABLE)
            ->where('submission_id', $submission->getId())
            ->where('user_id', $user->getId())
            ->first();
        $this->assertNotNull($row, 'nothing was written to ' . ParticipantLog::TABLE . ' for the co-author');
        $this->assertTrue((bool) $row->account_created, 'the row does not say the account was created');
        $this->assertTrue((bool) $row->assignment_created, 'the row does not say the assignment was created');

        // And the message. Three ends are acceptable and each one has to be
        // visible: it went out and is in the e-mail log of the submission; it is
        // waiting in the queue and the job for it is really there; or it failed
        // and the reason is written down. What is not acceptable is a co-author
        // left waiting with nothing behind it.
        if ($row->email_status === ParticipantLog::EMAIL_SENT) {
            $logged = DB::table('email_log')
                ->where('assoc_type', Application::ASSOC_TYPE_SUBMISSION)
                ->where('assoc_id', $submission->getId())
                ->where('event_type', CoauthorEmailLogEventType::COAUTHOR_PARTICIPANT_ASSIGNED->value)
                ->count();
            $this->assertGreaterThan(0, $logged, 'the message counts as sent but is in no e-mail log of the submission');
        } elseif (in_array($row->email_status, [ParticipantLog::EMAIL_PENDING, ParticipantLog::EMAIL_SENDING], true)) {
            // The queue runs outside the test; what has to exist here is the job.
            $queued = DB::table('jobs')->where('payload', 'like', '%SendCoauthorAssignmentEmail%')->count();
            $this->assertGreaterThan(
                0,
                $queued,
                'the message is waiting (' . $row->email_status . ') and no job was queued to send it'
            );
        } else {
            $this->assertNotEmpty(
                $row->last_error,
                'the message did not go out (' . $row->email_status . ') and nothing says why'
            );
        }
    }
}
