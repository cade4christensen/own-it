<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Vote extends Model
{
    protected $fillable = ['game_id', 'round', 'player_id', 'answer_id'];
}
