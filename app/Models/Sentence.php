<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Sentence extends Model
{
    protected $fillable = [
        'url',
        'case_number',
        'court',
        'content',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];
}
