<?php

namespace app\admin\model\department;


class AuthAdmin extends \app\admin\model\Admin
{
    // 表名
    protected $name = 'admin';
    /**
     * 关联部门中间表
     * @return \think\model\relation\HasMany
     */
    public function dadmin()
    {
        return $this->hasMany('\app\admin\model\department\Admin', 'admin_id', 'id');
    }

    /**
     * 关联部门表
     * @return \think\model\relation\BelongsToMany
     */
    public function departments()
    {
        return $this->belongsToMany('\app\admin\model\department\Department','DepartmentAdmin','department_id','admin_id');
    }

    /**
     * 关联角色组
     * @return \think\model\relation\HasMany
     */
    public function groups()
    {
        return $this->hasMany('\app\admin\model\department\AuthGroupAccess', 'uid', 'id');
    }

	 /**
     * 登录（公众号）
     * @return string
     */
    public function mpLogin()
    {
        // 获取配置
        $setting=get_addon_config('third');
        if (empty($setting['wechat']['app_id'])||empty($setting['wechat']['app_secret'])){
            $this->error="微信公众号未配置。插件->第三方登录->设置";
            return  false;
        }
        //微信配置
        $config = [
            'app_id'  =>$setting['wechat']['app_id'], // AppID
            'secret'  => $setting['wechat']['app_secret'], // AppSecret
        ];
        $data=array();
        try{
            $app = \EasyWeChat\Factory::officialAccount($config);
            $oauth = $app->oauth;
            $data = $oauth->user()->toArray();
        }catch (\Exception $e){
            $this->error=$e->getMessage();
            return  false;
        }
        return $data;
    }
	
	 /**
     * 第三方登录
     * @param string $platform 平台
     * @param array  $params   参数
     * @param array  $extend   会员扩展信息
     * @param int    $keeptime 有效时长
     * @return Third
     */
    public  function connects($platform, $params = [], $extend = [], $keeptime = 0)
    {

        $time = time();
        $nickname = $params['nickname'] ?? ($params['userinfo']['nickname'] ?? '');
        $avatar = $params['avatar'] ?? ($params['userinfo']['avatar'] ?? '');
        $values = [
            'platform'      => $platform,
            'openid'        => $params['openid'],
            'unionid'        => isset($params['unionid'])?$params['unionid']:'',
            'openname'      => $nickname,
            'access_token'  => $params['access_token'],
            'refresh_token' => $params['refresh_token'],
            'expires_in'    => $params['expires_in'],
            'logintime'     => $time,
            'expiretime'    => $time + $params['expires_in'],
        ];
        $values = array_merge($values, $params);


        //是否有自己的
        $third = \addons\third\model\Third::get(['platform' => $platform, 'openid' => $params['openid']]);

        if (!$third) {
            if (isset($params['unionid']) && !empty($params['unionid'])) {
                //存在unionid就需要判断是否需要生成新记录
                $third = \addons\third\model\Third::get(['platform' => $platform, 'unionid' => $params['unionid']]);
            }
            if (!$third) {
                //不存在就添加第三方登录信息
                return \addons\third\model\Third::create($values, true);
            }
        }
        if ($third){
            $third->logintime=$time;
            $third->refresh_token=$params['refresh_token'];
            $third->isUpdate(true)->save();
        }
        return  $third;




    }

}