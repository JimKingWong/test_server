<?php

namespace app\common\service\util;

/**
 * Google Authenticator 谷歌验证器（TOTP 时间同步一次性密码）
 *
 * 基于 RFC 6238 (TOTP) 与 RFC 4648 (Base32) 实现，
 * 兼容 Google Authenticator、Authy、Microsoft Authenticator 等标准 TOTP 应用。
 *
 * 用法：
 *   $ga = new GoogleAuthenticator();
 *   $secret = $ga->createSecret();                       // 生成 Base32 密钥
 *   $code   = $ga->getCode($secret);                     // 生成当前动态码(自测用)
 *   $ok     = $ga->verifyCode($secret, $inputCode);      // 校验用户输入
 *   $url    = $ga->getQRCodeGoogleUrl('admin', $secret); // otpauth 链接(可用于二维码)
 */
class GoogleAuthenticator
{

    /**
     * 动态码位数
     * @var int
     */
    protected $codeLength = 6;

    /**
     * 时间步长（秒）
     * @var int
     */
    protected $timeStep = 30;

    /**
     * Base32 字符表（RFC 4648，不含 I、L、O、1）
     */
    const BASE32_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * 生成随机 Base32 密钥
     * @param int $secretLength 密钥字符长度，默认16（80bit），推荐 16~32
     * @return string
     * @throws \Exception
     */
    public function createSecret($secretLength = 16)
    {
        $secret = '';
        for ($i = 0; $i < $secretLength; $i++) {
            $secret .= self::BASE32_CHARS[random_int(0, 31)];
        }
        return $secret;
    }

    /**
     * 生成指定时间片的动态码
     * @param string   $secret    Base32 密钥
     * @param int|null $timeSlice 时间片（自 Unix 纪元起每 30 秒为一片），null 表示当前时间
     * @return string 6 位数字动态码
     */
    public function getCode($secret, $timeSlice = null)
    {
        if ($timeSlice === null) {
            $timeSlice = floor(time() / $this->timeStep);
        }
        $secretkey = $this->base32Decode($secret);
        if ($secretkey === '') {
            return str_repeat('0', $this->codeLength);
        }
        // 时间戳按 8 字节大端序编码（高 32 位 + 低 32 位，避免 2038 年溢出）
        $time = pack('N*', ($timeSlice >> 32) & 0xFFFFFFFF, $timeSlice & 0xFFFFFFFF);
        $hash = hash_hmac('sha1', $time, $secretkey, true);
        $offset = ord(substr($hash, -1)) & 0x0F;
        $hashpart = substr($hash, $offset, 4);
        $value = unpack('N', $hashpart)[1] & 0x7FFFFFFF;
        $modulo = pow(10, $this->codeLength);
        return str_pad((string)($value % $modulo), $this->codeLength, '0', STR_PAD_LEFT);
    }

    /**
     * 校验用户输入的动态码
     * @param string $secret      Base32 密钥
     * @param string $code        用户输入的动态码
     * @param int    $discrepancy 允许的时间偏移窗口（前后各 N 个时间片）
     * @return bool
     */
    public function verifyCode($secret, $code, $discrepancy = 1)
    {
        $code = trim((string)$code);
        if ($code === '' || !preg_match('/^\d{' . $this->codeLength . '}$/', $code)) {
            return false;
        }
        $currentTimeSlice = floor(time() / $this->timeStep);
        for ($i = -$discrepancy; $i <= $discrepancy; $i++) {
            $calculatedCode = $this->getCode($secret, $currentTimeSlice + $i);
            if ($this->timingSafeEquals($calculatedCode, $code)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 生成 otpauth:// 链接（可用于生成二维码供 Authenticator 扫描）
     * @param string $name   账号名称（如管理员用户名）
     * @param string $secret Base32 密钥
     * @param string $issuer 签发者名称
     * @return string
     */
    public function getQRCodeGoogleUrl($name, $secret, $issuer = 'FastAdmin')
    {
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $name)
            . '?secret=' . rawurlencode($secret)
            . '&issuer=' . rawurlencode($issuer);
    }

    /**
     * Base32 解码（RFC 4648）
     * @param string $secret
     * @return string 二进制密钥
     */
    public function base32Decode($secret)
    {
        $secret = strtoupper((string)$secret);
        $secret = preg_replace('/[^A-Z2-7]/', '', $secret);
        if ($secret === '') {
            return '';
        }
        $buffer = 0;
        $bitsLeft = 0;
        $result = '';
        $len = strlen($secret);
        for ($i = 0; $i < $len; $i++) {
            $value = strpos(self::BASE32_CHARS, $secret[$i]);
            if ($value === false) {
                continue;
            }
            $buffer = ($buffer << 5) | $value;
            $bitsLeft += 5;
            if ($bitsLeft >= 8) {
                $result .= chr(($buffer >> ($bitsLeft - 8)) & 0xFF);
                $bitsLeft -= 8;
            }
        }
        return $result;
    }

    /**
     * Base32 编码（RFC 4648）
     * @param string $data 二进制数据
     * @return string
     */
    public function base32Encode($data)
    {
        if ($data === '') {
            return '';
        }
        $result = '';
        $buffer = 0;
        $bitsLeft = 0;
        $len = strlen($data);
        for ($i = 0; $i < $len; $i++) {
            $buffer = ($buffer << 8) | ord($data[$i]);
            $bitsLeft += 8;
            while ($bitsLeft >= 5) {
                $result .= self::BASE32_CHARS[($buffer >> ($bitsLeft - 5)) & 0x1F];
                $bitsLeft -= 5;
            }
        }
        if ($bitsLeft > 0) {
            $result .= self::BASE32_CHARS[($buffer << (5 - $bitsLeft)) & 0x1F];
        }
        return $result;
    }

    /**
     * 常量时间字符串比较，防止时序攻击
     * @param string $safe
     * @param string $user
     * @return bool
     */
    protected function timingSafeEquals($safe, $user)
    {
        if (function_exists('hash_equals')) {
            return hash_equals($safe, $user);
        }
        $safeLen = strlen($safe);
        $userLen = strlen($user);
        if ($safeLen !== $userLen) {
            return false;
        }
        $result = 0;
        for ($i = 0; $i < $safeLen; $i++) {
            $result |= (ord($safe[$i]) ^ ord($user[$i]));
        }
        return $result === 0;
    }
}
