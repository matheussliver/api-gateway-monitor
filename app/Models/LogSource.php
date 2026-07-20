<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'fingerprint',
    'path',
    'last_processed_offset',
    'last_processed_line',
    'file_size',
    'processed_prefix_hash',
])]
final class LogSource extends Model
{
    /**
     * @var array<string, int>
     */
    protected $attributes = [
        'last_processed_offset' => 0,
        'last_processed_line' => 0,
        'file_size' => 0,
    ];

    /**
     * @return HasMany<GatewayLog, $this>
     */
    public function logs(): HasMany
    {
        return $this->hasMany(GatewayLog::class);
    }

    /**
     * @return HasMany<GatewayLogRejection, $this>
     */
    public function rejections(): HasMany
    {
        return $this->hasMany(GatewayLogRejection::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_processed_offset' => 'integer',
            'last_processed_line' => 'integer',
            'file_size' => 'integer',
        ];
    }
}
