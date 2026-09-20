<?php

namespace app\admin\model\department;

use think\Model;

class AuthGroupAccess extends Model
{
    /**
     * 关联角色组
     * @return \think\model\relation\HasMany
     */
    public function getGroup()
    {
        return $this->belongsTo('\app\admin\model\AuthGroup', 'group_id', 'id');
    }
}
