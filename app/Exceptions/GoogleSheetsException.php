<?php

namespace App\Exceptions;

use Exception;

/**
 * Raised when the Sheets API refuses a read — sheet not shared with the connected
 * account, bad range, API not enabled on the Cloud project.
 */
class GoogleSheetsException extends Exception {}
