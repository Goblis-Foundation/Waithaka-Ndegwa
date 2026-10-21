<?php
declare(strict_types=1);
final class WkDatabaseSessions implements SessionHandlerInterface,SessionUpdateTimestampHandlerInterface {
    private ?string $lock=null;
    public function open(string $path,string $name):bool{return true;}
    public function close():bool{if($this->lock!==null){db()->prepare('SELECT RELEASE_LOCK(?)')->execute([$this->lock]);$this->lock=null;}return true;}
    public function validateId(string $id):bool{$q=db()->prepare('SELECT 1 FROM app_sessions WHERE id=? AND expires_at>?');$q->execute([$id,time()]);return(bool)$q->fetchColumn();}
    public function read(string $id):string|false{
        $this->lock='wk-session-'.substr(hash('sha256',$id),0,40);$q=db()->prepare('SELECT GET_LOCK(?,5)');$q->execute([$this->lock]);if((int)$q->fetchColumn()!==1)throw new RuntimeException('Session is busy.');
        $q=db()->prepare('SELECT payload FROM app_sessions WHERE id=? AND expires_at>?');$q->execute([$id,time()]);$raw=$q->fetchColumn();return$raw===false?'':decrypt_secret((string)$raw);
    }
    public function write(string $id,string $data):bool{return db()->prepare('INSERT INTO app_sessions(id,payload,expires_at) VALUES(?,?,?) ON DUPLICATE KEY UPDATE payload=VALUES(payload),expires_at=VALUES(expires_at)')->execute([$id,encrypt_secret($data),time()+7200]);}
    public function destroy(string $id):bool{return db()->prepare('DELETE FROM app_sessions WHERE id=?')->execute([$id]);}
    public function gc(int $max_lifetime):int|false{$q=db()->prepare('DELETE FROM app_sessions WHERE expires_at<?');$q->execute([time()]);return$q->rowCount();}
    public function updateTimestamp(string $id,string $data):bool{return db()->prepare('UPDATE app_sessions SET expires_at=? WHERE id=?')->execute([time()+7200,$id]);}
}
