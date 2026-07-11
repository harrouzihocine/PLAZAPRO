<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\CollaborationServiceProvider;
use App\Providers\RbacServiceProvider;
use App\Providers\WebServiceProvider;

return [
    AppServiceProvider::class,
    RbacServiceProvider::class,
    CollaborationServiceProvider::class,
    WebServiceProvider::class,
];
