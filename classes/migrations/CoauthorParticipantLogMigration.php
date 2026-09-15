<?php

/**
 * @file plugins/generic/coAuthorParticipants/classes/migrations/CoauthorParticipantLogMigration.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class CoauthorParticipantLogMigration
 *
 * @brief Control table: one row per participant linked (or pending) per
 *  submission, which keeps the processing idempotent and tracks the email.
 */

namespace APP\plugins\generic\coAuthorParticipants\classes\migrations;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CoauthorParticipantLogMigration extends Migration
{
    public const TABLE = 'coauthor_participant_log';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function (Blueprint $table) {
            $table->bigIncrements('coauthor_participant_log_id');
            $table->bigInteger('context_id');
            $table->bigInteger('submission_id');
            $table->bigInteger('author_id')->nullable();
            $table->bigInteger('user_id')->nullable();
            $table->bigInteger('user_group_id')->nullable();
            $table->string('source', 16);
            $table->string('status', 32);
            $table->boolean('account_created')->default(false);
            $table->boolean('assignment_created')->default(false);
            $table->string('email_status', 16);
            $table->smallInteger('email_attempts')->default(0);
            $table->dateTime('email_sent_at')->nullable();
            $table->text('last_error')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');

            $table->foreign('submission_id', 'coauthor_participant_log_submission_id')
                ->references('submission_id')->on('submissions')->onDelete('cascade');
            $table->foreign('user_id', 'coauthor_participant_log_user_id')
                ->references('user_id')->on('users')->onDelete('cascade');

            // A NULL user_id (a pending contributor without an account) does not
            // collide, so pending rows are kept unique by author instead.
            $table->unique(['submission_id', 'user_id'], 'coauthor_participant_log_submission_user');
            $table->index(['submission_id', 'author_id'], 'coauthor_participant_log_submission_author');
            $table->index(['context_id', 'email_status'], 'coauthor_participant_log_email_status');
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
}
