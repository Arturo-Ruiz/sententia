<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sentence extends Model
{
    protected $fillable = [
        'url',
        'case_number',
        'court',
        'content',
        'metadata',
        'decision_date',
    ];

    protected $casts = [
        'metadata' => 'array',
        'decision_date' => 'date',
    ];

    public function chunks(): HasMany
    {
        return $this->hasMany(SentenceChunk::class);
    }
}
