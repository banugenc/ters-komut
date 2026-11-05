<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stant oyunlarının skorları (stant ekranındaki sıralama). Şimdilik görevli
 * kart okutup girer; oyunların API'si hazır olunca aynı tabloya yazar.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('game_scores')) {
            Schema::create('game_scores', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->unsignedBigInteger('score');
                $table->timestamp('flagged_at')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['campaign_id', 'score']);
            });
        }

        if (! Schema::hasColumn('campaigns', 'score_type')) {
            Schema::table('campaigns', fn (Blueprint $table) => $table->string('score_type', 16)->default('number'));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('game_scores');

        if (Schema::hasColumn('campaigns', 'score_type')) {
            Schema::table('campaigns', fn (Blueprint $table) => $table->dropColumn('score_type'));
        }
    }
};
