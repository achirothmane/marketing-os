<?php
declare(strict_types=1);
namespace MarketingOS\Identity\Transport;
use PDO;
final class MosIdentityProjection {
    /** Applies ONLY already committed imported Person events. */
    public static function apply(array $event,PDO $db):void {
        if(($event['event_type']??'')!=='identity.person.imported.v1') {
            throw new \RuntimeException('UNSUPPORTED_EVENT_TYPE');
        }
        $q=$db->prepare('SELECT aggregate_id FROM mos_domain_event
            WHERE workspace_id=? AND event_id=? AND event_type=?');
        $q->execute([$event['workspace_id'],$event['event_id'],'identity.person.imported.v1']);
        $id=$q->fetchColumn();
        if(!is_string($id))throw new \RuntimeException('UNKNOWN_IMPORTED_PERSON');
        $s=$db->prepare('INSERT INTO mos_identity_projection
            (workspace_id,person_id,imported_event_id,created_at)
            VALUES(?,?,?,UTC_TIMESTAMP(6))');
        $s->execute([$event['workspace_id'],$id,$event['event_id']]);
    }
}
