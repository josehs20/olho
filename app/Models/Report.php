<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Report extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'generated_at' => 'datetime',
        ];
    }

    public function investigationRun(): BelongsTo
    {
        return $this->belongsTo(InvestigationRun::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
