<?php

/**
 * @file plugins/generic/coAuthorParticipants/tests/BackfillCommandTest.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class BackfillCommandTest
 *
 * @brief The command line contract: nothing happens without an explicit
 *  journal and an explicit choice between simulating and executing.
 */

namespace APP\plugins\generic\coAuthorParticipants\tests;

use APP\plugins\generic\coAuthorParticipants\classes\BackfillOptions;

class BackfillCommandTest extends TestCase
{
    public function testFlagsAndValuesAreParsed(): void
    {
        $options = BackfillOptions::parse(['--journal=recima21', '--execute', '--send-email=new', '--batch-size=50', '--output=/tmp/r.json']);

        $this->assertSame('recima21', $options['journal']);
        $this->assertSame(true, $options['execute']);
        $this->assertSame('new', $options['send-email']);
        $this->assertSame('50', $options['batch-size']);
        $this->assertEmpty(BackfillOptions::validate($options));
    }

    public function testUnknownOrMalformedArgumentsAreRejected(): void
    {
        $this->assertSame(null, BackfillOptions::parse(['--journal=x', '--everything']));
        $this->assertSame(null, BackfillOptions::parse(['journal=x']));
        $this->assertSame(null, BackfillOptions::parse(['-j', 'x']));
    }

    public function testTheJournalIsRequired(): void
    {
        $errors = BackfillOptions::validate(BackfillOptions::parse(['--dry-run']));
        $this->assertStringContainsString('--journal', implode(' ', $errors));

        $errors = BackfillOptions::validate(BackfillOptions::parse(['--journal=', '--dry-run']));
        $this->assertStringContainsString('--journal', implode(' ', $errors));
    }

    public function testExactlyOneOfDryRunOrExecute(): void
    {
        $neither = BackfillOptions::validate(BackfillOptions::parse(['--journal=1']));
        $both = BackfillOptions::validate(BackfillOptions::parse(['--journal=1', '--dry-run', '--execute']));

        $this->assertStringContainsString('exactly one of --dry-run or --execute', implode(' ', $neither));
        $this->assertStringContainsString('exactly one of --dry-run or --execute', implode(' ', $both));
        $this->assertEmpty(BackfillOptions::validate(BackfillOptions::parse(['--journal=1', '--dry-run'])));
    }

    public function testFlagsTakeNoValue(): void
    {
        $errors = BackfillOptions::validate(BackfillOptions::parse(['--journal=1', '--execute=yes']));
        $this->assertStringContainsString('--execute takes no value', implode(' ', $errors));
    }

    public function testNumericOptionsAreBounded(): void
    {
        $cases = [
            '--batch-size=0' => '--batch-size',
            '--batch-size=501' => '--batch-size',
            '--batch-size=abc' => '--batch-size',
            '--after-id=-3' => '--after-id',
            '--submission-id=1.5' => '--submission-id',
        ];
        foreach ($cases as $argument => $expected) {
            $errors = BackfillOptions::validate(BackfillOptions::parse(['--journal=1', '--dry-run', $argument]));
            $this->assertStringContainsString($expected, implode(' ', $errors), "{$argument} must be rejected.");
        }

        $this->assertEmpty(BackfillOptions::validate(BackfillOptions::parse(['--journal=1', '--dry-run', '--batch-size=500', '--after-id=7'])));
    }

    public function testSendEmailAcceptsOnlyAllNewOrNone(): void
    {
        foreach (['all', 'new', 'none'] as $value) {
            $this->assertEmpty(BackfillOptions::validate(BackfillOptions::parse(['--journal=1', '--dry-run', "--send-email={$value}"])));
        }
        $errors = BackfillOptions::validate(BackfillOptions::parse(['--journal=1', '--dry-run', '--send-email=everyone']));
        $this->assertStringContainsString('--send-email', implode(' ', $errors));
    }

    public function testDatesMustBeRealCalendarDatesInOrder(): void
    {
        $this->assertEmpty(BackfillOptions::validate(BackfillOptions::parse(['--journal=1', '--dry-run', '--from-date=2024-02-29', '--to-date=2026-09-14'])));

        foreach (['--from-date=2026-02-30', '--to-date=14/09/2026', '--from-date=2026-9-1'] as $argument) {
            $errors = BackfillOptions::validate(BackfillOptions::parse(['--journal=1', '--dry-run', $argument]));
            $this->assertStringContainsString('YYYY-MM-DD', implode(' ', $errors), "{$argument} must be rejected.");
        }

        $errors = BackfillOptions::validate(BackfillOptions::parse(['--journal=1', '--dry-run', '--from-date=2026-09-14', '--to-date=2026-01-01']));
        $this->assertStringContainsString('must not be after', implode(' ', $errors));
    }

    public function testTheToolIsNotRunnableFromTheWeb(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/tools/backfill.php');

        // CommandLineTool exits when SERVER_NAME is set; the tool must extend it.
        $this->assertStringContainsString('extends CommandLineTool', $source);
        $this->assertStringContainsString("require dirname(__FILE__, 5) . '/tools/bootstrap.php';", $source);
    }
}
