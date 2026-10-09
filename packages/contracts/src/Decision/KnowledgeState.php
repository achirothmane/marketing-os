<?php
declare(strict_types=1);
namespace MarketingOS\Contracts\Decision;
enum KnowledgeState: string {
    case KNOWN='KNOWN';
    case UNKNOWN='UNKNOWN';
    case AMBIGUOUS='AMBIGUOUS';
    case CONFLICTING='CONFLICTING';
    case REFUSED='REFUSED';
}
