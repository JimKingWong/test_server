<?php

namespace app\admin\controller\department;

use app\admin\model\department\Department;
use app\admin\model\department\Department as DepartmentModel;
use app\common\controller\Backend;
use \app\admin\model\department\Admin as DepartmentAdminModel;
use \app\admin\model\department\AuthAdmin as AdminModel;
use fast\Random;
use fast\Tree;
use think\Db;
use think\Model;
use think\Validate;


/**
 * 部门成员
 */
class Admin extends Backend
{

    protected $tree = null;
    /**
     * 部门人员对象
     * @var null
     */
    protected $dadminModel = null;
    protected $childrenGroupIds = [];//权限组
    /**
     * 部门所有
     * @var array
     */
    protected $allDepartment = [];//
    protected $noNeedRight = ['selectpage', 'getDepartmentIds', 'qrcode'];


    public function _initialize()
    {
        parent::_initialize();
        $this->childrenGroupIds = $this->auth->getChildrenGroupIds($this->auth->isSuperAdmin());

        $this->dadminModel = new DepartmentAdminModel;
        $departmentList = [];
        $this->allDepartment = \app\admin\model\department\Admin::getAllDepartmentsArray($this->auth->id, ($this->auth->isSuperAdmin() || $this->auth->data_scope) ? true : false);
        $this->allDepartment = collection($this->allDepartment)->toArray();

        $departmentdata = [];
        foreach ($this->allDepartment as $k => $v) {
            $state = ['opened' => true];
            $departmentList[] = [
                'id' => $v['id'],
                'parent' => $v['parent_id'] ? $v['parent_id'] : '#',
                'text' => preg_replace('/&nbsp;|\s*|│|└|├\s*/', '', __($v['name'])),//str_replace("&nbsp;",'', __($v['name'])) ,
                'state' => $state
            ];
        }


        $tree = Tree::instance()->init($this->allDepartment, 'parent_id');
        $departmentOptions = $tree->getTree(0, "<option model='@model_id' value=@id @selected @disabled>@spacer@name</option>");

        $this->view->assign('departmentOptions', $departmentOptions);
        $this->assignconfig('departmentList', $departmentList);

        $result = Tree::instance()->getTreeList(Tree::instance()->getTreeArray(0));
        foreach ($result as $k => $v) {
            $departmentdata[$v['id']] = $v['name'];
        }
        $this->view->assign('departmentdata', $departmentdata);

        //兼容旧版本没有手机号
        $database = config('database');
        $exits_mobile = \think\Db::query("SHOW COLUMNS FROM {$database['prefix']}admin LIKE 'mobile'");
        $this->view->assign('exits_mobile', $exits_mobile ? 1 : 0);
        $this->assignconfig('exits_mobile', $exits_mobile ? 1 : 0);
    }

    /**
     * 成员列表
     */
    public function index()
    {
        //设置过滤方法
        $this->request->filter(['strip_tags']);
        $department_id = $this->request->request("department_id");
        if ($this->request->isAjax()) {
            $this->model = new \app\admin\model\department\AuthAdmin();
            $filter = $this->request->get("filter", '');
            $filter = (array)json_decode($filter, true);
            $filter_w = [];

            if (isset($filter['department_id'])) {
                $department_id = $filter['department_id'];
                unset($filter['department_id']);
                $this->request->get(['filter' => json_encode($filter)]);
            }
            if ($department_id) {
                if (array_diff(explode(',', $department_id), array_column($this->allDepartment, 'id'))) {
                    $this->error("您所选的部门没有权限");
                }
                $admin_ids = $this->dadminModel->where('department_id', 'in', $department_id)->column('admin_id');
                $filter_w['id'] = ['in', $admin_ids];
            } else if ($this->auth->data_scope != 1 && !$this->auth->isSuperAdmin()) {
                $this->childrenAdminIds = \app\admin\model\department\Admin::getChildrenAdminIds($this->auth->id, true);
                $filter_w['id'] = ['in', $this->childrenAdminIds];
            }

            if (!$this->auth->check('department/admin/edit')) {
                //有编辑权限的人才有查看离职员工的功能
                $filter_w['status'] = "normal";
            }


            list($where, $sort, $order, $offset, $limit) = $this->buildparams();
            $total = $this->model
                ->where($where)
                ->where($filter_w)
                ->order($sort, $order)->fetchSql(false)
                ->count();
            $list = $this->model
                ->where($where)
                ->with(['dadmin.department'])
                ->with(['groups.getGroup'])
                ->where($filter_w)
                ->order($sort, $order)
                ->limit($offset, $limit)->fetchSql(false)
                ->select();


            $result = array("total" => $total, "rows" => $list);

            return json($result);
        }
        return $this->view->fetch();
    }


    /**
     * 添加
     * @return string|\think\response\Json
     * @throws \think\Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function add()
    {
        $groupdata = DepartmentModel::getGroupdata(
            $this->childrenGroupIds,
            $this->auth->isSuperAdmin() ? null : $this->auth->getGroups()
        );

        if ($this->request->isPost()) {

            $adminModel = new AdminModel();
            $departmentModel = new DepartmentModel();

            $params = $this->request->post("row/a");
            if ($params) {
                Db::startTrans();
                try {
                    $department_id = $this->request->post("department_id");//主部门
                    $department_ids = $this->request->post("department_ids/a");
                    array_push($department_ids, $department_id);
                    $is_principal = $this->request->post("is_principal", '0', 'intval');

                    //获取部门信息
                    if (!$department_ids) {
                        exception(__("部门不能为空"));
                    }
                    $d_list = $departmentModel->where('id', 'in', $department_ids)->select();

                    if (!$d_list) {
                        exception(__("部门不能为空"));
                    }

                    if (!Validate::is($params['password'], '\S{6,16}')) {
                        exception(__("Please input correct password"));
                    }
                    $params['salt'] = Random::alnum();
                    $params['password'] = md5(md5($params['password']) . $params['salt']);
                    $params['avatar'] = '/assets/img/avatar.png'; //设置新管理员默认头像。
                    $result = $adminModel->validate('Admin.add')->save($params);
                    if ($result === false) {
                        exception($adminModel->getError());
                    }
                    if (!$this->auth->isSuperAdmin() && isset($params['data_scope'])) {
                        unset($params['data_scope']);//超级管理人才能赋予全部数据权限
                    }

                    $admin_id = $adminModel->id;
                    $dadmin = array();
                    //添加部门信息
                    foreach ($d_list as $d_row) {
                        $dadmin[] = [
                            'department_id' => $d_row->id,
                            'organise_id' => $d_row->organise_id ? $d_row->organise_id : $d_row->id,
                            'admin_id' => $admin_id,
                            'create_time' => time(),
                            'is_principal' => ($d_row->id == $department_id && $is_principal) ? 1 : 0,
                            'is_owner' => $d_row->id == $department_id ? 1 : 0,//设置主部门

                        ];
                    }
                    $this->dadminModel->saveAll($dadmin);

                    $group = $this->request->post("group/a");
                    //过滤不允许的组别,避免越权
                    $group = array_intersect($this->childrenGroupIds, $group);

                    //添加权限默认
                    if (!$group) {
                        exception(__('The parent group exceeds permission limit'));
                    }

                    $dataset = [];
                    foreach ($group as $value) {
                        $dataset[] = ['uid' => $admin_id, 'group_id' => $value];
                    }
                    model('AuthGroupAccess')->saveAll($dataset);
                    hook("department_admin_add", $adminModel);
                    Db::commit();
                } catch (\Exception $e) {
                    Db::rollback();
                    $this->error($e->getMessage());
                }

                $this->success();
            }
            $this->error(__('Parameter %s can not be empty', ''));
        }
        $this->view->assign('groupdata', $groupdata);
        return $this->view->fetch();
    }

    /**
     * 修改
     * @param null $ids
     * @return string|\think\response\Json
     * @throws \think\Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function edit($ids = null)
    {

        $this->model = new AdminModel();
        $departmentModel = new DepartmentModel();
        $row = $this->model->get($ids);
        if (!$row) {
            $this->error(__('No Results were found'));
        }
        if (!\app\admin\model\department\Admin::checkDataAuth($this->auth, $row, 'id')) {
            $this->error(__('You have no permission'));
        }
        if ($this->request->isPost()) {

            //判断是不是超级管理员编辑
            if (!$this->auth->isSuperAdmin() && DepartmentAdminModel::isSuperAdmin($row->id)) {
                $this->error("您无权操作超级管理员");
            }

            $params = $this->request->post("row/a");

            if ($params) {
                Db::startTrans();
                try {
                    $department_id = $this->request->post("department_id");//主部门
                    $department_ids = $this->request->post("department_ids/a");
                    array_push($department_ids, $department_id);

                    //获取部门信息
                    if (!$department_ids) {
                        exception(__("Department can't null"));
                    }
                    $d_list = $departmentModel->where('id', 'in', $department_ids)->select();

                    if (!$d_list) {
                        exception(__("Department can't null"));
                    }

                    if ($params['password']) {
                        if (!Validate::is($params['password'], '\S{6,16}')) {
                            exception(__("Please input correct password"));
                        }
                        $params['salt'] = Random::alnum();
                        $params['password'] = md5(md5($params['password']) . $params['salt']);
                    } else {
                        unset($params['password'], $params['salt']);
                    }
                    if (!$this->auth->isSuperAdmin() && isset($params['data_scope'])) {
                        unset($params['data_scope']);//超级管理人才能赋予全部数据权限
                    }
                    //这里需要针对username和email做唯一验证
                    $adminValidate = \think\Loader::validate('Admin');
                    $adminValidate->rule([
                        'username' => 'require|regex:\w{3,20}|unique:admin,username,' . $row->id,
                        'email' => 'require|email|unique:admin,email,' . $row->id,
                        'mobile' => 'regex:1[3-9]\d{9}|unique:admin,mobile,' . $row->id,
                        'password' => 'regex:\S{32}',
                    ]);
                    $result = $row->validate('Admin.edit')->save($params);
                    if ($result === false) {
                        exception($row->getError());
                    }

                    //已存在的部门
                    $exist_list = $this->dadminModel->where('admin_id', $row->id)->select();
                    $exist_departmentids = array_column($exist_list, 'department_id');

                    //添加新的部门信息
                    $dadmin = array();
                    foreach ($d_list as $d_row) {
                        $exist_key = array_search($d_row['id'], $exist_departmentids);
                        if ($exist_key !== false) {
                            $exist_row = $exist_list[$exist_key];
                        } else {
                            $exist_row = [];
                        }

                        $dadmin[] = [
                            'department_id' => $d_row->id,
                            'organise_id' => $d_row->organise_id ? $d_row->organise_id : $d_row->id,
                            'admin_id' => $row->id,
                            'is_principal' => $exist_row ? $exist_row['is_principal'] : 0,
                            'create_time' => $exist_row ? $exist_row['create_time'] : time(),
                            'is_owner' => $d_row->id == $department_id ? 1 : 0,//设置主部门
                        ];


                    }

                    if ($exist_departmentids) {
                        //删除旧部门
                        $this->dadminModel->where('admin_id', $row->id)->where('department_id', 'in', $exist_departmentids)->delete();
                    }
                    if (count($dadmin) > 0) {
                        $this->dadminModel->saveAll($dadmin);
                    }


                    // 先移除所有权限
                    model('AuthGroupAccess')->where('uid', $row->id)->delete();

                    $group = $this->request->post("group/a");
                    // 过滤不允许的组别,避免越权
                    $group = array_intersect($this->childrenGroupIds, $group);
                    if (!$group) {
                        exception(__('The parent group exceeds permission limit'));
                    }

                    $dataset = [];
                    foreach ($group as $value) {
                        $dataset[] = ['uid' => $row->id, 'group_id' => $value];
                    }
                    model('AuthGroupAccess')->saveAll($dataset);
                    hook("department_admin_edit", $row);
                    Db::commit();
                } catch (\Exception $e) {
                    Db::rollback();
                    $this->error($e->getMessage());
                }
                $this->success();
            }
            $this->error(__('Parameter %s can not be empty', ''));
        }
        $groupdata = DepartmentModel::getGroupdata(
            $this->childrenGroupIds,
            $this->auth->isSuperAdmin() ? null : $this->auth->getGroups()
        );
        $grouplist = $this->auth->getGroups($row['id']);
        $groupids = [];
        foreach ($grouplist as $k => $v) {
            $groupids[] = $v['id'];
        }

        $department_id = DepartmentAdminModel::getOwnerDepartmentId($row->id, false);//主部门

        $this->view->assign("groupids", $groupids);
        $this->view->assign('row', $row);
        $this->view->assign("department_id", $department_id);
        $this->view->assign('department_ids', DepartmentAdminModel::getDepartmentIds($row->id, false, false));
        $this->view->assign('groupdata', $groupdata);
        return $this->view->fetch();
    }


    /**
     * 删除
     */
    public function del($ids = "")
    {
        if (!$this->request->isPost()) {
            $this->error(__("Invalid parameters"));
        }
        $this->childrenAdminIds = $this->auth->getChildrenAdminIds($this->auth->isSuperAdmin());
        $this->model = new AdminModel();

        $ids = $ids ? $ids : $this->request->post("ids");
        if ($ids) {
            $ids = array_intersect($this->childrenAdminIds, array_filter(explode(',', $ids)));
            // 避免越权删除管理员
            $childrenGroupIds = $this->childrenGroupIds;
            $adminList = $this->model->where('id', 'in', $ids)->where('id', 'in', function ($query) use ($childrenGroupIds) {
                $query->name('auth_group_access')->where('group_id', 'in', $childrenGroupIds)->field('uid');
            })->select();
            if ($adminList) {
                $deleteIds = [];
                foreach ($adminList as $k => $v) {
                    $deleteIds[] = $v->id;
                }
                $deleteIds = array_values(array_diff($deleteIds, [$this->auth->id]));
                if ($deleteIds) {
                    Db::startTrans();
                    try {
                        $this->model->destroy($deleteIds);
                        model('AuthGroupAccess')->where('uid', 'in', $deleteIds)->delete();
                        //删除部门员工信息
                        $this->dadminModel->where('admin_id', 'in', $deleteIds)->delete();;

                        Db::commit();
                    } catch (\Exception $e) {
                        Db::rollback();
                        $this->error($e->getMessage());
                    }
                    $this->success();
                }
                $this->error(__('No rows were deleted'));
            }
        }
        $this->error(__('You have no permission'));
    }

    /**
     * 批量更新
     */
    public function multi($ids = "")
    {
        $this->model = new AdminModel();
        return parent::multi($ids);
    }

    /**
     * 设置部门负责人
     */
    public function principal($ids = "")
    {

        $this->model = new AdminModel();
        $departmentModel = new DepartmentModel();
        $row = $this->model->get($ids);
        if ($this->request->isPost()) {

            //判断是不是超级管理员编辑
            if (!DepartmentAdminModel::isSuperAdmin($this->auth->id) && DepartmentAdminModel::isSuperAdmin($row->id)) {
                $this->error("您无权操作超级管理员");
            }

            $department_id = $this->request->post("department_id/a");
            if ($department_id && $department_id[0]) {
                Db::startTrans();
                try {
                    $d_list = $departmentModel->where('id', 'in', $department_id)->select();

                    if (!$d_list) {
                        exception(__("Department can't null"));
                    }
                    //先移除他所有负责的部门
                    $this->dadminModel->where('admin_id', $row->id)->update(['is_principal' => 0]);

                    //判断选择的部门是否存在，不存在就先把他加入
                    $p_departmentids = array_column($d_list, 'id');
                    $exist_departmentids = $this->dadminModel->where('admin_id', $row->id)->column('department_id');

                    $dadmin = array();
                    //添加部门信息
                    foreach ($d_list as $d_row) {
                        if (!in_array($d_row->id, $exist_departmentids)) {
                            $dadmin[] = ['department_id' => $d_row->id, 'organise_id' => $d_row->organise_id ? $d_row->organise_id : $d_row->id, 'admin_id' => $row->id];
                        }
                    }
                    if (count($dadmin) > 0) {
                        $this->dadminModel->saveAll($dadmin);
                    }
                    //更改为负责人
                    $this->dadminModel->where('admin_id', $row->id)->where('department_id', 'in', $p_departmentids)->update(['is_principal' => 1]);


                    Db::commit();
                } catch (\Exception $e) {
                    Db::rollback();
                    $this->error($e->getMessage());
                }

            } else {
                //移除他所有负责的部门
                $this->dadminModel->where('admin_id', $row->id)->update(['is_principal' => 0]);
            }
            $this->success();
        }


        $this->view->assign('row', $row);
        $this->view->assign('department_ids', $this->dadminModel::getPrincipalIds($ids));
        return $this->view->fetch();
    }

    /**
     * 获取员工的部门IDs
     */
    public function getDepartmentIds($id = "")
    {

        $departmentIds = DepartmentAdminModel::getDepartmentIds($id);
        //这里一定要返回有list这个字段,total是可选的,如果total<=list的数量,则会隐藏分页按钮
        $this->success("", '', ['departmentIds' => $departmentIds]);
    }

    /**
     * 下拉选择
     * @Internal
     */
    public function selectpage()
    {
        $this->model = new AdminModel();
        $type = $this->request->request('type');//all代表选择所有
        $custom = ['status' => 'normal'];
        if ($type != "all") {
            $childrenAdminIds = \app\admin\model\department\Admin::getChildrenAdminIds($this->auth->id, true);
            $custom['id'] = ['in', $childrenAdminIds];
        }
        $searchField = (array)$this->request->request("searchField/a");
        $searchField = array_merge($searchField, ['id', 'nickname', 'mobile']);
        $this->request->request(['searchField' => $searchField, 'custom' => $custom]);
        return parent::selectpage();
    }


    /**
     * 生成绑定二维码
     * @ApiSummary("直接返回图片流")
     * @ApiParams(name="ids", type="int", required=true, description="admin_id")
     * @ApiBody("返回的二维码数据");
     */
    public function qrcode($ids = null)
    {
        if (!$this->auth->check('department/admin/add')) {
            $this->error("你没有权限生成绑定码");
        }

        $admin_id = $this->request->request('ids', '', 'intval');
        $isview = $this->request->request('isview', '', 'intval');
        if (!$admin_id) {
            $this->error("员工不存在");
        }
        //判断是不是超级管理员编辑
        if (!$this->auth->isSuperAdmin() && DepartmentAdminModel::isSuperAdmin($admin_id)) {
            $this->error("您无权操作超级管理员");
        }


        $authAdmin = (new \app\admin\model\Admin());
        $row = $authAdmin->find($admin_id);
        if (!$row || $row['status'] != "normal") {
            $this->error("员工不存在,或已被禁用");
        }

        //查看是否被绑定
        $third = get_addon_info('third');

        if (!$third || $third['state'] != 1) {
            $this->error(__("请先安装第三方登录插件,或启用"));
        }

        $platform = 'admin_mp';
        $thirdModel = new \addons\third\model\Third();
        if ($third = $thirdModel->where('platform', $platform)->where('user_id', $admin_id)->find()) {
            $this->error(__("当前员工已被绑定"));
        }

        if ($isview) {
            return $this->view->fetch("", ['row' => $row]);
        }

        $atoken = Random::uuid();
        cache($atoken, $admin_id, 600);

        $text = url("department/third/bindconnect", ['ATOKEN' => $atoken], "", true);
        $qr = \addons\department\library\QRCode::getMinimumQRCode($text);
        $im = $qr->createImage(8, 5);
        imagepng($im);
        $content = ob_get_clean();
        imagedestroy($im);
        return response($content, 200, ['Content-Length' => strlen($content)])->contentType('image/png');
    }


}
