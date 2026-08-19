<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ComparisonJob extends Model
{
    use HasUuids;

    public const STATUS_PROCESSING = 'procesando';

    public const STATUS_DONE = 'done';

    public const STATUS_ERROR = 'error';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'comparison_jobs';

    protected $fillable = [
        'part_number',
        'quantity',
        'preferred_warehouse',
        'status',
        'context',
        'offers',
        'best',
        'error_message',
        'n8n_execution_id',
        'demo_mode',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'context' => 'array',
            'offers' => 'array',
            'best' => 'array',
            'demo_mode' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        $context = $this->context ?? [];
        $notFound = [];
        if (is_array($context) && isset($context['notFound']) && is_array($context['notFound'])) {
            $notFound = $context['notFound'];
        }

        return [
            'id' => $this->id,
            'partNumber' => $this->part_number,
            'quantity' => (float) $this->quantity,
            'preferredWarehouse' => $this->preferred_warehouse,
            'status' => $this->status,
            'context' => $context,
            'offers' => $this->offers ?? [],
            'best' => $this->best,
            'notFound' => $notFound,
            // Aviso informativo (producto no hallado); no implica status=error del job.
            'errorMessage' => $this->error_message,
            'notFoundSummary' => is_array($context)
                ? ($context['notFoundSummary'] ?? $this->error_message)
                : $this->error_message,
            'demoMode' => $this->demo_mode,
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
