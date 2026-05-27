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
        Schema::create('sentence_chunks', function (Blueprint $table) {
            $table->id();

            $table->foreignId('sentence_id')->constrained('sentences')->onDelete('cascade');

            $table->text('content'); 
            $table->integer('chunk_index'); 

            $table->timestamps();
        });

        DB::statement('ALTER TABLE sentence_chunks ADD COLUMN embedding vector(1024);');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sentence_chunks');
    }
};
