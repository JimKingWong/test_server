<?php

namespace app\admin\controller\department;

use app\admin\model\department\AuthAdmin ;

use app\common\controller\Backend;

use addons\third\library\Application;
use addons\third\model\Third as ThirdModel;
use fast\Random;
use think\Session;


/**
 * 微信扫码登录
 * @internal
 */
class Third extends Backend
{
    protected $app = null;
    protected $options = [];
    protected $noNeedRight = ['*'];
    protected $noNeedLogin = ['*'];
    const GET_AUTH_CODE_URL = "https://open.weixin.qq.com/connect/oauth2/authorize";

    public function _initialize()
    {
        parent::_initialize();
        $config = get_addon_config('third');
        $this->app = new Application($config);
    }

    /**
     * 发起登录授权[手机端]
     */
    public function connect()
    {
        $platform = 'wechat';
        $config = get_addon_config('third');
        if (!$config['status']) {
            $this->error("第三方登录已关闭");
        }
        //获取uuid
        $uuid = $this->request->get('uuid');

        if ($uuid) {
            cache($uuid, ['code' => 1, 'msg' => '扫码成功，请在微信上操作登录', 'data' => ''], 120);
            //如果登录了直接返回登录
            if ($this->auth->isLogin()) {
                $this->redirect(url('department/third/confirmlogin', ['uuid' => $uuid]));
            }

        } else {
            $this->error("二维码有误");
        }


        $url = url('department/third/callback', ['uuid' => $uuid], '', true);
        if (!$this->app->{$platform}) {
            $this->error('参数错误');
        }

        if ($url) {
            Session::set("redirecturl", $url);
        }

        $state = md5(uniqid(rand(), true));
        Session::set('state', $state);
        $queryarr = array(
            "appid" => $config['wechat']['app_id'],
            "redirect_uri" => $url,
            "response_type" => "code",
            "scope" => $config['wechat']['scope'],
            "state" => $state,
        );
        request()->isMobile() && $queryarr['display'] = 'mobile';
        $url = self::GET_AUTH_CODE_URL . '?' . http_build_query($queryarr) . '#wechat_redirect';
        // 跳转到登录授权页面
        $this->redirect($url);
        return;
    }

    /**
     * 登录授权回调
     */
    public function callback()
    {

        $platform = 'admin_mp';
        $model = new AuthAdmin();
        $get = $this->request->get();
        $third = get_addon_info('third');
        if (!$third || $third['state'] != 1) {
            $this->error(__("请先安装第三方登录插件"));
        }
        $data = $model->mpLogin($get);
        if (!$data) {
            $this->error($model->getError());
        }
        if (!isset($data['original']['openid'])) {
            $this->error(__("获取微信登录信息失败"));
        }

        $data_param['openid'] = $data['original']['openid'];
        $data_param['unionId'] = isset($data['original']['unionId']) ? $data['original']['unionId'] : '';
        $data_param['expires_in'] = 3600;
        $data_param['userinfo']['nickname'] = $data['nickname'];
        $data_param['userinfo']['avatar'] = $data['avatar'];
        $data_param['access_token'] = $data['access_token'];
        $data_param['refresh_token'] = $data['refresh_token'];

        //判断是否绑定了微信登录
        $thirdModel = new ThirdModel();
        $admin_id = $thirdModel->where("platform", $platform)->value('user_id');
        if (!$admin_id) {
            $this->error(__("你尚未绑定微信登录"));
        }

        //登录系统账号
        $admin = $model->where('id', $admin_id)->find();
        if (!$admin) {
            $this->error(__('登录失败'));
        }
        if ($admin['status'] == 'hidden') {
            $this->error(__('Admin is forbidden'));
        }

        $admin->loginfailure = 0;
        $admin->logintime = time();
        $admin->loginip = request()->ip();
        $admin->token = Random::uuid();
        $admin->save();
        Session::set("admin", $admin->toArray());
        Session::set("admin.safecode", $this->auth->getEncryptSafecode($admin));
        \app\admin\model\AdminLog::record(__('微信扫码登录'));

        //跳转确认登录界面
        $uuid = $this->request->request('uuid');
        $this->redirect(url('department/third/confirmlogin', ['uuid' => $uuid]));


    }


    /**
     * 确认登录操作（手机）
     */
    public function confirmlogin()
    {
        $uuid = $this->request->request('uuid');
        cache($uuid, ['code' => 1, 'msg' => '登录成功', 'data' => ['id' => $this->auth->id, 'username' => $this->auth->username]], 120);
        $this->success("登录成功", 'index/index');

    }

    /**
     * 登录【PC端】
     */
    public function login()
    {
        $uuid = $this->request->request('uuid');
        $result = cache($uuid);
        if (!$result) {
            $this->error("");
        }

        if (isset($result['data']) && isset($result['data']['id']) && $result['data']['id']) {
            $admin_id = $result['data']['id'];
            //登录系统账号
            $adminModel = new AuthAdmin();
            $admin = $adminModel->where('id', $admin_id)->find();
            if (!$admin) {
                $this->error(__('登录失败'));
            }
            if ($admin['status'] == 'hidden') {
                $this->error(__('Admin is forbidden'));
            }

            $admin->loginfailure = 0;
            $admin->logintime = time();
            $admin->loginip = request()->ip();
            $admin->token = Random::uuid();
            $admin->save();
            Session::set("admin", $admin->toArray());
            Session::set("admin.safecode", $this->auth->getEncryptSafecode($admin));

            \app\admin\model\AdminLog::record(__('扫码登录PC'));
            $url = $this->request->get('url', '', 'url_clean');
            $url = $url ?: 'index/index';

            $this->success(__('Login successful'), $url, ['url' => $url, 'id' => $this->auth->id, 'username' => $admin->username, 'avatar' => $admin->avatar]);

        } else if ($result && !isset($result['data']['id'])) {
            $this->success("扫码成功，请在手机上操作");

        }
        $this->error("登录失效", 'index/login', ['token' => $this->request->token()]);
    }


    /**
     * 生成登录二维码
     * @ApiSummary("直接返回图片流")
     * @ApiParams(name="text", type="string", required=true, description="要生成二维码的数据")
     * @ApiBody("返回的二维码数据");
     */
    public function qrcode()
    {
        $uuid = $this->request->request('uuid');
        $text = url("department/third/connect", ['uuid' => $uuid], "", true);
        $qr = \addons\department\library\QRCode::getMinimumQRCode($text);
        $im = $qr->createImage(8, 5);
        imagepng($im);
        $content = ob_get_clean();
        imagedestroy($im);
        return response($content, 200, ['Content-Length' => strlen($content)])->contentType('image/png');


    }


    /**
     * 发起授权【绑定】
     */
    public function bindconnect()
    {

        $config = get_addon_config('third');
        if (!$config['status']) {
            $this->error("第三方登录已关闭");
        }

        //获取Atoken自动登录[绑定]
        $atoken = $this->request->request('ATOKEN');

        if ($atoken) {
            $admin_id = cache($atoken);
            if (!$admin_id) {
                $this->error(__('绑定二维码已过期'));
            }

            $adminModel = new AuthAdmin();
            $admin = $adminModel->where('id', $admin_id)->find();
            if (!$admin) {
                $this->error(__('绑定二维码已过期'));
            }
            if ($admin['status'] == 'hidden') {
                $this->error(__('账号已经被禁止'));
            }


            $platform = 'admin_mp';
            $thirdModel = new \addons\third\model\Third();
            if ($third=$thirdModel->where('platform',$platform)->where('user_id',$admin_id)->find()){
                $this->error(__("当前员工已被绑定"));
            }

            $admin->loginfailure = 0;
            $admin->logintime = time();
            $admin->loginip = request()->ip();
            $admin->token = Random::uuid();
            $admin->save();
            Session::set("admin", $admin->toArray());
            Session::set("admin.safecode", $this->auth->getEncryptSafecode($admin));
            \app\admin\model\AdminLog::record(__('扫码登录绑定'));
            cache($atoken,null);
        } else {
            $this->error(__('绑定码已失效'));
        }


        $url = url('department/third/bindcallback', '', '', true);
        $platform = 'wechat';
        if (!$this->app->{$platform}) {
            $this->error('参数错误');
        }

        if ($url) {
            Session::set("redirecturl", $url);
        }

        $state = md5(uniqid(rand(), true));
        Session::set('state', $state);
        $queryarr = array(
            "appid" => $config['wechat']['app_id'],
            "redirect_uri" => $url,
            "response_type" => "code",
            "scope" => $config['wechat']['scope'],
            "state" => $state,
        );
        request()->isMobile() && $queryarr['display'] = 'mobile';
        $url = self::GET_AUTH_CODE_URL . '?' . http_build_query($queryarr) . '#wechat_redirect';
        // 跳转到登录授权页面
        $this->redirect($url);
        return;
    }


    /**
     * 通知回调【绑定】
     */
    public function bindcallback()
    {

        $platform = 'admin_mp';
        $model = new AuthAdmin();
        $get = $this->request->get();
        $third = get_addon_info('third');
        if (!$third || $third['state'] != 1) {
            $this->error(__("请先安装第三方登录插件"));
        }
        $data = $model->mpLogin();
        if (!$data) {
            $this->error($model->getError());
        }
        if (!isset($data['original']['openid'])) {
            $this->error(__("获取微信登录信息失败"));
        }

        $data_param['openid'] = $data['original']['openid'];
        $data_param['unionId'] = isset($data['original']['unionId']) ? $data['original']['unionId'] : '';
        $data_param['expires_in'] = 3600;
        $data_param['userinfo']['nickname'] = $data['nickname'];
        $data_param['userinfo']['avatar'] = $data['avatar'];
        $data_param['access_token'] = $data['access_token'];
        $data_param['refresh_token'] = $data['refresh_token'];
        $data_param['user_id'] = $this->auth->id;

        $third = $model->connects($platform, $data_param);
        if ($third) {
			
			if ($third->user_id!=$this->auth->id){
				$this->auth->logout();
				$this->error("当前微信已绑定其他账号，请先解绑再来绑定", url('admin/index/index'));
			}
            if (isset($third->user_id) && $third->user_id) {
                $this->success("成功绑定，请关闭当前页面", url('admin/index/index'));
            } else {
                //绑定帐号
                $this->error(__("绑定失败，请重试", url('admin/index/index')));
            }
        }
        $this->error(__("绑定失败，请重试", url('admin/index/index')));


    }


}
