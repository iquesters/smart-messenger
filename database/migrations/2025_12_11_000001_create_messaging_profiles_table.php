<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messaging_profiles', function (Blueprint $table) {
            $table->id();
            $table->ulid('uid')->unique();
            $table->unsignedBigInteger('provider_id')->nullable();
            $table->string('name');
            $table->string('status')->default('active');
            $table->boolean('is_default')->default(false);
            $table->bigInteger('created_by')->default(0);
            $table->bigInteger('updated_by')->default(0);
            $table->timestamps();
        });

        // New messaging_profile_metas table
        Schema::create('messaging_profile_metas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('messaging_profile_id')->constrained()->onDelete('cascade');
            $table->string('meta_key');
            $table->text('meta_value');
            $table->string('status')->default('active');
            $table->bigInteger('created_by')->default(0);
            $table->bigInteger('updated_by')->default(0);
            $table->timestamps();

            $table->index(
                ['messaging_profile_id', 'meta_key'],
                'org_profile_meta_idx' // Custom shorter index name
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messaging_profile_metas');
        Schema::dropIfExists('messaging_profiles');
    }
};