<?php

declare(strict_types=1);

namespace App\Payroll\Domain\Exception;

use DomainException;

/** Base type for every rule violation raised by the payroll domain. */
abstract class PayrollException extends DomainException {}
