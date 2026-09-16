<?php

namespace App\Exceptions;

use Exception;

/**
 * Raised when a film site cannot be compared — the WordPress REST API is not
 * reachable, or the region/season does not exist on that site. Messages are
 * written for an admin to act on and are shown on the dashboard as-is.
 */
class FilmSiteException extends Exception {}
