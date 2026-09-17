<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiRequestLog extends Model
{
    protected $fillable = [
        'method', 'path', 'status_code', 'success', 'user_id', 'ip_address',
        'user_agent', 'request_headers', 'request_body', 'response_body',
        'error_message', 'duration_ms',
    ];

    protected function casts(): array
    {
        return [
            'success' => 'boolean',
            'request_headers' => 'array',
            'request_body' => 'array',
            'response_body' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}