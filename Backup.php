<?php
namespace FreePBX\modules\Xmpp;
use FreePBX\modules\Backup as Base;
class Backup Extends Base\BackupBase{
  public function runBackup($id,$transaction){
    $xmpp = $this->FreePBX->Xmpp;
    $configs = [
        'users' => $xmpp->getAllUsers(),
        'options' => $xmpp->getAllOptions(),
    ];

    $this->addDependency('pm2');
    $this->addDependency('userman');
    $this->addConfigs($configs);
  }
}