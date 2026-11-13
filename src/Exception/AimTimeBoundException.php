<?php

declare(strict_types=1);

namespace Drupal\aim\Exception;

/**
 * A fact looks true only for a date or period but has no end date (ADR-0057).
 *
 * An InvalidArgumentException, so every caller that already reports a
 * refused write reports this one; a caller that can ask for the date
 * (aim_remember) catches it first.
 */
class AimTimeBoundException extends \InvalidArgumentException {

}
