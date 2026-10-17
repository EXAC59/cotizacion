<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuoteClosingResult extends Model
{
    protected $primaryKey = 'quote_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['quote_id', 'status', 'invoice', 'comments', 'selected_lines', 'user_id', 'version', 'request_id'];

    protected function casts(): array
    {
        return ['selected_lines' => 'array', 'version' => 'integer'];
    }
}
