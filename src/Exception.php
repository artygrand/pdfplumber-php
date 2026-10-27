<?php

declare(strict_types=1);

namespace Artygrand\Pdfplumber;

/**
 * Thrown when a PDF cannot be read: not a PDF, broken beyond repair, or a wrong password.
 */
class Exception extends \RuntimeException
{
}
