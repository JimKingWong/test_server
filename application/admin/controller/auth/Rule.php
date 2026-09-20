<?php

namespace app\admin\controller\auth;

use app\admin\model\AuthRule;
use app\common\controller\Backend;
use fast\Tree;
use think\Cache;

/**
 * 规则管理
 *
 * @icon   fa fa-list
 * @remark 规则通常对应一个控制器的方法,同时左侧的菜单栏数据也从规则中体现,通常建议通过控制台进行生成规则节点
 */
class Rule extends Backend
{

    /**
     * @var \app\admin\model\AuthRule
     */
    protected $model = null;
    protected $rulelist = [];
    protected $multiFields = 'ismenu,status';

    public function _initialize()
    {
        parent::_initialize();
        if (!$this->auth->isSuperAdmin()) {
            $this->error(__('Access is allowed only to the super management group'));
        }
        $this->model = model('AuthRule');
        // 必须将结果集转换为数组
        $ruleList = \think\Db::name("auth_rule")->field('type,condition,remark,createtime,updatetime', true)->order('weigh DESC,id ASC')->select();
        foreach ($ruleList as $k => &$v) {
            $v['title'] = __($v['title']);
        }
        unset($v);
        Tree::instance()->init($ruleList)->icon = ['&nbsp;&nbsp;&nbsp;&nbsp;', '&nbsp;&nbsp;&nbsp;&nbsp;', '&nbsp;&nbsp;&nbsp;&nbsp;'];
        $this->rulelist = Tree::instance()->getTreeList(Tree::instance()->getTreeArray(0), 'title');
        $ruledata = [0 => __('None')];
        foreach ($this->rulelist as $k => &$v) {
            if (!$v['ismenu']) {
                continue;
            }
            $ruledata[$v['id']] = $v['title'];
            unset($v['spacer']);
        }
        unset($v);
        $this->view->assign('ruledata', $ruledata);
        $this->view->assign("menutypeList", $this->model->getMenutypeList());
    }

    /**
     * 根据规则 name 解析控制器类名（最后一段首字母大写，兼容 api/lang => api\Lang）
     * @param string $name
     * @return string
     */
    protected function getControllerClass($name)
    {
        $parts = explode('/', trim($name, '/'));
        if (!$parts || $parts[0] === '') {
            return '';
        }
        $last = array_pop($parts);
        $prefix = $parts ? implode('\\', $parts) . '\\' : '';
        return 'app\\admin\\controller\\' . $prefix . ucfirst($last);
    }

    /**
     * 查看
     */
    public function index()
    {
        if ($this->request->isAjax()) {
            $list = $this->rulelist;
            foreach ($list as &$v) {
                // 标记该菜单是否对应真实存在的控制器，用于前端渲染"生成子菜单"按钮
                $v['has_ctrl'] = 0;
                if ($v['ismenu'] && $v['name']) {
                    $class = $this->getControllerClass($v['name']);
                    $v['has_ctrl'] = $class && class_exists($class) ? 1 : 0;
                }
            }
            unset($v);
            $total = count($list);
            $result = array("total" => $total, "rows" => $list);

            return json($result);
        }
        return $this->view->fetch();
    }

    /**
     * 获取规则下的候选子菜单（扫描控制器本类方法）
     * @param string $ids 规则ID
     */
    public function submenu($ids = null)
    {
        // 兼容 ?id= 与 /ids/ 两种传参方式
        $ids = $ids ?: $this->request->get('id');
        $row = $this->model->get(['id' => $ids]);
        if (!$row) {
            $this->error(__('No Results were found'));
        }
        $name = $row['name'];
        $class = $this->getControllerClass($name);
        $methods = [];
        if ($class && class_exists($class)) {
            $ref = new \ReflectionClass($class);
            foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
                // 只取本类声明的方法，跳过父类继承的方法与 _ 开头内部方法
                if ($m->getDeclaringClass()->getName() !== $class) {
                    continue;
                }
                $mname = $m->getName();
                if (strpos($mname, '_') === 0) {
                    continue;
                }
                $title = $mname;
                $doc = $m->getDocComment();
                if ($doc && preg_match('/\*\s*([^\s@*][^\n*]*)/', $doc, $mm)) {
                    $title = trim($mm[1]);
                }
                $exists = \think\Db::name('auth_rule')->where('name', $name . '/' . $mname)->count() > 0;
                $methods[] = ['method' => $mname, 'title' => $title, 'exists' => $exists];
            }
        }
        $this->success(__('Success'), null, ['id' => $row['id'], 'name' => $name, 'methods' => $methods]);
    }

    /**
     * 批量生成子菜单
     */
    public function gensubmenu()
    {
        if (!$this->request->isPost()) {
            $this->error(__('Invalid parameters'));
        }
        $id = $this->request->post('id/d');
        $methods = $this->request->post('methods/a', []);
        $row = $this->model->get(['id' => $id]);
        if (!$row) {
            $this->error(__('No Results were found'));
        }
        if (!$methods) {
            $this->error(__('Please select at least one submenu'));
        }
        $name = $row['name'];
        $class = $this->getControllerClass($name);
        $titleMap = [];
        if (class_exists($class)) {
            $ref = new \ReflectionClass($class);
            foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
                if ($m->getDeclaringClass()->getName() !== $class) {
                    continue;
                }
                $mname = $m->getName();
                $title = $mname;
                $doc = $m->getDocComment();
                if ($doc && preg_match('/\*\s*([^\s@*][^\n*]*)/', $doc, $mm)) {
                    $title = trim($mm[1]);
                }
                $titleMap[$mname] = $title;
            }
        }
        $existsNames = \think\Db::name('auth_rule')->where('name', 'in', array_map(function ($m) use ($name) {
            return $name . '/' . $m;
        }, $methods))->column('name');
        $time = time();
        $count = 0;
        foreach ($methods as $m) {
            $m = trim($m);
            if ($m === '') {
                continue;
            }
            $ruleName = $name . '/' . $m;
            if (in_array($ruleName, $existsNames)) {
                continue;
            }
            \think\Db::name('auth_rule')->insert([
                'type'       => 'file',
                'pid'        => $row['id'],
                'name'       => $ruleName,
                'title'      => isset($titleMap[$m]) ? $titleMap[$m] : $m,
                'icon'       => '',
                'ismenu'     => 0,
                'menutype'   => null,
                'status'     => 'normal',
                'weigh'      => 0,
                'createtime' => $time,
                'updatetime' => $time,
            ]);
            $count++;
        }
        if ($count) {
            Cache::rm('__menu__');
            $this->success(__('Generated %d submenus', $count));
        }
        $this->error(__('No new submenu to generate'));
    }

    /**
     * 添加
     */
    public function add()
    {
        if ($this->request->isPost()) {
            $this->token();
            $params = $this->request->post("row/a", [], 'strip_tags');
            if ($params) {
                if (!$params['ismenu'] && !$params['pid']) {
                    $this->error(__('The non-menu rule must have parent'));
                }
                $result = $this->model->validate()->save($params);
                if ($result === false) {
                    $this->error($this->model->getError());
                }
                $this->success();
            }
            $this->error();
        }
        return $this->view->fetch();
    }

    /**
     * 编辑
     */
    public function edit($ids = null)
    {
        $row = $this->model->get(['id' => $ids]);
        if (!$row) {
            $this->error(__('No Results were found'));
        }
        if ($this->request->isPost()) {
            $this->token();
            $params = $this->request->post("row/a", [], 'strip_tags');
            if ($params) {
                if (!$params['ismenu'] && !$params['pid']) {
                    $this->error(__('The non-menu rule must have parent'));
                }
                if ($params['pid'] == $row['id']) {
                    $this->error(__('Can not change the parent to self'));
                }
                if ($params['pid'] != $row['pid']) {
                    $childrenIds = Tree::instance()->init(collection(AuthRule::select())->toArray())->getChildrenIds($row['id']);
                    if (in_array($params['pid'], $childrenIds)) {
                        $this->error(__('Can not change the parent to child'));
                    }
                }
                //这里需要针对name做唯一验证
                $ruleValidate = \think\Loader::validate('AuthRule');
                $ruleValidate->rule([
                    'name' => 'require|unique:AuthRule,name,' . $row->id,
                ]);
                $result = $row->validate()->save($params);
                if ($result === false) {
                    $this->error($row->getError());
                }
                $this->success();
            }
            $this->error();
        }
        $this->view->assign("row", $row);
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
        $ids = $ids ? $ids : $this->request->post("ids");
        if ($ids) {
            $delIds = [];
            foreach (explode(',', $ids) as $k => $v) {
                $delIds = array_merge($delIds, Tree::instance()->getChildrenIds($v, true));
            }
            $delIds = array_unique($delIds);
            $count = $this->model->where('id', 'in', $delIds)->delete();
            if ($count) {
                Cache::rm('__menu__');
                $this->success();
            }
        }
        $this->error();
    }
}
