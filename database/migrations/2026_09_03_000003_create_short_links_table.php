<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('short_links', function (Blueprint $table) {
            $table->id();
            // The part after /s/. Six url-safe characters, unambiguous alphabet.
            $table->string('code', 12)->unique();
            $table->text('url');
            // Same destination is reused across rows, so the sheet's ticket
            // link maps to one code however many sends point at it.
            $table->string('url_hash', 64)->index();
            $table->unsignedInteger('clicks')->default(0);
            $table->timestamp('last_clicked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('short_links');
    }
};
