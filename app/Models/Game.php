<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Game extends Model
{
    protected $fillable = ['phase', 'round', 'ends_at', 'prompts'];

    protected $attributes = ['phase' => 'lobby', 'round' => 0, 'ends_at' => 0];

    protected function casts(): array
    {
        return ['prompts' => 'array', 'round' => 'integer', 'ends_at' => 'integer'];
    }

    /**
     * The game the room is playing right now, created on first use.
     */
    public static function current(): self
    {
        return static::latest('id')->first()
            ?? static::create(['prompts' => config('game.prompts')]);
    }

    public function players(): HasMany
    {
        return $this->hasMany(Player::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(Answer::class);
    }

    public function votes(): HasMany
    {
        return $this->hasMany(Vote::class);
    }
}
