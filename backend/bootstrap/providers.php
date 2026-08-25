<?php

use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;

use App\Providers\ExternalModuleServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    ExternalModuleServiceProvider::class,
];
