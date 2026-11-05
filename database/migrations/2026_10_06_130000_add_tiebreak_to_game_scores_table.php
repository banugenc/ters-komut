<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ters Komut'ta eşit doğru sayısında toplam tepki süresi (ms) düşük olan
 * üste çıkar. Süreli oyunlarda (Kaçış) ve görevli kaydında boş kalır.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('game_scores', 'tiebreak')) {
            Schema::table('game_scores', function (Blueprint $table): void {
                $table->unsignedInteger('tiebreak')->nullable()->after('score');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('game_scores', 'tiebreak')) {
            Schema::table('game_scores', function (Blueprint $table): void {
                $table->dropColumn('tiebreak');
            });
        }
    }
};
