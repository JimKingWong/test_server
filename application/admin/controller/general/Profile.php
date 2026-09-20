<?php

namespace app\admin\controller\general;

use app\admin\model\Admin;
use app\common\controller\Backend;
use app\common\service\util\GoogleAuthenticator;
use Endroid\QrCode\QrCode;
use fast\Random;
use think\Session;
use think\Validate;

/**
 * 个人配置
 *
 * @icon fa fa-user
 */
class Profile extends Backend
{

    protected $searchFields = 'id,title';

    /**
     * 查看
     */
    public function index()
    {
        //设置过滤方法
        $this->request->filter(['strip_tags', 'trim']);
        if ($this->request->isAjax()) {
            $this->model = model('AdminLog');
            list($where, $sort, $order, $offset, $limit) = $this->buildparams();

            $list = $this->model
                ->where($where)
                ->where('admin_id', $this->auth->id)
                ->order($sort, $order)
                ->paginate($limit);

            $result = array("total" => $list->total(), "rows" => $list->items());

            return json($result);
        }
        // 谷歌验证器状态（未绑定时生成新密钥暂存Session，供页面展示）
        $admin = Admin::get($this->auth->id);
        $gaBound = !empty($admin->google_secret);
        $this->view->assign('gaBound', $gaBound);
        if (!$gaBound) {
            $googleAuth = new GoogleAuthenticator();
            $secret = Session::get('admin_ga_new_secret');
            if (!$secret) {
                $secret = $googleAuth->createSecret();
                Session::set('admin_ga_new_secret', $secret);
            }
            $gaUrl = $googleAuth->getQRCodeGoogleUrl($this->auth->username, $secret, config('site.name'));
            $this->view->assign('gaSecret', $secret);
            $this->view->assign('gaUrl', $gaUrl);
            // 生成二维码（otpauth 链接 → PNG base64，供扫码绑定）
            try {
                $qr = new QrCode($gaUrl);
                $this->view->assign('gaQr', 'data:image/png;base64,' . base64_encode($qr->writeString()));
            } catch (\Exception $e) {
                $this->view->assign('gaQr', '');
            }
        }

        $google_auth = config('system.google_auth');
        $this->view->assign('google_auth', $google_auth);
        return $this->view->fetch();
    }

    /**
     * 更新个人信息
     */
    public function update()
    {
        if ($this->request->isPost()) {
            $this->token();
            $params = $this->request->post("row/a");
            $params = array_filter(array_intersect_key(
                $params,
                array_flip(array('email', 'nickname', 'password', 'avatar'))
            ));
            unset($v);
            if (!Validate::is($params['email'], "email")) {
                $this->error(__("Please input correct email"));
            }
            if (isset($params['password'])) {
                if (!Validate::is($params['password'], "/^[\S]{6,30}$/")) {
                    $this->error(__("Please input correct password"));
                }
                $params['salt'] = Random::alnum();
                $params['password'] = md5(md5($params['password']) . $params['salt']);
            }
            $exist = Admin::where('email', $params['email'])->where('id', '<>', $this->auth->id)->find();
            if ($exist) {
                $this->error(__("Email already exists"));
            }
            if ($params) {
                $admin = Admin::get($this->auth->id);
                $admin->save($params);
                //因为个人资料面板读取的Session显示，修改自己资料后同时更新Session
                Session::set("admin", $admin->toArray());
                Session::set("admin.safecode", $this->auth->getEncryptSafecode($admin));
                $this->success();
            }
            $this->error();
        }
        return;
    }

    /**
     * 谷歌验证器绑定/解绑
     */
    public function google()
    {
        if ($this->request->isPost()) {
            $this->token();
            $action = $this->request->post('action', 'bind');
            $code = $this->request->post('code', '', 'trim');
            $googleAuth = new GoogleAuthenticator();
            $admin = Admin::get($this->auth->id);
            if (!$admin) {
                $this->error(__('Admin not found'));
            }
            if ($action == 'unbind') {
                // 解绑需校验当前动态码，防止误操作
                if ($admin->google_secret && $googleAuth->verifyCode($admin->google_secret, $code)) {
                    $result = $admin->save(['google_secret' => '']);
                    if ($result === false) {
                        $this->error(__('Unbind failed, please try again'));
                    }
                    Session::set("admin", $admin->toArray());
                    Session::set("admin.safecode", $this->auth->getEncryptSafecode($admin));
                    $this->success(__('Unbind successful'));
                }
                $this->error(__('Verification code error'));
            }
            // 绑定：校验用户 App 中显示的动态码与暂存密钥一致
            $secret = Session::get('admin_ga_new_secret');
            if (!$secret) {
                $this->error(__('Please refresh the page to get the secret key'));
            }
            if ($googleAuth->verifyCode($secret, $code)) {
                $result = $admin->save(['google_secret' => $secret]);
                if ($result === false) {
                    $this->error(__('Bind failed, please try again'));
                }
                Session::delete('admin_ga_new_secret');
                Session::set("admin", $admin->toArray());
                Session::set("admin.safecode", $this->auth->getEncryptSafecode($admin));
                $this->success(__('Bind successful'));
            }
            $this->error(__('Verification code error'));
        }
        $this->error(__('Invalid request'));
    }
}
