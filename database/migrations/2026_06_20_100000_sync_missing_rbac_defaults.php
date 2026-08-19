<?php

use App\Services\Rbac\RbacService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(RbacService::class)->syncMissingDefaults();
    }

    public function down(): void
    {
        // No revert: solo restaura permisos por defecto que faltaban.
    }
};
