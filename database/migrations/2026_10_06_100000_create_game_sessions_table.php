<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stant oyunları: her oyun sunucuda tek kullanımlık bir oturumla başlar,
 * skor o oturumla gönderilir. Oyuncu öğrenci numarasından tanınır.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'student_number')) {
            Schema::table('users', fn (Blueprint $table) => $table->string('student_number', 20)->nullable()->unique());
        }

        if (! Schema::hasColumn('campaigns', 'game_key')) {
            Schema::table('campaigns', fn (Blueprint $table) => $table->string('game_key', 32)->nullable()->index());
        }

        if (! Schema::hasTable('game_sessions')) {
            Schema::create('game_sessions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('token', 64)->unique();
                $table->timestamp('started_at');
                $table->timestamp('finished_at')->nullable();
                $table->foreignId('game_score_id')->nullable()->constrained('game_scores')->nullOnDelete();
                $table->string('ip', 45)->nullable();
                $table->string('user_agent', 255)->nullable();
                $table->json('meta')->nullable();
                $table->timestamps();

                // Şartname: her öğrenci numarası her oyunu bir kez oynar.
                $table->unique(['campaign_id', 'user_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('game_sessions');

        if (Schema::hasColumn('campaigns', 'game_key')) {
            Schema::table('campaigns', fn (Blueprint $table) => $table->dropColumn('game_key'));
        }

        if (Schema::hasColumn('users', 'student_number')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropUnique(['student_number']);
                $table->dropColumn('student_number');
            });
        }
    }
};
