<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quais permissões cada perfil concede. Pivô com PK composta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permission_system_role', function (Blueprint $table) {
            $table->foreignId('system_role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->primary(['system_role_id', 'permission_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permission_system_role');
    }
};
