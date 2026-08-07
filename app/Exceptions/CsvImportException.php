<?php

namespace App\Exceptions;

use Exception;

/**
 * A problem with the uploaded file itself. The message is written to be shown to
 * the admin and to say what to fix.
 */
class CsvImportException extends Exception {}
