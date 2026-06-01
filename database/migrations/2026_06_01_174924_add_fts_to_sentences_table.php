<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Agregar columna generada search_vector de tipo tsvector usando el diccionario en español
        DB::statement("ALTER TABLE sentences ADD COLUMN search_vector tsvector GENERATED ALWAYS AS (to_tsvector('spanish', left(coalesce(content, ''), 50000))) STORED");

        // 2. Crear índice GIN en la columna search_vector para acelerar las búsquedas masivas
        DB::statement('CREATE INDEX sentences_search_vector_idx ON sentences USING GIN (search_vector)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS sentences_search_vector_idx');
        DB::statement('ALTER TABLE sentences DROP COLUMN IF EXISTS search_vector');
    }
};
