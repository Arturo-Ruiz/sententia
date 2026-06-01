<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SentenceChunk extends Model
{
    protected $fillable = [
        'sentence_id',
        'content',
        'chunk_index',
        'embedding',
    ];

    public function sentence(): BelongsTo
    {
        return $this->belongsTo(Sentence::class);
    }
}
