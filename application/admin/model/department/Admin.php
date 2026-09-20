<?php

namespace app\admin\model\department;

use app\admin\model\department\Department as DepartmentModel;
use fast\Tree;
use think\Db;
use think\Exception;
use think\Model;

class Admin extends Model
{
    // 表名
    protected $name = 'department_admin';
    // 自动写入时间戳字段
    protected $autoWriteTimestamp = 'int';
    // 定义时间戳字段名
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';

    /**
     * 关联部门
     */
    public function department()
    {
        return $this->hasOne('app\admin\model\department\Department', 'id', 'department_id');
    }

    /**
     * 获取指定部门的员工
     * @param $admin_id
     * @param bool $is_principal 是否取负责部门
     * @return array|bool|string
     */
    public static function getDepartmentAdminIds($departmentids)
    {
        //获取当前部门负责人
        $AdminIds = Db::name('department_admin')
            ->alias('da')
            ->join('__' . strtoupper('department') . '__ d', 'da.department_id = d.id')
            ->where('da.department_id', 'in', $departmentids)
            ->where('d.status', 'normal')
            ->column('da.admin_id');
        return $AdminIds;
    }

    /**
     * 获取员工者的部门ids
     * @param $admin_id
     * @param bool $is_principal 是否取负责部门
     * @param bool $has_owner 是否包含主部门
     * @return array|bool|string
     */
    public static function getDepartmentIds($admin_id, $is_principal = false, $has_owner = true)
    {
        $model = new self();
        if ($is_principal) $model->where('is_principal', 1);

        if ($has_owner != true) {
            $model->where('is_owner', 0);
        }

        return $model->where('admin_id', $admin_id)->column('department_id');
    }


    /**
     * 获取负责的部门IDs
     * @param $admin_id
     * @return array|bool|string
     */
    public static function getPrincipalIds($admin_id)
    {
        return self::where('admin_id', $admin_id)->where('is_principal', 1)->column('department_id');
    }

    /**
     * 获取组织(公司)ids
     * @param $admin_id
     * @param int $is_principal 是否只获取负责的部门
     * @return array|bool|string
     */
    public static function getOrganiseIds($admin_id, $is_principal = 0)
    {
        $where = array();
        if ($is_principal) $where['is_principal'] = 1;

        return self::where('admin_id', $admin_id)->where($where)->column('organise_id');
    }


    /**
     * 当前负责人下属ids
     * @param int $admin_id 某个管理员ID
     * @param boolean $withself 是否包含自身
     * @param string $department_ids 是否指定某个管理部门id，多个逗号id隔开
     * @return array
     */
    public static function getChildrenAdminIds($admin_id, $withself = false, $department_ids = null)
    {

        $cache_name = "getChildrenAdminIds" . ((string)$withself) . json_encode($department_ids) . $admin_id;
        $childrenAdminIds = cache($cache_name);
        if ($childrenAdminIds) {
            return $childrenAdminIds;
        }
        $childrenAdminIds = [];
        if (self::isSuperAdmin($admin_id)) {
            $childrenAdminIds = \app\admin\model\department\AuthAdmin::column('id');
        } else {
            $departmentIds = self::getChildrenDepartmentIds($admin_id, true);
            $authDepartmentList = self::field('admin_id,department_id')
                ->where('department_id', 'in', $departmentIds)
                ->select();
            foreach ($authDepartmentList as $k => $v) {
                $childrenAdminIds[] = $v['admin_id'];
            }
        }
        if ($withself) {
            if (!in_array($admin_id, $childrenAdminIds)) {
                $childrenAdminIds[] = $admin_id;
            }
        } else {
            $childrenAdminIds = array_diff($childrenAdminIds, [$admin_id]);
        }
        cache($cache_name, $childrenAdminIds, 3600);//缓存一个小时
        return $childrenAdminIds;


    }

    /**
     * 判断是否是超级管理员
     * @return bool
     */
    public static function isSuperAdmin($admin_id)
    {
        $auth = new \app\admin\library\Auth();
        return in_array('*', $auth->getRuleIds($admin_id)) ? true : false;
    }


    /**
     * 取出当前负责人管理的下级部门
     * @param boolean $withself 是否包含当前所在的分组
     * @return array
     */
    public static function getChildrenDepartmentIds($admin_id, $withself = false)
    {
        //取出当前负责人所有部门
        if (self::isSuperAdmin($admin_id)) {
            $departments = DepartmentModel::allDepartment();
        } else {
            $departments = self::getDepartments($admin_id, 1);
        }

        $departmenIds = [];
        foreach ($departments as $k => $v) {
            $departmenIds[] = $v['id'];
        }
        $originDepartmenId = $departmenIds;
        foreach ($departments as $k => $v) {
            if (in_array($v['parent_id'], $originDepartmenId)) {
                $departmenIds = array_diff($departmenIds, [$v['id']]);
                unset($departments[$k]);
            }
        }
        // 取出所有部门
        $departmentList = \app\admin\model\department\Department::allDepartment();
        $objList = [];
        foreach ($departments as $k => $v) {
            // 取出包含自己的所有子节点
            $childrenList = Tree::instance()->init($departmentList, 'parent_id')->getChildren($v['id'], true);
            $obj = Tree::instance()->init($childrenList, 'parent_id')->getTreeArray($v['parent_id']);
            $objList = array_merge($objList, Tree::instance()->getTreeList($obj));
        }
        $childrenDepartmenIds = [];
        foreach ($objList as $k => $v) {
            $childrenDepartmenIds[] = $v['id'];
        }
        if (!$withself) {
            $childrenDepartmenIds = array_diff($childrenDepartmenIds, $departmenIds);
        }
        return $childrenDepartmenIds;
    }


    /**
     * 根据用户id获取所在部门,返回值为数组
     * @param int $admin_id admin_id
     * @param int $admin_id $is_principal 是否只取负责的部分
     * @return array       用户所属的部门 array(
     *                  array('admin_id'=>'员工id','department_id'=>'部门id','name'=>'部门名称'),
     *                  ...)
     */
    public static function getDepartments($admin_id, $is_principal = 0)
    {
        static $departments = [];
        if (isset($departments[$admin_id])) {
            return $departments[$admin_id];
        }

        // 执行查询
        $user_departments = Db::name('department_admin')
            ->alias('da')
            ->join('__' . strtoupper('department') . '__ d', 'da.department_id = d.id', 'LEFT')
            ->field('da.admin_id,da.department_id,d.id,d.parent_id,d.name,d.tags')
            ->where("da.admin_id='{$admin_id}' " . ($is_principal ? "and is_principal=1" : '') . " and d.status='normal'")
            ->fetchSql(false)
            ->select();
        $departments[$admin_id] = $user_departments ?: [];
        return $departments[$admin_id];
    }

    /**
     * 获取当前用户可管理的所有部门
     * @param $admin_id
     * @param bool $isSuperAdmin
     * @return array|bool|false|\PDOStatement|string|\think\Collection
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public static function getAllDepartments($admin_id, $isSuperAdmin = false)
    {

        if ($isSuperAdmin) {
            $departmentList = DepartmentModel::allDepartment();
        } else {
            $departmentIds = \app\admin\model\department\Admin::getChildrenDepartmentIds($admin_id, true);
            $departmentList = collection(DepartmentModel::where('id', 'in', $departmentIds)->select())->toArray();
        }
        return $departmentList;
    }

    /**
     * 获取当前用户可管理的所有部门[key=>value]
     * @param $admin_id
     * @param bool $isSuperAdmin
     * @return array|bool|false|\PDOStatement|string|\think\Collection
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public static function getAllDepartmentsTreeArray($admin_id, $isSuperAdmin = false)
    {

        $departmentdata = array();
        if ($isSuperAdmin) {
            $departmentList = DepartmentModel::allDepartment();
            Tree::instance()->init($departmentList, 'parent_id');
            $result = Tree::instance()->getTreeList(Tree::instance()->getTreeArray(0));
            foreach ($result as $k => $v) {
                $departmentdata[$v['id']] = $v['name'];
            }
        } else {
            //获取当前可管理部门
            $departmentIds = \app\admin\model\department\Admin::getChildrenDepartmentIds($admin_id, true);
            $departmentList = collection(DepartmentModel::where('id', 'in', $departmentIds)->select())->toArray();
            Tree::instance()->init($departmentList, 'parent_id');

            $departments = \app\admin\model\department\Admin::getDepartments($admin_id);
            $issetIDs = array_column($departments, 'id');
            foreach ($departments as $m => $n) {
                if ($n['parent_id'] == 0) {
                    $result1 = Tree::instance()->getTreeList(Tree::instance()->getTreeArray(0));
                    foreach ($result1 as $k => $v) {
                        $departmentdata[$v['id']] = $v['name'];
                    }
                } else {
                    if (in_array($n['parent_id'], $issetIDs)) continue;
                    $childlist = Tree::instance()->getTreeList(Tree::instance()->getTreeArray(($n['parent_id'])));
                    foreach ($childlist as $k => $v) {
                        $departmentdata[$v['id']] = $v['name'];
                    }
                }
            }
        }

        return $departmentdata;
    }

    /**
     * 获取当前用户可管理的所有部门
     * @param $admin_id
     * @param bool $isSuperAdmin
     * @return array|bool|false|\PDOStatement|string|\think\Collection
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public static function getAllDepartmentsArray($admin_id, $isSuperAdmin = false)
    {
        $departmentList = array();
        if ($isSuperAdmin) {
            $departmentList = DepartmentModel::allDepartment();
            Tree::instance()->init($departmentList, 'parent_id');
            $departmentList = Tree::instance()->getTreeList(Tree::instance()->getTreeArray(0));

        } else {
            //获取当前可管理部门
            $departmentIds = self::getChildrenDepartmentIds($admin_id, true);

            $dList = collection(DepartmentModel::where('id', 'in', $departmentIds)->select())->toArray();
            Tree::instance()->init($dList, 'parent_id');


            $departments = \app\admin\model\department\Admin::getDepartments($admin_id);
            $issetIDs = array_column($departments, 'id');

            foreach ($departments as $m => $n) {
                if ($n['parent_id'] != 0) {
                    if (in_array($n['parent_id'], $issetIDs)) continue;
                    $childlist = Tree::instance()->getTreeList(Tree::instance()->getTreeArray(($n['parent_id'])));
                    foreach ($childlist as $k => $v) {
                        $k == 0 ? $v['parent_id'] = 0 : '';
                        $departmentList[] = $v;
                    }
                } else {
                    $childlist = Tree::instance()->getTreeList(Tree::instance()->getTreeArray($n['id']));
                    $childlist ? $n['haschild'] = 1 : '';
                    $departmentList[] = $n;
                    foreach ($childlist as $k => $v) {
                        $departmentList[] = $v;
                    }
                }
            }
        }
        return $departmentList;

    }


    /**
     * 获取上级负责人
     * @param bool $parent 如果当前部门没负责人，是否逐级寻找？
     * @param array $ignore 是否忽略当前uid
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public static function getParentAdminIds($uid, $parent = true, $ignore = false)
    {
        $principalIds = [];
        $departmentIds = self::getDepartmentIds($uid);//获取当前用户的所有部门ID，
        if ($departmentIds) {
            $principalIds = self::getDprincipalIds($departmentIds, $parent, $ignore ? [$uid] : []);
        }
        return $principalIds;
    }


    /**
     * 获取部门的负责人
     * @param $departmentIds 部门IDs
     * @param bool $parent 如果当前部门没负责人，是否逐级寻找？
     * @param array $ignore_ids 忽略的adminids
     * @return array|bool|string
     */
    public static function getDprincipalIds($departmentIds, $parent = true, $ignore_ids = [])
    {
        $daModel = Db::name('department_admin');
        if ($ignore_ids) {
            $daModel->where('da.admin_id', 'not in', $ignore_ids);
        }
        //获取当前部门负责人
        $principalIds = $daModel
            ->alias('da')
            ->join('__' . strtoupper('department') . '__ d', 'da.department_id = d.id')
            ->where('da.department_id', 'in', $departmentIds)
            ->where('is_principal', 1)
            ->where('d.status', 'normal')
            ->column('da.admin_id');
        if ($principalIds) {
            return $principalIds;//如果存在就直接返回
        }
        //上一级查找
        foreach ($departmentIds as $k => $v) {
            $newDepartmentIds = Department::getParentId($v);

            if ($newDepartmentIds) {
                return self::getDprincipalIds($newDepartmentIds, $parent);
            }
        }
        return [];

    }


    /**
     * 数据权限校验
     * @param $auth
     * @param $row
     * @param string $field
     * @return bool
     */
    public static function checkDataAuth($auth, $row, $field = "admin_id")
    {
        if ($auth->data_scope != 1 && !$auth->isSuperAdmin()) {
            $childrenAdminIds = \app\admin\model\department\Admin::getChildrenAdminIds($auth->id, true);
            if (!$row[$field] || !in_array($row[$field], $childrenAdminIds)) {
                return false;
            }
        }
        return true;
    }

    /**
     * 获取员工上级所有部门ids
     * @param $admin_id
     * @param bool $withself
     * @return array|mixed
     */
    public static function getParentDepartmentIds($admin_id, $withself = false)
    {
        //如已经存在直接返回
        static $parentDepartment = [];
        if (isset($parentDepartment[$admin_id])) {
            return $parentDepartment[$admin_id];
        }
        //获取当前员工的所在部门
        $departmentIds = self::getDepartmentIds($admin_id);
        if (!$departmentIds) return [];
        $tempdata = array();
        foreach ($departmentIds as $departmentId) {
            $tempdata = array_merge($tempdata, \app\admin\model\department\Department::getParentIds($departmentId, $withself));
        }

        $parentDepartment[$admin_id] = $tempdata ?: [];
        return $parentDepartment[$admin_id];
    }


    /**
     * 获取员工的主部门
     * @param $admin_id
     * @param bool $cache 缓存时效
     * @param bool $has 如没主部门获取一条其他部门，主要为了是兼容旧版本
     */
    public static function getOwnerDepartmentId($admin_id, $cache = 600, $has = true)
    {
        $model = new  self();
        if (!$has) {
            $model->where('is_owner', 1);
        }
        return $model->where('admin_id', $admin_id)->cache($cache)->order('is_owner desc')->value('department_id');
    }


}
