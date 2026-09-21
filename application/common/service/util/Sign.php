<?php

namespace app\common\service\util;

use think\Cache;
use think\Config;

/**
 * API 请求验签服务类
 *
 * 验签规则：
 * 1. 请求需携带 timestamp（Unix 时间戳）、nonce（随机串）、sign（签名）
 * 2. 参与签名的参数：除 sign、sign_type 外的全部请求参数（空值参数不参与）
 * 3. 参数按 key 升序排序，拼接为 k1=v1&k2=v2&...&key=密钥，取 md5 作为签名
 * 4. timestamp 与服务器时间误差在 expire 秒内有效，防重放
 * 5. nonce 在有效期内不可重复使用（缓存记录），防重放
 *
 * 使用示例：
 * $params = ['timestamp' => time(), 'nonce' => uniqid()];
 * $params['sign'] = \app\common\service\util\Sign::makeSign($params, $secret);
 */
class Sign
{

    /**
     * 验签密钥
     * @var string
     */
    protected $secret = '';

    /**
     * 时间戳允许误差（秒）
     * @var int
     */
    protected $expire = 300;

    /**
     * nonce 防重放缓存时长（秒）
     * @var int
     */
    protected $nonceExpire = 600;

    public function __construct($secret = '', $expire = 0)
    {
        if ($secret !== '') {
            $this->secret = $secret;
        } else {
            $this->secret = (string)Config::get('develop.api_sign_secret');
        }
        if ($expire > 0) {
            $this->expire = $expire;
        } else {
            $this->expire = (int)Config::get('develop.api_sign_expire') ?: 300;
        }
    }

    /**
     * 校验请求签名
     * @param array $params 全部请求参数（含 timestamp/nonce/sign）
     * @return bool|string true 通过；字符串为失败原因
     */
    public function check(array $params)
    {
        if (!isset($params['timestamp']) || !isset($params['nonce']) || !isset($params['sign'])) {
            return '缺少签名参数(timestamp/nonce/sign)';
        }
        $timestamp = $params['timestamp'];
        $nonce = $params['nonce'];
        $sign = $params['sign'];

        if (!is_numeric($timestamp)) {
            return 'timestamp格式错误';
        }
        if (abs(time() - (int)$timestamp) > $this->expire) {
            return '请求已过期';
        }
        if (!preg_match('/^[a-zA-Z0-9]{8,32}$/', (string)$nonce)) {
            return 'nonce格式错误';
        }
        $cacheKey = 'api_nonce_' . md5($nonce);
        if (Cache::get($cacheKey)) {
            return 'nonce已被使用';
        }
        $expect = static::makeSign($params, $this->secret);
        if (!hash_equals($expect, strtolower((string)$sign))) {
            return '签名错误';
        }
        // 记录 nonce，防重放
        Cache::set($cacheKey, 1, $this->nonceExpire);
        return true;
    }

    /**
     * 生成签名
     * @param array  $params 请求参数（自动排除 sign/sign_type 与空值）
     * @param string $secret 验签密钥
     * @return string
     */
    public static function makeSign(array $params, $secret)
    {
        ksort($params);
        $str = '';
        foreach ($params as $k => $v) {
            if ($k === 'sign' || $k === 'sign_type' || $v === '' || $v === null) {
                continue;
            }
            if (is_array($v)) {
                $v = json_encode($v, JSON_UNESCAPED_UNICODE);
            }
            $str .= $k . '=' . $v . '&';
        }
        $str .= 'key=' . $secret;
        return md5($str);
    }

    /**
     * 便捷方法：根据请求参数直接校验
     * @param array $params
     * @return bool|string
     */
    public static function verify(array $params)
    {
        $sign = new static();
        return $sign->check($params);
    }
}
