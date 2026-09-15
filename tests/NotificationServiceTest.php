<?php

/**
 * @file plugins/generic/coAuthorParticipants/tests/NotificationServiceTest.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class NotificationServiceTest
 *
 * @brief Telling a message that left from one the mail server refused.
 *
 * PKP's mailer catches transport exceptions and only writes them to the error
 * log, so Mail::send() returns normally on an SMTP refusal. These tests swap
 * the transport for one that accepts and one that refuses, and check that the
 * service tells the two apart. No message leaves the server.
 */

namespace APP\plugins\generic\coAuthorParticipants\tests;

use APP\plugins\generic\coAuthorParticipants\classes\CoauthorNotificationService;
use APP\submission\Submission;
use APP\journal\Journal;
use PKP\mail\Mailable;
use PKP\user\User;
use RuntimeException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

class NotificationServiceTest extends TestCase
{
    public function testAMessageAcceptedByTheTransportCountsAsSent(): void
    {
        $this->withTransport($this->transport(refuse: false), function () {
            $this->service()->exposeSendOrFail($this->mailable());
            $this->assertTrue(true, 'No exception for an accepted message.');
        });
    }

    public function testAMessageRefusedByTheTransportIsAFailure(): void
    {
        $this->withTransport($this->transport(refuse: true), function () {
            $failed = false;
            $logFile = tempnam(sys_get_temp_dir(), 'capMail');
            $previous = ini_set('error_log', $logFile);
            try {
                $this->service()->exposeSendOrFail($this->mailable());
            } catch (RuntimeException $e) {
                $failed = true;
                $this->assertStringContainsString('did not accept the message', $e->getMessage());
            } finally {
                ini_set('error_log', $previous === false ? '' : $previous);
                @unlink($logFile);
            }
            $this->assertTrue($failed, 'A refused message must not be reported as sent.');
        });
    }

    public function testTheMessageLanguageFollowsTheRecipientThenTheSubmission(): void
    {
        $context = new Journal();
        $context->setData('supportedLocales', ['pt_BR', 'en', 'es']);
        $context->setData('primaryLocale', 'pt_BR');

        $submission = new Submission();
        $submission->setData('locale', 'en');

        $user = new User();
        $user->setLocales(['es']);
        $this->assertSame('es', CoauthorNotificationService::pickLocale($user, $submission, $context));

        $user->setLocales(['fr']);
        $this->assertSame('en', CoauthorNotificationService::pickLocale($user, $submission, $context));

        $submission->setData('locale', 'de');
        $this->assertSame('pt_BR', CoauthorNotificationService::pickLocale($user, $submission, $context));
    }

    protected function service(): object
    {
        return new class () extends CoauthorNotificationService {
            public function exposeSendOrFail(Mailable $mailable): void
            {
                $this->sendOrFail($mailable);
            }
        };
    }

    protected function mailable(): Mailable
    {
        return (new Mailable())
            ->from('journal@example.org', 'Journal')
            ->to('coauthor@example.org', 'Co-author')
            ->subject('Test')
            ->body('<p>Test</p>');
    }

    protected function transport(bool $refuse): TransportInterface
    {
        return new class ($refuse) implements TransportInterface {
            public function __construct(private bool $refuse)
            {
            }

            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                if ($this->refuse) {
                    throw new TransportException('550 The mail server could not deliver mail (simulated)');
                }

                return new SentMessage($message, $envelope ?? Envelope::create($message));
            }

            public function __toString(): string
            {
                return 'simulated://';
            }
        };
    }

    protected function withTransport(TransportInterface $transport, callable $callback): void
    {
        $this->ensureRouter();
        $mailer = app('mailer');
        $original = $mailer->getSymfonyTransport();
        $mailer->setSymfonyTransport($transport);
        try {
            $callback();
        } finally {
            $mailer->setSymfonyTransport($original);
        }
    }
}
