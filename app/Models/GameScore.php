<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class GameScore extends Model
{
    protected $fillable = [
        'campaign_id',
        'user_id',
        'score',
        'tiebreak',
        'flagged_at',
        'created_by',
    ];

    protected $casts = [
        'score' => 'integer',
        'tiebreak' => 'integer',
        'flagged_at' => 'datetime',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Telefondan oynanan oyunun oturumu (stantta elle girilen skorda yok). */
    public function session(): HasOne
    {
        return $this->hasOne(GameSession::class, 'game_score_id');
    }

    /** Hileli diye işaretlenmemiş skorlar. */
    public function scopeValid(Builder $query): Builder
    {
        return $query->whereNull('flagged_at');
    }
}
