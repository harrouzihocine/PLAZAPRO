<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\CollaborationServiceProvider;
use App\Providers\RbacServiceProvider;

return [
    AppServiceProvider::class,
    RbacServiceProvider::class,
    CollaborationServiceProvider::class,
];
