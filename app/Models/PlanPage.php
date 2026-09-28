<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlanPage extends Model
{
    protected $fillable = [
        'plan_id',
        'page_number',
        'width',
        'height',
        'dzi_key',
        'thumbnail_key',
    ];

    protected function casts(): array
    {
        return [
            'page_number' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function annotations(): HasMany
    {
        return $this->hasMany(Annotation::class);
    }

    public function issues(): HasMany
    {
        return $this->hasMany(Issue::class);
    }
}