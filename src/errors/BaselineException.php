<?php

namespace dgrigg\canary\errors;

use yii\base\Exception;

/**
 * A stored baseline exists but can't be used.
 */
class BaselineException extends Exception
{
    public function getName(): string
    {
        return 'Baseline Exception';
    }
}
