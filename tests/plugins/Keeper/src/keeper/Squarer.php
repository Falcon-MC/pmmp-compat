<?php

declare(strict_types=1);

namespace keeper;

// loaded the first time a thread needs it, through the server's class loader
final class Squarer{
    public static function square(int $value) : int{
        return $value * $value;
    }
}
