<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[DateFormat('Y-m-d H:i:s.v')]
#[Fillable([
    'log_source_id',
    'source_offset',
    'source_line',
    'consumer_id',
    'service_name',
    'latency_proxy',
    'latency_gateway',
    'latency_request',
    'created_at',
    'processed_at',
])]
final class GatewayLog extends Model
{
    public $timestamps = false;

    /**
     * @return BelongsTo<LogSource, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(LogSource::class, 'log_source_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source_offset' => 'integer',
            'source_line' => 'integer',
            'latency_proxy' => 'integer',
            'latency_gateway' => 'integer',
            'latency_request' => 'integer',
            'created_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
        ];
    }
}
