<?php

namespace app\common\service\util;

use think\Config;

/**
 * API 返回数据对称加密工具（AES-256-CBC）
 * 密钥来源：后台"开发设置" api_encrypt_key（application/extra/develop.php）
 * 响应结构：data=base64(密文)，iv=base64(随机向量)，encrypt=1
 * 客户端：用相同 key + iv 做 AES-256-CBC 解密 data
 */
class Encrypt
{
    const METHOD    = 'AES-256-CBC';
    const IV_LENGTH = 16;

    /**
     * 取规范化 32 字节 key（对配置密钥做 sha1 派生，保证长度一致）
     * @return string
     */
    protected static function key()
    {
        $raw = Config::get('develop.api_encrypt_key');
        if (!$raw) {
            return false;
        }
        return substr(sha1($raw, true), 0, 32);
    }

    /**
     * 加密
     * @param string $plaintext 明文（通常是 json 字符串）
     * @return array|false ['data'=>base64密文, 'iv'=>base64向量]，失败返回 false
     */
    public static function encrypt($plaintext)
    {
        $key = self::key();
        if (!$key || $plaintext === '' || $plaintext === null) {
            return false;
        }
        $iv = openssl_random_pseudo_bytes(self::IV_LENGTH);
        $encrypted = openssl_encrypt($plaintext, self::METHOD, $key, OPENSSL_RAW_DATA, $iv);
        if ($encrypted === false) {
            return false;
        }
        return [
            'data' => base64_encode($encrypted),
            'iv'   => base64_encode($iv),
        ];
    }

    /**
     * 解密（服务端内部调试/回调时使用）
     * @param string $data base64 密文
     * @param string $iv   base64 向量
     * @return string|false
     */
    public static function decrypt($data, $iv)
    {
        $key = self::key();
        if (!$key || !$data || !$iv) {
            return false;
        }
        return openssl_decrypt(base64_decode($data), self::METHOD, $key, OPENSSL_RAW_DATA, base64_decode($iv));
    }
}
