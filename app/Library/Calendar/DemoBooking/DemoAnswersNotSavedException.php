<?php

namespace App\Library\Calendar\DemoBooking;

use RuntimeException;

/** The form-answer snapshot could not be stored with a /book-demo booking, so the booking was rolled back. */
class DemoAnswersNotSavedException extends RuntimeException
{
}
