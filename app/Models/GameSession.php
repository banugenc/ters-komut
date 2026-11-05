<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GameSession extends Model
{
    protected $fillable = [
        'campaign_id',
        'user_id',
        'token',
        'started_at',
        'finished_at',
        'game_score_id',
        'ip',
        'user_agent',
        'meta',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'meta' => 'array',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function score(): BelongsTo
    {
        return $this->belongsTo(GameScore::class, 'game_score_id');
    }

    public function isFinished(): bool
    {
        return $this->finished_at !== null;
    }
}
