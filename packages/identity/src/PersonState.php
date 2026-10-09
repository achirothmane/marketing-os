<?php
declare(strict_types=1);
namespace MarketingOS\Identity;
enum PersonState: string {
    case ACTIVE='ACTIVE';
    case MERGED='MERGED';
    case ERASED='ERASED';
}
