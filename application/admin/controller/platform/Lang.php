<?php

namespace app\admin\controller\platform;

use app\common\controller\Backend;
use think\Config;

/**
 * API语言包管理
 *
 * @icon fa fa-language
 */
class Lang extends Backend
{

    /**
     * 语言包目录
     * @var string
     */
    protected $langDir = '';

    /**
     * 语言代码校验规则
     * @var string
     */
    protected $langRule = '/^[a-z]{2,5}(-[a-z]{2,5})?$/i';

    public function _initialize()
    {
        parent::_initialize();
        $this->langDir = APP_PATH . 'api/lang/';
    }

    /**
     * 语言包列表
     */
    public function index()
    {
        $list = $this->getLangList();
        if ($this->request->isAjax()) {
            return json(['total' => count($list), 'rows' => $list]);
        }
        $this->view->assign('list', $list);
        $this->view->assign('allowLangList', implode(', ', (array)Config::get('allow_lang_list')));
        $this->view->assign('defaultLang', Config::get('default_lang'));
        return $this->view->fetch();
    }

    /**
     * 扫描语言包目录生成列表数据
     * @return array
     */
    protected function getLangList()
    {
        $list = [];
        $files = glob($this->langDir . '*.php');
        if ($files) {
            foreach ($files as $file) {
                $code = pathinfo($file, PATHINFO_FILENAME);
                if (!preg_match($this->langRule, $code)) {
                    continue;
                }
                $data = include $file;
                $list[] = [
                    'code'    => $code,
                    'file'    => basename($file),
                    'items'   => is_array($data) ? count($data) : 0,
                    'size'    => round(filesize($file) / 1024, 2) . ' KB',
                    'default' => $code == Config::get('default_lang') ? 1 : 0,
                    'enabled' => in_array($code, (array)Config::get('allow_lang_list')) ? 1 : 0,
                ];
            }
        }
        return $list;
    }

    /**
     * 新增语言包
     */
    public function add()
    {
        if ($this->request->isPost()) {
            $this->token();
            $code = strtolower($this->request->post('code', '', 'trim'));
            $copyZh = (int)$this->request->post('copy_zh', 1);
            if (!preg_match($this->langRule, $code)) {
                $this->error(__('非法语言代码，只允许字母和连字符'));
            }
            $file = $this->langDir . $code . '.php';
            if (is_file($file)) {
                $this->error(__('语言包已存在'));
            }
            // 默认语言包不可被创建同名
            if ($code == 'zh-cn') {
                $this->error(__('zh-cn 是默认语言包'));
            }
            // 生成语言包：默认复制 zh-cn 模板便于翻译
            if ($copyZh && is_file($this->langDir . 'zh-cn.php')) {
                $content = file_get_contents($this->langDir . 'zh-cn.php');
                if ($content === false) {
                    $this->error(__('读取模板语言包失败'));
                }
            } else {
                $content = "<?php\n\nreturn [\n];\n";
            }
            $result = @file_put_contents($file, $content);
            if ($result === false) {
                $this->error(__('创建语言包失败，请检查目录权限'));
            }
            $this->success(__('语言包已创建，请在编辑页中翻译'), null, ['code' => $code]);
        }
        return $this->view->fetch();
    }

    /**
     * 编辑语言包
     * @param string $ids 语言代码
     */
    public function edit($ids = null)
    {
        $code = strtolower($ids);
        if (!preg_match($this->langRule, $code)) {
            $this->error(__('非法语言代码'));
        }
        $file = $this->langDir . $code . '.php';
        if (!is_file($file)) {
            $this->error(__('语言包不存在'));
        }
        if ($this->request->isPost()) {
            $this->token();
            $code = strtolower($this->request->post('lang', '', 'trim'));
            $file = $this->langDir . $code . '.php';
            if (!is_file($file)) {
                $this->error(__('语言包不存在'));
            }
            $items = $this->request->post('items/a', []);
            $data = [];
            foreach ($items as $item) {
                $key = trim(isset($item['key']) ? $item['key'] : '');
                $val = isset($item['value']) ? (string)$item['value'] : '';
                if ($key === '' && $val === '') {
                    continue;
                }
                if ($key === '') {
                    $this->error(__('语言键不能为空'));
                }
                if (isset($data[$key])) {
                    $this->error(__('重复的语言键') . ': ' . $key);
                }
                $data[$key] = $val;
            }
            $content = "<?php\n\nreturn " . var_export_short($data, true) . ";\n";
            $result = @file_put_contents($file, $content);
            if ($result === false) {
                $this->error(__('保存语言包失败，请检查目录权限'));
            }
            $this->success(__('保存成功'));
        }
        $data = include $file;
        $this->view->assign('lang', $code);
        $this->view->assign('items', is_array($data) ? $data : []);
        $this->view->assign('enabled', in_array($code, (array)Config::get('allow_lang_list')) ? 1 : 0);
        $this->view->assign('allowLangList', implode(', ', (array)Config::get('allow_lang_list')));
        return $this->view->fetch();
    }

    /**
     * 删除语言包
     * @param string $ids 语言代码
     */
    public function del($ids = null)
    {
        if (!$this->request->isPost()) {
            $this->error(__('Invalid request'));
        }
        $this->token();
        $code = strtolower($ids);
        if (!preg_match($this->langRule, $code)) {
            $this->error(__('非法语言代码'));
        }
        if ($code == Config::get('default_lang')) {
            $this->error(__('默认语言包不能删除'));
        }
        $file = $this->langDir . $code . '.php';
        if (!is_file($file)) {
            $this->error(__('语言包不存在'));
        }
        if (!@unlink($file)) {
            $this->error(__('删除语言包失败，请检查目录权限'));
        }
        $this->success(__('删除成功'));
    }
}
