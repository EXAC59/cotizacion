<?php

namespace App\Exceptions;

use App\Models\User;
use Exception;

class QuoteLockedException extends Exception
{
    public function __construct(
        public readonly User $lockedBy,
        public readonly ?string $lockedAt = null,
        string $message = 'Esta cotización está en seguimiento por otro usuario.',
    ) {
        parent::__construct($message);
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'message' => $this->getMessage(),
            'lockedBy' => [
                'id' => (string) $this->lockedBy->id,
                'name' => $this->lockedBy->name,
                'email' => $this->lockedBy->email,
            ],
            'lockedAt' => $this->lockedAt,
        ];
    }
}
