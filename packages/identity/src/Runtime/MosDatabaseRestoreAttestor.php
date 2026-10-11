<?php
declare(strict_types=1);
namespace MarketingOS\Identity\Runtime;

use PDO;
use RuntimeException;

/** Read-only comparison after actual SQL-dump import into an independent database. */
final readonly class MosDatabaseRestoreAttestor {
    public function __construct(private PDO $source,private PDO $restored) {}

    /** @return array{decision:string,tables:int,rows:int,mos_tables:int,encrypted_archives:int} */
    public function attest():array {
        if($this->source->inTransaction()||$this->restored->inTransaction()) {
            throw new RuntimeException('RESTORE_ACTIVE_TRANSACTION');
        }
        $a=(string)$this->source->query('SELECT DATABASE()')->fetchColumn();
        $b=(string)$this->restored->query('SELECT DATABASE()')->fetchColumn();
        if($a===''||$b===''||$a===$b)throw new RuntimeException('RESTORE_SAME_DATABASE');
        $tablesA=$this->tables($this->source);
        $tablesB=$this->tables($this->restored);
        if($tablesA!==$tablesB)throw new RuntimeException('RESTORE_TABLE_SET_DIVERGED');
        $tables=0;$rows=0;$mos=0;
        foreach($tablesA as $table) {
            $left=$this->digestTable($this->source,$table);
            $right=$this->digestTable($this->restored,$table);
            if($left!==$right)throw new RuntimeException('RESTORE_CONTENT_DIVERGED');
            $tables++;$rows+=$left['count'];
            if(str_starts_with($table,'mos_'))$mos++;
        }
        foreach(['leads','mos_workspace','mos_person','mos_evidence','mos_domain_event',
                 'mos_outbox','mos_inbox','mos_messenger_messages',
                 'mos_messenger_wire_quarantine','mos_schema_migration'] as $name) {
            if(!in_array($name,$tablesA,true))throw new RuntimeException('RESTORE_REQUIRED_TABLE_MISSING');
        }
        $q=$this->restored->query('SELECT COUNT(*) FROM mos_messenger_wire_quarantine');
        return [
            'decision'=>'DISPOSABLE_SQL_RESTORE_DB_STATE_MATCH',
            'tables'=>$tables,'rows'=>$rows,'mos_tables'=>$mos,
            'encrypted_archives'=>(int)$q->fetchColumn()
        ];
    }
    /** @return list<string> */
    private function tables(PDO $db):array {
        return $db->query("SELECT TABLE_NAME FROM information_schema.TABLES
          WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")
          ->fetchAll(PDO::FETCH_COLUMN);
    }
    /** @return array{metadata:string,hash:string,count:int} */
    private function digestTable(PDO $db,string $table):array {
        if(!preg_match('/^[A-Za-z0-9_]{1,64}$/D',$table))throw new RuntimeException('RESTORE_TABLE_NAME_UNSAFE');
        $meta=$db->prepare('SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,
          COLUMN_DEFAULT,EXTRA,CHARACTER_SET_NAME,COLLATION_NAME
          FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?
          ORDER BY ORDINAL_POSITION');
        $meta->execute([$table]);
        $schema=json_encode($meta->fetchAll(PDO::FETCH_ASSOC),JSON_THROW_ON_ERROR);
        $stmt=$db->query('SELECT * FROM '.chr(96).$table.chr(96));
        $digests=[];$count=0;
        while(($row=$stmt->fetch(PDO::FETCH_ASSOC))!==false) {
            $ctx=hash_init('sha256');
            foreach($row as $col=>$value){
                $name=(string)$col;
                hash_update($ctx,pack('N',strlen($name)).$name);
                if($value===null){hash_update($ctx,"\0");continue;}
                $bytes=(string)$value;
                hash_update($ctx,"\1".pack('N',strlen($bytes)).$bytes);
            }
            $digests[]=hash_final($ctx);
            $count++;
            if($count>100000)throw new RuntimeException('RESTORE_ROWS_OUT_OF_SCOPE');
        }
        sort($digests,SORT_STRING);
        $ctx=hash_init('sha256');
        foreach($digests as $digest)hash_update($ctx,$digest."\n");
        return ['metadata'=>$schema,'hash'=>hash_final($ctx),'count'=>$count];
    }
}
