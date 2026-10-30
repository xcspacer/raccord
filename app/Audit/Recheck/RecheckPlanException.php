<?php

namespace App\Audit\Recheck;

use RuntimeException;

/**
 * The plan of a box cannot be used for the recheck (no plan, not an Excel workbook, unknown layout, no line).
 */
class RecheckPlanException extends RuntimeException {}
