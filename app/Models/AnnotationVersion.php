<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnnotationVersion extends Model
{
    protected $fillable = [
        'annotation_id',
        'version',
        'geometry',
        'style',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'geometry' => 'array',
            'style' => 'array',
        ];
    }

    public function annotation(): BelongsTo
    {
        return $this->belongsTo(Annotation::class);
    }
}