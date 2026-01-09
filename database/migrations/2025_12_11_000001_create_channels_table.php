<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channels', function (Blueprint $table) {
            $table->id();
            $table->ulid('uid')->unique();
            $table->unsignedBigInteger('user_id');
            $table->foreignId('channel_provider_id')
                ->constrained('channel_providers')
                ->onDelete('cascade');
            $table->string('name');
            $table->string('status')->default('active');
            $table->boolean('is_default')->default(false);
            $table->bigInteger('created_by')->default(0);
            $table->bigInteger('updated_by')->default(0);
            $table->timestamps();
        });

        // New channel_metas table
        Schema::create('channel_metas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ref_parent')->constrained('channels')->onDelete('cascade')->onUpdate('cascade');
            $table->string('meta_key');
            $table->text('meta_value');
            $table->string('status')->default('active');
            $table->bigInteger('created_by')->default(0);
            $table->bigInteger('updated_by')->default(0);
            $table->timestamps();

            $table->index(
                ['ref_parent', 'meta_key'],
                'channel_meta_idx' // Custom shorter index name
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_metas');
        Schema::dropIfExists('channels');
    }
};