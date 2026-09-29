<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class InvestigationRun extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'run_number' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'credits_reserved' => 'integer',
            'credits_consumed' => 'integer',
            'input_snapshot' => 'array',
        ];
    }

    public function investigation(): BelongsTo
    {
        return $this->belongsTo(Investigation::class);
    }

    public function sourceRuns(): HasMany
    {
        return $this->hasMany(SourceRun::class);
    }

    public function creditReservations(): HasMany
    {
        return $this->hasMany(CreditReservation::class);
    }

    public function report(): HasOne
    {
        return $this->hasOne(Report::class);
    }
}
