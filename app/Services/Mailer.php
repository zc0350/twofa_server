<?php
declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * 邮件发送服务（零依赖 SMTP 客户端，凭据从 .env 读取）。
 *
 * 必需配置（.env）：SMTP_HOST / SMTP_USER / SMTP_PASS
 * 可选：SMTP_PORT（默认 465）、SMTP_SECURE（ssl 默认 / tls）、
 *       SMTP_FROM（默认同 SMTP_USER）、SMTP_FROM_NAME（默认 "风信密码器"）
 *
 * 未配置 SMTP_HOST 时抛异常，注册接口返回"邮件发送失败"。
 */
final class Mailer
{
    /**
     * @param string $to      收件邮箱
     * @param string $code    6 位验证码
     * @param string $hint    邮件正文场景说明（如"注册云端同步账号"/"找回登录密码"）
     * @param string $subject 邮件主题
     */
    public static function sendVerificationCode(
        string $to,
        string $code,
        string $hint = '注册 风信密码器（风信2FA）的云端同步账号',
        string $subject = '【风信密码器】邮箱验证码'
    ): void {
        $host = (string) \App\Core\Env::get('SMTP_HOST', '');
        if ($host === '') {
            throw new RuntimeException('SMTP 未配置（.env 缺少 SMTP_HOST）');
        }

        $client = new SmtpClient(
            $host,
            (int) \App\Core\Env::get('SMTP_PORT', '465'),
            (string) \App\Core\Env::get('SMTP_SECURE', 'ssl'),
            (string) \App\Core\Env::get('SMTP_USER', ''),
            (string) \App\Core\Env::get('SMTP_PASS', ''),
            15
        );

        $from = (string) \App\Core\Env::get('SMTP_FROM', \App\Core\Env::get('SMTP_USER', ''));
        $fromName = (string) \App\Core\Env::get('SMTP_FROM_NAME', '风信密码器');
        $safeCode = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
        $safeHint = htmlspecialchars($hint, ENT_QUOTES, 'UTF-8');
        $safeSubject = htmlspecialchars($subject, ENT_QUOTES, 'UTF-8');

        $html =
            '<div style="max-width:480px;margin:0 auto;font-family:-apple-system,Segoe UI,PingFang SC,Microsoft YaHei,sans-serif;color:#1f2733;">' .
            '<h2 style="margin:16px 0 8px;">风信密码器</h2>' .
            '<p style="margin:0 0 12px;">您好！</p>' .
            '<p style="margin:0 0 12px;">您正在' . $safeHint . '，本次邮箱验证码为：</p>' .
            '<p style="font-size:30px;font-weight:700;letter-spacing:8px;margin:18px 0;color:#2F6BFF;">' . $safeCode . '</p>' .
            '<p style="margin:0 0 12px;color:#6b7482;font-size:13px;">验证码 10 分钟内有效。若非您本人的操作，请忽略此邮件。</p>' .
            '</div>';
        $text = '您的验证码：' . $code . '（10 分钟内有效）。若非您本人的操作，请忽略此邮件。';

        try {
            $client->send($from, $fromName, $to, $safeSubject, $html, $text);
        } catch (\Throwable $e) {
            $logMsg = '验证码邮件发送失败 [' . $to . ']: ' . $e->getMessage();
            error_log($logMsg);
            // error_log 实际写到哪取决于服务器配置，这里同步落盘 runtime/mail.log 便于排查
            $root = defined('SERVER_ROOT') ? SERVER_ROOT : dirname(__DIR__, 2);
            $logDir = $root . '/runtime';
            if (!is_dir($logDir)) {
                @mkdir($logDir, 0755, true);
            }
            @file_put_contents($logDir . '/mail.log', date('Y-m-d H:i:s') . ' ' . $logMsg . "\n", FILE_APPEND);
            throw $e;
        }
    }
}
