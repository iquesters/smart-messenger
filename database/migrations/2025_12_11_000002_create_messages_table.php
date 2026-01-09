<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')
            ->constrained('channels')
            ->cascadeOnDelete();
            $table->string('message_id')->unique();
            $table->string('from');
            $table->string('to');
            $table->string('message_type');
            $table->text('content');
            $table->datetime('timestamp');
            $table->string('status')->default('received');
            $table->json('raw_payload')->nullable();
            $table->json('raw_response')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};