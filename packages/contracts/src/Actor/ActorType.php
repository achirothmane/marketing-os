<?php
declare(strict_types=1);
namespace MarketingOS\Contracts\Actor;
enum ActorType: string {
    case HUMAN='HUMAN';
    case SERVICE='SERVICE';
    case AUTOMATION='AUTOMATION';
    case SYSTEM='SYSTEM';
    case EXTERNAL='EXTERNAL';
}
