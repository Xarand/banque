<?php
declare(strict_types=1);

namespace App;

use PDO;

class MicroActivityRepository
{
    public function __construct(private PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function listAll(): array
    {
        $st = $this->pdo->query("SELECT * FROM micro_activity_rates ORDER BY code ASC");
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function get(int $id): ?array
    {
        $st = $this->pdo->prepare("SELECT * FROM micro_activity_rates WHERE id=:id LIMIT 1");
        $st->execute([':id'=>$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    public function getByCode(string $code): ?array
    {
        $st = $this->pdo->prepare("SELECT * FROM micro_activity_rates WHERE code=:c LIMIT 1");
        $st->execute([':c'=>$code]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    public function create(array $data): int
    {
        $sql = "
          INSERT INTO micro_activity_rates(
            code,label,family,social_rate,ir_rate,cfp_rate,
            chamber_type,chamber_rate_default,chamber_rate_alsace,chamber_rate_moselle,
            ca_ceiling,tva_ceiling,tva_ceiling_major,tva_alert_threshold,created_at
          ) VALUES (
            :code,:label,:family,:sr,:ir,:cfp,
            :ch_type,:ch_def,:ch_al,:ch_mo,
            :ca,:tva,:tvaM,:tvaThr,datetime('now')
          )
        ";
        $st = $this->pdo->prepare($sql);
        $st->execute([
            ':code'=>$data['code'],
            ':label'=>$data['label'],
            ':family'=>$data['family'],
            ':sr'=>$data['social_rate'],
            ':ir'=>$data['ir_rate'],
            ':cfp'=>$data['cfp_rate'],
            ':ch_type'=>$data['chamber_type'],
            ':ch_def'=>$data['chamber_rate_default'],
            ':ch_al'=>$data['chamber_rate_alsace'],
            ':ch_mo'=>$data['chamber_rate_moselle'],
            ':ca'=>$data['ca_ceiling'],
            ':tva'=>$data['tva_ceiling'],
            ':tvaM'=>$data['tva_ceiling_major'],
            ':tvaThr'=>$data['tva_alert_threshold'],
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $sql = "
          UPDATE micro_activity_rates
             SET code=:code,
                 label=:label,
                 family=:family,
                 social_rate=:sr,
                 ir_rate=:ir,
                 cfp_rate=:cfp,
                 chamber_type=:ch_type,
                 chamber_rate_default=:ch_def,
                 chamber_rate_alsace=:ch_al,
                 chamber_rate_moselle=:ch_mo,
                 ca_ceiling=:ca,
                 tva_ceiling=:tva,
                 tva_ceiling_major=:tvaM,
                 tva_alert_threshold=:tvaThr,
                 updated_at=datetime('now')
           WHERE id=:id
        ";
        $st = $this->pdo->prepare($sql);
        $st->execute([
            ':id'=>$id,
            ':code'=>$data['code'],
            ':label'=>$data['label'],
            ':family'=>$data['family'],
            ':sr'=>$data['social_rate'],
            ':ir'=>$data['ir_rate'],
            ':cfp'=>$data['cfp_rate'],
            ':ch_type'=>$data['chamber_type'],
            ':ch_def'=>$data['chamber_rate_default'],
            ':ch_al'=>$data['chamber_rate_alsace'],
            ':ch_mo'=>$data['chamber_rate_moselle'],
            ':ca'=>$data['ca_ceiling'],
            ':tva'=>$data['tva_ceiling'],
            ':tvaM'=>$data['tva_ceiling_major'],
            ':tvaThr'=>$data['tva_alert_threshold'],
        ]);
    }

    public function delete(int $id): void
    {
        $st = $this->pdo->prepare("DELETE FROM micro_activity_rates WHERE id=:id");
        $st->execute([':id'=>$id]);
    }
}