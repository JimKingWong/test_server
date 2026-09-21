<?php

namespace app\admin\model\game;

use think\Model;
use traits\model\SoftDelete;

class Category extends Model
{

    use SoftDelete;

    

    // 表名
    protected $name = 'game_category';
    
    // 自动写入时间戳字段
    protected $autoWriteTimestamp = 'integer';

    // 定义时间戳字段名
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';
    protected $deleteTime = 'deletetime';

    // 追加属性
    protected $append = [
        'is_nav_text',
        'is_home_show_text',
        'is_left_show_text',
        'direction_text',
        'status_text'
    ];
    

    protected static function init()
    {
        self::afterInsert(function ($row) {
            if (!$row['weigh']) {
                $pk = $row->getPk();
                $row->getQuery()->where($pk, $row[$pk])->update(['weigh' => $row[$pk]]);
            }
        });
    }

    
    public function getIsNavList()
    {
        return ['0' => __('Is_nav 0'), '1' => __('Is_nav 1')];
    }

    public function getIsHomeShowList()
    {
        return ['0' => __('Is_home_show 0'), '1' => __('Is_home_show 1')];
    }

    public function getIsLeftShowList()
    {
        return ['0' => __('Is_left_show 0'), '1' => __('Is_left_show 1')];
    }

    public function getDirectionList()
    {
        return ['0' => __('Direction 0'), '1' => __('Direction 1'), '2' => __('Direction 2')];
    }

    public function getStatusList()
    {
        return ['0' => __('Status 0'), '1' => __('Status 1')];
    }


    public function getIsNavTextAttr($value, $data)
    {
        $value = $value ?: ($data['is_nav'] ?? '');
        $list = $this->getIsNavList();
        return $list[$value] ?? '';
    }


    public function getIsHomeShowTextAttr($value, $data)
    {
        $value = $value ?: ($data['is_home_show'] ?? '');
        $list = $this->getIsHomeShowList();
        return $list[$value] ?? '';
    }


    public function getIsLeftShowTextAttr($value, $data)
    {
        $value = $value ?: ($data['is_left_show'] ?? '');
        $list = $this->getIsLeftShowList();
        return $list[$value] ?? '';
    }


    public function getDirectionTextAttr($value, $data)
    {
        $value = $value ?: ($data['direction'] ?? '');
        $list = $this->getDirectionList();
        return $list[$value] ?? '';
    }


    public function getStatusTextAttr($value, $data)
    {
        $value = $value ?: ($data['status'] ?? '');
        $list = $this->getStatusList();
        return $list[$value] ?? '';
    }




}
