<?php
declare(strict_types=1);
namespace MarketingOS\Identity\Transport;

use Doctrine\DBAL\DriverManager;
use MarketingOS\Contracts\Id\WorkspaceId;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\Connection;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;

/**
 * Explicit bootstrap, not application auto-registration.
 * Queue is unique per workspace; transport table is migration-owned.
 */
final class MosDoctrineTransportFactory {
    public static function create(WorkspaceId $workspace,array $params,int $redeliverSeconds=60): DoctrineTransport {
        if($redeliverSeconds<1||$redeliverSeconds>3600)throw new \InvalidArgumentException('Invalid redelivery timeout.');
        $dbal=DriverManager::getConnection($params);
        return new DoctrineTransport(new Connection([
          'table_name'=>'mos_messenger_messages',
          'queue_name'=>'mos_identity_'.(string)$workspace,
          'redeliver_timeout'=>$redeliverSeconds,
          'auto_setup'=>false
        ],$dbal),new MosMessengerJsonSerializer());
    }
}
