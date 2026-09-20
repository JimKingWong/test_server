<?php

namespace app\common\service;

use app\common\controller\Api;

/**
 * Service 基类
 *
 * MVCS 分层中的业务逻辑层（Service）基类，项目分层对应关系：
 *   M - Model      application/common/model       数据层（数据表模型）
 *   V - View       application/{admin,index}/view 视图层（模板）
 *   C - Controller application/{admin,index,api}/controller  控制层（请求入口、参数校验、响应）
 *   S - Service    application/common/service     业务逻辑层（本目录，公共业务集中于此）
 *
 * 继承 app\common\controller\Api，因此 Service 内可直接使用：
 *   $this->request 请求对象    $this->auth 权限Auth
 *   $this->success() / $this->error()  API响应（内部抛 HttpResponseException 终止流程）
 *   $this->validate() 数据验证   $this->token() 令牌验证
 *
 * 注意：
 *   1. 本基类默认设置 noNeedLogin = ['*']，实例化时不会强制登录校验；
 *      如需判断登录态，在业务内使用 $this->auth->isLogin()。
 *   2. success()/error() 会直接输出响应并中断执行，适用于 API 场景；
 *      若 Service 被非请求上下文（CLI/队列/定时任务）复用，请勿调用。
 *
 * 业务 Service 用法示例：
 *   class UserService extends Base
 *   {
 *       protected $model = 'app\common\model\User';
 *
 *       public function getUserInfo($id)
 *       {
 *           return $this->transaction(function () use ($id) {
 *               return $this->getModel()->get($id);
 *           });
 *       }
 *   }
 */
class Base extends Api
{

    /**
     * Service 层默认不做登录拦截，由调用方（控制器/请求上下文）负责鉴权
     * @var array
     */
    protected $noNeedLogin = ['*'];

    /**
     * 业务对应的模型类名，子类覆盖；留空则按类名自动推断
     * 例：UserService => app\common\model\User
     * @var string
     */
    protected $model = '';

    /**
     * 模型实例缓存
     * @var \think\Model|null
     */
    protected $modelInstance = null;

    /**
     * 获取业务模型实例（不存在则自动实例化并缓存）
     * @return \think\Model
     * @throws \think\Exception
     */
    public function getModel()
    {
        if ($this->modelInstance instanceof \think\Model) {
            return $this->modelInstance;
        }
        $modelClass = $this->model;
        if (!$modelClass) {
            $shortName = (new \ReflectionClass($this))->getShortName();
            $shortName = preg_replace('/Service$/', '', $shortName);
            $modelClass = 'app\\common\\model\\' . $shortName;
        }
        if (!class_exists($modelClass)) {
            throw new \think\Exception('Service 对应的模型不存在: ' . $modelClass);
        }
        $this->modelInstance = new $modelClass();
        return $this->modelInstance;
    }

    /**
     * 事务封装：闭包内执行业务，抛出异常自动回滚
     * @param \Closure $closure 回调参数为当前 Service 实例
     * @return mixed
     * @throws \Exception
     * @throws \Throwable
     */
    public function transaction(\Closure $closure)
    {
        \think\Db::startTrans();
        try {
            $result = $closure($this);
            \think\Db::commit();
            return $result;
        } catch (\Exception $e) {
            \think\Db::rollback();
            throw $e;
        } catch (\Throwable $e) {
            \think\Db::rollback();
            throw $e;
        }
    }

    /**
     * 魔术方法：将 Service 上未定义的方法调用转发到模型实例
     * 例：$service->where('id', 1)->find();
     * @param string $name
     * @param array $arguments
     * @return mixed
     */
    public function __call($name, $arguments)
    {
        return call_user_func_array([$this->getModel(), $name], $arguments);
    }
}
