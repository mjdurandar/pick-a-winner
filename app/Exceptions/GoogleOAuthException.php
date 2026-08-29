<?php

namespace App\Exceptions;

use Exception;

/**
 * Raised when the Google OAuth handshake or a token refresh fails. The message
 * is safe to show an admin — anything that could echo the client secret stays in
 * the log.
 */
class GoogleOAuthException extends Exception {}
