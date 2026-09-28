<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Annotation extends Model
{
    protected $fillable = [
        'plan_page_id',
        'type',
        'current_version',
    ];

    protected function casts(): array
    {
        return [
            'current_version' => 'integer',
        ];
    }

    public function planPage(): BelongsTo
    {
        return $this->belongsTo(PlanPage::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(AnnotationVersion::class)->orderBy('version');
    }

    public function currentVersion(): ?AnnotationVersion
    {
        return $this->versions()
            ->where('version', $this->current_version)
            ->first();
    }
}