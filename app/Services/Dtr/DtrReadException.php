<?php

namespace App\Services\Dtr;

use RuntimeException;

/** An uploaded file that can't be read as a Daily Time Record; the message says why, for the person uploading it. */
class DtrReadException extends RuntimeException {}
