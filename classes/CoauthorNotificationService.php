<?php

/**
 * @file plugins/generic/coAuthorParticipants/classes/CoauthorNotificationService.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class CoauthorNotificationService
 *
 * @brief Sends the assignment email, one recipient per message, and keeps its
 *  status in the control table.
 *
 * A failed email never undoes the account or the assignment: the row stays
 * "failed" with the last error and can be sent again.
 */

namespace APP\plugins\generic\coAuthorParticipants\classes;

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\coAuthorParticipants\classes\jobs\SendCoauthorAssignmentEmail;
use APP\plugins\generic\coAuthorParticipants\classes\mailables\CoauthorParticipantAssigned;
use APP\plugins\generic\coAuthorParticipants\CoAuthorParticipantsPlugin;
use APP\submission\Submission;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use PKP\context\Context;
use PKP\core\Core;
use PKP\core\PKPApplication;
use PKP\facades\Locale;
use PKP\mail\Mailable;
use PKP\security\Validation;
use PKP\user\User;
use Throwable;

class CoauthorNotificationService
{
    protected ParticipantLog $log;

    /** Messages the mailer reported as sent in this process. */
    protected static int $sentMessages = 0;
    protected static bool $listening = false;

    public function __construct(protected ?CoAuthorParticipantsPlugin $plugin = null)
    {
        $this->log = new ParticipantLog();
    }

    /**
     * Hand a pending message over: to the queue for automatic runs, straight to
     * the mailer for the command line.
     *
     * @return string the result counter to increment
     */
    public function deliver(int $logId, SyncOptions $options): string
    {
        if ($options->delivery === SyncOptions::DELIVERY_QUEUE) {
            dispatch(new SendCoauthorAssignmentEmail($logId, $options->emailMaxAttempts, $options->passwordLinkDays));
            return 'emailsQueued';
        }

        return $this->send($logId, $options->emailMaxAttempts, $options->passwordLinkDays) === ParticipantLog::EMAIL_SENT
            ? 'emailsSent'
            : 'emailsPendingOrFailed';
    }

    /**
     * Send the message of one control row now.
     *
     * @param bool $rethrow Rethrow a delivery error while attempts remain, so a
     *  queue job is retried by the worker.
     *
     * @return string the email status after the attempt
     */
    public function send(int $logId, int $maxAttempts, int $passwordLinkDays, bool $rethrow = false): string
    {
        if (!$this->log->claimForSending($logId, $maxAttempts)) {
            $row = $this->log->find($logId);
            return $row->email_status ?? ParticipantLog::EMAIL_SUPPRESSED;
        }

        $row = $this->log->find($logId);

        try {
            $user = Repo::user()->get((int) $row->user_id, true);
            $submission = Repo::submission()->get((int) $row->submission_id);
            $context = Application::getContextDAO()->getById((int) $row->context_id);

            if (!$user || $user->getDisabled() || !$submission || !$context) {
                $this->log->update($logId, ['email_status' => ParticipantLog::EMAIL_SUPPRESSED, 'last_error' => 'Recipient, submission or journal no longer available.']);
                return ParticipantLog::EMAIL_SUPPRESSED;
            }

            $this->registerPluginLocale();

            $mailable = $this->buildMailable($context, $submission, $user, (bool) $row->account_created, $passwordLinkDays);
            $this->sendOrFail($mailable);

            $this->log->update($logId, [
                'email_status' => ParticipantLog::EMAIL_SENT,
                'email_sent_at' => Core::getCurrentDate(),
                'last_error' => null,
            ]);
        } catch (Throwable $e) {
            $this->log->update($logId, [
                'email_status' => ParticipantLog::EMAIL_FAILED,
                'last_error' => mb_substr($e->getMessage(), 0, 2000),
            ]);
            error_log('[coAuthorParticipants] Email for control row ' . $logId . ' failed: ' . $e->getMessage());

            $attempts = (int) ($this->log->find($logId)->email_attempts ?? $maxAttempts);
            if ($rethrow && $attempts < $maxAttempts) {
                throw $e;
            }

            return ParticipantLog::EMAIL_FAILED;
        }

        // The message went out: a problem writing the history must not turn it
        // into a failure that would be sent again.
        try {
            Repo::emailLogEntry()->logMailable(CoauthorEmailLogEventType::COAUTHOR_PARTICIPANT_ASSIGNED, $mailable, $submission);
        } catch (Throwable $e) {
            error_log('[coAuthorParticipants] Email sent but not added to the submission email log (control row ' . $logId . '): ' . $e->getMessage());
        }

        return ParticipantLog::EMAIL_SENT;
    }

    /**
     * Send a message and fail loudly if it did not leave.
     *
     * PKP's mailer catches transport exceptions and only writes them to the
     * error log, so Mail::send() returns normally when SMTP refuses the message.
     * Laravel fires MessageSent only for a message actually handed to the
     * transport, which is what tells the two cases apart.
     */
    protected function sendOrFail(Mailable $mailable): void
    {
        if (!static::$listening) {
            Event::listen(MessageSent::class, fn () => static::$sentMessages++);
            static::$listening = true;
        }

        $before = static::$sentMessages;
        Mail::send($mailable);

        if (static::$sentMessages === $before) {
            throw new RuntimeException('The mail transport did not accept the message; the transport error is in the PHP error log.');
        }
    }

    /**
     * Build the message for one recipient.
     */
    public function buildMailable(Context $context, Submission $submission, User $user, bool $accountCreated, int $passwordLinkDays): CoauthorParticipantAssigned
    {
        $locale = static::pickLocale($user, $submission, $context);
        $template = Repo::emailTemplate()->getByKey((int) $context->getId(), CoauthorParticipantAssigned::getEmailTemplateKey());

        $mailable = new CoauthorParticipantAssigned($context, $submission);
        $mailable
            ->from($context->getData('contactEmail'), $context->getData('contactName'))
            ->recipients([$user], $locale)
            ->subject($template->getLocalizedData('subject', $locale))
            ->body($template->getLocalizedData('body', $locale));

        // The reset link is only for an account the plugin created and whose
        // owner has not chosen a password yet.
        $passwordResetUrl = '';
        if ($accountCreated && $user->getMustChangePassword()) {
            $passwordResetUrl = $this->getPasswordResetUrl($context, $user, $passwordLinkDays);
        }

        $instructions = $passwordResetUrl !== ''
            ? __('plugins.generic.coAuthorParticipants.email.accessNewAccount', [
                'username' => htmlspecialchars($user->getUsername()),
                'passwordResetUrl' => htmlspecialchars($passwordResetUrl),
                'days' => $passwordLinkDays,
            ], $locale)
            : __('plugins.generic.coAuthorParticipants.email.accessExistingAccount', [
                'username' => htmlspecialchars($user->getUsername()),
                'passwordLostUrl' => htmlspecialchars($this->getPasswordLostUrl($context)),
            ], $locale);

        $mailable->addData([
            CoauthorParticipantAssigned::ACCOUNT_ACCESS_INSTRUCTIONS => $instructions,
            CoauthorParticipantAssigned::PASSWORD_RESET_URL => $passwordResetUrl,
        ]);

        return $mailable;
    }

    /**
     * A signed, temporary link to set the password; nothing sensitive is stored.
     */
    public function getPasswordResetUrl(Context $context, User $user, int $days): string
    {
        $request = Application::get()->getRequest();

        return $request->getDispatcher()->url(
            $request,
            PKPApplication::ROUTE_PAGE,
            $context->getData('urlPath'),
            'login',
            'resetPassword',
            [$user->getUsername()],
            ['confirm' => Validation::generatePasswordResetHash($user->getId(), time() + $days * 86400)]
        );
    }

    public function getPasswordLostUrl(Context $context): string
    {
        $request = Application::get()->getRequest();

        return $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, $context->getData('urlPath'), 'login', 'lostPassword');
    }

    /**
     * The recipient's language when the journal uses it, then the submission's,
     * then the journal's primary language.
     */
    public static function pickLocale(User $user, Submission $submission, Context $context): string
    {
        $supported = (array) $context->getSupportedLocales();
        foreach (array_merge((array) $user->getLocales(), [(string) $submission->getData('locale')]) as $candidate) {
            if (in_array($candidate, $supported, true)) {
                return $candidate;
            }
        }

        return $context->getPrimaryLocale();
    }

    /**
     * Queue workers and the command line do not load the plugin, so its
     * translations are registered explicitly.
     */
    protected function registerPluginLocale(): void
    {
        Locale::registerPath(dirname(__DIR__) . '/locale');
    }
}
