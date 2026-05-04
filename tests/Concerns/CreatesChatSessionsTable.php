<?php

namespace Iquesters\SmartMessenger\Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait CreatesChatSessionsTable
{
    protected function createChatSessionsTable(): void
    {
        if (Schema::hasTable('chat_sessions')) {
            return;
        }

        Schema::create('chat_sessions', function (Blueprint $table) {
            $table->string('session_id')->primary();
            $table->string('contact_uid')->nullable()->index();
            $table->string('integration_id')->index();
            $table->longText('context_json')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('last_active_at')->nullable()->index();
            $table->timestamp('expires_at')->nullable();
        });
    }
}
