<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('film_site_checks', function (Blueprint $table) {
            $table->id();

            // The event whose locations are compared against the site. Explicit,
            // because one film site publishes several seasons and regions and
            // the Win App holds a separate event per year.
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();

            // e.g. https://womensadventurefilmtour.com — the WordPress site root.
            $table->string('site_url');

            // Taxonomy slugs on the film site ('au,new-zealand', '2026'). Region
            // takes several because one event can span regions the site files
            // separately. Nullable: a site that does not split by region or
            // season is compared whole.
            $table->string('region', 100)->nullable();
            $table->string('season', 50)->nullable();

            $table->boolean('enabled')->default(true);

            // Headline of the most recent run, so the dashboard and nav badge can
            // show the state of every check without opening its run log.
            $table->timestamp('last_checked_at')->nullable();
            $table->string('last_status')->nullable();
            $table->text('last_error')->nullable();
            $table->unsignedInteger('last_errors')->default(0);
            $table->unsignedInteger('last_warnings')->default(0);

            $table->timestamps();

            $table->unique(['event_id', 'site_url', 'region', 'season']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('film_site_checks');
    }
};
