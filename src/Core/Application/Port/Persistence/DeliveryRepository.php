<?php

declare(strict_types=1);

namespace ChatSync\Core\Application\Port\Persistence;

use ChatSync\Core\Domain\Model\Delivery;

interface DeliveryRepository
{
    public function save(Delivery $delivery): void;
}

