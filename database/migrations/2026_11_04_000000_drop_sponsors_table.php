<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Drops the per-event `sponsors` table.
 *
 * Partners are owned by the film sites, not by this app: they are read and
 * written over the WordPress REST API by the partner dashboard, and nothing is
 * stored here. This table came from an earlier design that kept a second copy,
 * which is exactly the drift the dashboard exists to avoid.
 *
 * Running this removes any rows still in it. Check what is there first — on a
 * database where the earlier design was used, those rows are the only record of
 * what was entered.
 *
 * There is no down(): the create migration went with the feature, and an empty
 * table for a screen that no longer exists would only mislead. Restore from git
 * if it is ever needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('sponsors');
    }
};
