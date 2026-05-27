<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */

    public function up(): void
    {
        Schema::ensureVectorExtensionExists();

        Schema::create('sentence_chunks', function (Blueprint $table) {
            $table->id();

            $table->foreignId('sentence_id')->constrained('sentences')->onDelete('cascade');
            $table->text('content');
            $table->integer('chunk_index');

            $table->vector('embedding', dimensions: 1024)->nullable();

            $table->timestamps();
        });

        DB::statement('CREATE INDEX IF NOT EXISTS sentence_chunks_embedding_hnsw_idx ON sentence_chunks USING hnsw (embedding vector_cosine_ops);');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sentence_chunks');
    }
};
