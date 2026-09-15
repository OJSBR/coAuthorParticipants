<?php

/**
 * @file plugins/generic/coAuthorParticipants/tests/SubmissionSubmittedListenerTest.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class SubmissionSubmittedListenerTest
 *
 * @brief The automatic trigger. Repository::submit() does not protect its
 *  listeners, so the one thing this listener must never do is throw.
 */

namespace APP\plugins\generic\coAuthorParticipants\tests;

use APP\journal\Journal;
use APP\plugins\generic\coAuthorParticipants\classes\CoauthorParticipantService;
use APP\plugins\generic\coAuthorParticipants\classes\listeners\SynchronizeSubmittedCoauthors;
use APP\plugins\generic\coAuthorParticipants\CoAuthorParticipantsPlugin;
use APP\submission\Submission;
use PKP\observers\events\SubmissionSubmitted;
use RuntimeException;

class SubmissionSubmittedListenerTest extends TestCase
{
    public function testAFailureInTheServiceNeverReachesTheAuthorsRequest(): void
    {
        $plugin = $this->plugin(['enabled' => true, 'autoLink' => true], throw: true);
        $listener = new SynchronizeSubmittedCoauthors($plugin);

        $logged = $this->captureErrorLog(fn () => $listener->handle($this->event()));

        $this->assertSame(1, $plugin->serviceCalls);
        $this->assertStringContainsString('Automatic linking failed for submission 42', $logged);
    }

    public function testNothingRunsWhenThePluginIsDisabledInTheJournal(): void
    {
        $plugin = $this->plugin(['enabled' => false, 'autoLink' => true]);
        (new SynchronizeSubmittedCoauthors($plugin))->handle($this->event());

        $this->assertSame(0, $plugin->serviceCalls);
    }

    public function testNothingRunsWhenAutomaticLinkingIsOff(): void
    {
        $plugin = $this->plugin(['enabled' => true, 'autoLink' => false]);
        (new SynchronizeSubmittedCoauthors($plugin))->handle($this->event());

        $this->assertSame(0, $plugin->serviceCalls);
    }

    public function testThePluginListensAfterTheCoreListeners(): void
    {
        // The core listeners are discovered when the application boots; a
        // listener added by a plugin's register() is appended after them. The
        // acknowledgement is sent to every user assigned as author at the moment
        // it runs, so the order is what keeps co-authors out of it.
        $source = (string) file_get_contents(dirname(__DIR__) . '/CoAuthorParticipantsPlugin.php');
        $this->assertStringContainsString('Event::listen(SubmissionSubmitted::class', $source);

        $discovered = (string) file_get_contents(\PKP\core\Core::getBaseDir() . '/lib/pkp/classes/core/EventServiceProvider.php');
        $this->assertStringContainsString("basePath('lib/pkp/classes/observers/listeners')", $discovered);
    }

    protected function event(): SubmissionSubmitted
    {
        $submission = new Submission();
        $submission->setId(42);
        $context = new Journal();
        $context->setId(7);

        return new SubmissionSubmitted($submission, $context);
    }

    protected function plugin(array $settings, bool $throw = false): CoAuthorParticipantsPlugin
    {
        return new class ($settings, $throw) extends CoAuthorParticipantsPlugin {
            public int $serviceCalls = 0;

            public function __construct(private array $settings, private bool $throw)
            {
                parent::__construct();
            }

            public function getSetting($contextId, $name)
            {
                return $this->settings[$name] ?? null;
            }

            public function getEnabled($contextId = null)
            {
                return $this->settings['enabled'];
            }

            public function getService(): CoauthorParticipantService
            {
                $this->serviceCalls++;
                if ($this->throw) {
                    throw new RuntimeException('database unavailable');
                }
                throw new RuntimeException('the service must not be reached in this test');
            }
        };
    }

    protected function captureErrorLog(callable $callback): string
    {
        $file = tempnam(sys_get_temp_dir(), 'capLog');
        $previous = ini_set('error_log', $file);
        try {
            $callback();
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
        }
        $content = (string) file_get_contents($file);
        @unlink($file);

        return $content;
    }
}
