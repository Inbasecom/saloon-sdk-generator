<?php

declare(strict_types=1);

namespace Crescat\SaloonSdkGenerator;

use Crescat\SaloonSdkGenerator\Contracts\Deserializable;
use Crescat\SaloonSdkGenerator\Traits\Deserializes;

abstract class BaseDto implements Deserializable
{
    use Deserializes;
}
