<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    protected $fillable = [
        'name',
        'original_pdf_key',
        'status',
        'page_count',
    ];

    protected function casts(): array
    {
        return [
            'page_count' => 'integer',
        ];
    }

    public function pages(): HasMany
    {
        return $this->hasMany(PlanPage::class)->orderBy('page_number');
    }
}