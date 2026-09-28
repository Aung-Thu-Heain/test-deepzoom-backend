<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Issue extends Model
{
    protected $fillable = [
        'plan_page_id',
        'title',
        'description',
        'status',
        'priority',
        'x',
        'y',
    ];

    protected function casts(): array
    {
        return [
            'x' => 'float',
            'y' => 'float',
        ];
    }

    public function planPage(): BelongsTo
    {
        return $this->belongsTo(PlanPage::class);
    }
}