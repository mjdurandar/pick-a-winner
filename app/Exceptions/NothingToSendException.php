<?php

namespace App\Exceptions;

/**
 * A manual send found nothing worth mailing — a site that matches the app, a
 * tab with no changes waiting. Reported back as skipped, not as a failure.
 */
class NothingToSendException extends \RuntimeException {}
