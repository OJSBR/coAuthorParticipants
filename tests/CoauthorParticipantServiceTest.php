<?php

/**
 * @file plugins/generic/coAuthorParticipants/tests/CoauthorParticipantServiceTest.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class CoauthorParticipantServiceTest
 *
 * @brief The rules that decide which submissions and contributors are linked,
 *  and with which group. The full flow against a database is exercised by the
 *  functional battery described in the README.
 */

namespace APP\plugins\generic\coAuthorParticipants\tests;

use APP\plugins\generic\coAuthorParticipants\classes\CoauthorAccountService;
use APP\plugins\generic\coAuthorParticipants\classes\CoauthorParticipantService;
use APP\plugins\generic\coAuthorParticipants\classes\SyncOptions;
use APP\plugins\generic\coAuthorParticipants\classes\SyncResult;
use APP\plugins\generic\coAuthorParticipants\CoAuthorParticipantsPlugin;
use APP\submission\Submission;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use PDOException;
use PKP\userGroup\UserGroup;

class CoauthorParticipantServiceTest extends PluginTestCase
{
    public function testOnlyCompletedQueuedOrScheduledSubmissionsAreEligible(): void
    {
        $now = '2026-09-14 10:00:00';

        $this->assertTrue(CoauthorParticipantService::isEligibleData($now, '', Submission::STATUS_QUEUED));
        $this->assertTrue(CoauthorParticipantService::isEligibleData($now, null, Submission::STATUS_SCHEDULED), 'Scheduled is not published yet.');

        $this->assertFalse(CoauthorParticipantService::isEligibleData($now, '', Submission::STATUS_PUBLISHED));
        $this->assertFalse(CoauthorParticipantService::isEligibleData($now, '', Submission::STATUS_DECLINED));
        $this->assertFalse(CoauthorParticipantService::isEligibleData($now, 'contributors', Submission::STATUS_QUEUED), 'An incomplete submission is still a draft.');
        $this->assertFalse(CoauthorParticipantService::isEligibleData(null, '', Submission::STATUS_QUEUED));
        $this->assertFalse(CoauthorParticipantService::isEligibleData('', '', Submission::STATUS_QUEUED));
        $this->assertFalse(CoauthorParticipantService::isEligibleData($now, '', 99));
    }

    public function testEligibilityReadsTheSubmissionObject(): void
    {
        $submission = new Submission();
        $submission->setData('dateSubmitted', '2026-09-14 10:00:00');
        $submission->setData('submissionProgress', '');
        $submission->setData('status', Submission::STATUS_QUEUED);
        $this->assertTrue(CoauthorParticipantService::isEligible($submission));

        $submission->setData('status', Submission::STATUS_PUBLISHED);
        $this->assertFalse(CoauthorParticipantService::isEligible($submission));
    }

    public function testEmailsAreTrimmedLowercasedAndValidated(): void
    {
        $this->assertSame('ana.souza@example.org', CoauthorAccountService::normalizeEmail("  Ana.Souza@Example.ORG \n"));
        $this->assertSame(null, CoauthorAccountService::normalizeEmail('ana.souza@'));
        $this->assertSame(null, CoauthorAccountService::normalizeEmail('ana souza@example.org'));
        $this->assertSame(null, CoauthorAccountService::normalizeEmail(''));
        $this->assertSame(null, CoauthorAccountService::normalizeEmail(null));
    }

    public function testEveryCoauthorIsLinkedWithTheAuthorGroupWhateverTheyChose(): void
    {
        // Rule of the specification: a contributor registered as "Translator"
        // is still linked as an author.
        [$service, $groups] = $this->serviceWithGroups();

        $this->assertSame(14, (int) $service->pickAuthorGroup($groups, null)->id, 'The group open to self-registration is the journal Author group.');
    }

    public function testTheConfiguredGroupIsUsedWhenItIsAValidAuthorGroup(): void
    {
        [$service, $groups] = $this->serviceWithGroups();

        $this->assertSame(15, (int) $service->pickAuthorGroup($groups, 15)->id);
        $this->assertSame(14, (int) $service->pickAuthorGroup($groups, 3)->id, 'A group that is not a valid author group is ignored.');
    }

    public function testWithoutSelfRegistrationGroupsTheFirstValidAuthorGroupIsUsed(): void
    {
        [$service] = $this->serviceWithGroups();
        $groups = new Collection();
        foreach ([21, 22] as $id) {
            $group = new UserGroup();
            $group->setRawAttributes(['user_group_id' => $id, 'permit_self_registration' => false]);
            $groups->put($id, $group);
        }

        $this->assertSame(21, (int) $service->pickAuthorGroup($groups, null)->id);
    }

    public function testNoGroupWhenTheJournalHasNoAuthorGroupWithWorkflowAccess(): void
    {
        [$service] = $this->serviceWithGroups();

        $this->assertSame(null, $service->pickAuthorGroup(new Collection(), null));
    }

    public function testOnlyAuthorGroupsWithAccessToTheSubmissionStageAreConsidered(): void
    {
        // A group with the author role but no workflow stage (the "Translator"
        // group of some journals) gives an assignment that grants no access and
        // does not even show among the participants.
        $source = (string) file_get_contents(dirname(__DIR__) . '/classes/CoauthorParticipantService.php');
        $this->assertStringContainsString("whereHas('userGroupStages', fn (\$query) => \$query->where('stage_id', WORKFLOW_STAGE_ID_SUBMISSION))", $source);
    }

    public function testNotificationPolicy(): void
    {
        $this->assertTrue((new SyncOptions(sendEmail: SyncOptions::EMAIL_ALL))->shouldNotify(false));
        $this->assertTrue((new SyncOptions(sendEmail: SyncOptions::EMAIL_NEW))->shouldNotify(true));
        $this->assertFalse((new SyncOptions(sendEmail: SyncOptions::EMAIL_NEW))->shouldNotify(false));
        $this->assertFalse((new SyncOptions(sendEmail: SyncOptions::EMAIL_NONE))->shouldNotify(true));
    }

    public function testConcurrencyErrorsAreRecognized(): void
    {
        $this->assertTrue(CoauthorAccountService::isConcurrencyError($this->queryException('23000', 1062)));
        $this->assertTrue(CoauthorAccountService::isConcurrencyError($this->queryException('40001', 1213)));
        $this->assertTrue(CoauthorAccountService::isConcurrencyError($this->queryException('HY000', 1205)));
        $this->assertTrue(CoauthorAccountService::isUniqueViolation($this->queryException('23505', 0)));

        $this->assertFalse(CoauthorAccountService::isConcurrencyError($this->queryException('23000', 1452)), 'A foreign key error is not a race.');
        $this->assertFalse(CoauthorAccountService::isConcurrencyError($this->queryException('42S02', 1146)));
    }

    public function testResultsAreMergedAcrossSubmissions(): void
    {
        $first = new SyncResult();
        $first->increment('usersCreated', 2);
        $first->addDetail(['submissionId' => 1]);
        $first->lastSubmissionId = 1;

        $second = new SyncResult();
        $second->increment('usersCreated');
        $second->increment('errors');
        $second->addDetail(['submissionId' => 2]);
        $second->lastSubmissionId = 2;

        $first->merge($second);

        $this->assertSame(3, $first->counters['usersCreated']);
        $this->assertTrue($first->hasErrors());
        $this->assertCount(2, $first->details);
        $this->assertSame(2, $first->lastSubmissionId);
    }

    /**
     * @return array{0: CoauthorParticipantService, 1: Collection}
     */
    protected function serviceWithGroups(): array
    {
        $groups = new Collection();
        // Ordered as in a standard install: Author (14) opens self-registration,
        // Translator (15) does not.
        foreach ([15 => false, 14 => true] as $id => $selfRegistration) {
            $group = new UserGroup();
            $group->setRawAttributes(['user_group_id' => $id, 'permit_self_registration' => $selfRegistration]);
            $groups->put($id, $group);
        }

        return [new CoauthorParticipantService(new CoAuthorParticipantsPlugin()), $groups];
    }

    protected function queryException(string $sqlState, int $driverCode): QueryException
    {
        $previous = new PDOException('simulated');
        $previous->errorInfo = [$sqlState, $driverCode, 'simulated'];

        return new QueryException('mysql', 'insert into t values (?)', [1], $previous);
    }
}
