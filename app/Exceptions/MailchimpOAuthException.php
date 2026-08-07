<?php

namespace App\Exceptions;

use Exception;

/**
 * Raised when the OAuth handshake with Mailchimp cannot be completed. Carries a
 * message safe to show an admin — the underlying response body is logged, not
 * surfaced, since it can echo back credentials.
 */
class MailchimpOAuthException extends Exception {}
