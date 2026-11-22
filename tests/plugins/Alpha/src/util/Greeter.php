<?php

declare(strict_types=1);

namespace alpha\plugin\util;

use beta\Shared;

// loaded only when Alpha first uses it, and reaches a class of the plugin it depends on
final class Greeter{
    public static function greet() : string{
        return "Alpha's greeter says " . Shared::greeting();
    }
}
