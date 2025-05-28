<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // This migration was originally intended to modify the orders table, but it has been neutralized
        // to prevent conflicts and dependency issues. No action is taken here.
    }

    public function down(): void
    {
        // Nothing to rollback because up() is empty.
    }
}; 