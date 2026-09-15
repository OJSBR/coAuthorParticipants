<?php

/**
 * @file plugins/generic/coAuthorParticipants/tests/PluginTest.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class PluginTest
 *
 * @brief The plugin classes compiled against the PKP classes of the
 *  installation, its settings and its installable email template.
 */

namespace APP\plugins\generic\coAuthorParticipants\tests;

use APP\plugins\generic\coAuthorParticipants\classes\CoauthorEmailLogEventType;
use APP\plugins\generic\coAuthorParticipants\classes\jobs\SendCoauthorAssignmentEmail;
use APP\plugins\generic\coAuthorParticipants\classes\mailables\CoauthorParticipantAssigned;
use APP\plugins\generic\coAuthorParticipants\classes\migrations\CoauthorParticipantLogMigration;
use APP\plugins\generic\coAuthorParticipants\CoAuthorParticipantsPlugin;
use APP\plugins\generic\coAuthorParticipants\CoAuthorParticipantsSettingsForm;
use Illuminate\Support\Collection;
use PKP\log\SubmissionEmailLogEventType;
use ReflectionClass;
use ReflectionNamedType;

class PluginTest extends TestCase
{
    public function testOverriddenMethodsDeclareTheReturnTypesOfThisPkpVersion(): void
    {
        // A missing return type on an override is a fatal error that php -l does
        // not catch: it only shows when the class is loaded next to its parent.
        $classes = [
            CoAuthorParticipantsPlugin::class,
            CoAuthorParticipantsSettingsForm::class,
            CoauthorParticipantAssigned::class,
            SendCoauthorAssignmentEmail::class,
            CoauthorParticipantLogMigration::class,
        ];
        foreach ($classes as $class) {
            $reflection = new ReflectionClass($class);
            $parent = $reflection->getParentClass();
            foreach ($reflection->getMethods() as $method) {
                if ($method->getDeclaringClass()->getName() !== $class || !$parent->hasMethod($method->getName())) {
                    continue;
                }
                $parentType = $parent->getMethod($method->getName())->getReturnType();
                if ($parentType === null) {
                    continue;
                }
                $type = $method->getReturnType();
                $this->assertTrue(
                    $type instanceof ReflectionNamedType && $type->getName() === (string) $parentType,
                    sprintf('%s::%s() must declare the return type %s.', $reflection->getShortName(), $method->getName(), $parentType)
                );
            }
        }
    }

    public function testSettingsFallBackToTheirDefaultsAndBounds(): void
    {
        $this->assertSame(true, CoAuthorParticipantsPlugin::normalizeSetting('autoLink', null));
        $this->assertSame(false, CoAuthorParticipantsPlugin::normalizeSetting('sendEmail', false));
        $this->assertSame(3, CoAuthorParticipantsPlugin::normalizeSetting('emailMaxAttempts', ''));
        $this->assertSame(10, CoAuthorParticipantsPlugin::normalizeSetting('emailMaxAttempts', 99));
        $this->assertSame(1, CoAuthorParticipantsPlugin::normalizeSetting('emailMaxAttempts', -4));
        $this->assertSame(7, CoAuthorParticipantsPlugin::normalizeSetting('passwordLinkDays', null));
        $this->assertSame(30, CoAuthorParticipantsPlugin::normalizeSetting('passwordLinkDays', 365));
        $this->assertSame(null, CoAuthorParticipantsPlugin::normalizeSetting('authorUserGroupId', 0));
        $this->assertSame(15, CoAuthorParticipantsPlugin::normalizeSetting('authorUserGroupId', '15'));
    }

    public function testTheMailableIsAddedToTheJournalsEmails(): void
    {
        $mailables = new Collection();
        (new CoAuthorParticipantsPlugin())->addMailable('Mailer::Mailables', [$mailables, null]);

        $this->assertSame([CoauthorParticipantAssigned::class], $mailables->all());
    }

    public function testTheInstalledTemplateMatchesTheMailable(): void
    {
        $xml = simplexml_load_file(dirname(__DIR__) . '/emailTemplates.xml');
        $this->assertSame(CoauthorParticipantAssigned::getEmailTemplateKey(), (string) $xml->email['key']);
        $this->assertSame('COAUTHOR_PARTICIPANT_ASSIGNED', CoauthorParticipantAssigned::getEmailTemplateKey());
    }

    public function testTheDefaultBodyOnlyUsesVariablesTheMailableProvides(): void
    {
        $body = (new PoFile(dirname(__DIR__) . '/locale/en/emails.po'))->entries['emails.coauthorParticipantAssigned.body'];
        preg_match_all('/\{\$([a-zA-Z]+)\}/', $body, $matches);

        $provided = [
            // Core variables of a mailable built with a context, a submission and a user recipient.
            'recipientName', 'recipientUsername', 'submissionId', 'submissionTitle', 'authorSubmissionUrl',
            'contextName', 'contextUrl', 'contactName', 'contactEmail', 'contextSignature',
            // Added by the plugin.
            CoauthorParticipantAssigned::ACCOUNT_ACCESS_INSTRUCTIONS,
            CoauthorParticipantAssigned::PASSWORD_RESET_URL,
        ];
        foreach (array_unique($matches[1]) as $variable) {
            $this->assertTrue(in_array($variable, $provided, true), "The default body uses {\${$variable}}, which nothing provides.");
        }
    }

    public function testTheEmailLogEventTypeDoesNotCollideWithTheCore(): void
    {
        $coreValues = array_map(fn ($case) => $case->value, SubmissionEmailLogEventType::cases());
        $this->assertFalse(in_array(CoauthorEmailLogEventType::COAUTHOR_PARTICIPANT_ASSIGNED->value, $coreValues, true));
    }

    public function testNoSecretIsEverWrittenToTheLog(): void
    {
        // Details logged by the service are built from ids, outcomes and flags
        // only. Keep passwords, hashes and reset tokens out of every log call.
        foreach (glob(dirname(__DIR__) . '/{classes,classes/*,tools}/*.php', GLOB_BRACE) as $file) {
            foreach (explode("\n", (string) file_get_contents($file)) as $number => $line) {
                if (!str_contains($line, 'error_log(')) {
                    continue;
                }
                foreach (['password', 'Password', 'hash', 'confirm', 'token'] as $secret) {
                    $this->assertStringNotContainsString($secret, $line, basename($file) . ':' . ($number + 1) . ' logs something that looks like a secret.');
                }
            }
        }
    }
}
