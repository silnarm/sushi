<?php

namespace Sushi;

trait SushiTrain
{
    use Sushi {
        sushiCache as _Sushi_sushiCache;
    }

    public static function sushiCache()
    {
        return 'sushi-train';
    }
}
