<?php
declare(strict_types=1);
namespace MarketingOS\Identity;
enum MappingResolution: string {
    case UNBOUND='UNBOUND';
    case REUSE_EXISTING='REUSE_EXISTING';
}
