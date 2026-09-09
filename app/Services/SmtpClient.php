<?php
declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * 最小 SMTP 客户端（零依赖）：支持 SSL(465) / STARTTLS(587) / 明文(25)，AUTH LOGIN。
 * 仅实现发送验证码邮件所需的最小命令集。
 */
final class SmtpClient
{
    private string $host;
    private int $port;
    private string $secure;
    private string $username;
    private string $password;
    private int $timeout;
    /** @var resource|null */
    private $socket = null;

    public function __construct(
        string $host,
        int $port,
        string $secure,
        string $username,
        string $password,
        int $timeout = 15
    ) {
        $this->host = $host;
        $this->port = $port;
        $this->secure = strtolower($secure);
        $this->username = $username;
        $this->password = $password;
        $this->timeout = $timeout;
    }

    public function send(string $from, string $fromName, string $to, string $subject, string $html, string $text): void
    {
        $this->connect();
        try {
            $this->expect(220);
            $this->ehlo();
            if ($this->secure === 'tls') {
                $this->command('STARTTLS', 220);
                if (!stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('STARTTLS 协商失败');
                }
                $this->ehlo();
            }
            $this->command('AUTH LOGIN', 334);
            $this->command(base64_encode($this->username), 334);
            $this->command(base64_encode($this->password), 235);
            $this->command('MAIL FROM:<' . $from . '>', 250);
            $this->command('RCPT TO:<' . $to . '>', 250);
            $this->command('DATA', 354);
            $this->command($this->buildMessage($from, $fromName, $to, $subject, $html, $text) . "\r\n.", 250);
            $this->command('QUIT', 221);
        } finally {
            if (is_resource($this->socket)) {
                fclose($this->socket);
                $this->socket = null;
            }
        }
    }

    private function connect(): void
    {
        $transport = $this->secure === 'ssl' ? 'ssl://' : 'tcp://';
        $this->socket = stream_socket_client(
            $transport . $this->host . ':' . $this->port,
            $errno,
            $errstr,
            $this->timeout
        );
        if (!is_resource($this->socket)) {
            throw new RuntimeException('SMTP 连接失败: ' . $errstr);
        }
        stream_set_timeout($this->socket, $this->timeout);
    }

    private function ehlo(): void
    {
        $hostname = $_SERVER['SERVER_NAME'] ?? 'localhost';
        $this->command('EHLO ' . $hostname, 250);
    }

    private function readResponse(): string
    {
        $data = '';
        while (($line = fgets($this->socket, 1024)) !== false) {
            $data .= $line;
            // 多行响应以 "250-" 续行，"250 " 结束
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        return $data;
    }

    private function expect(int $expect): string
    {
        $resp = $this->readResponse();
        $code = (int) substr($resp, 0, 3);
        if ($code !== $expect) {
            throw new RuntimeException('SMTP 响应异常（期望 ' . $expect . '，实际 ' . $code . '）: ' . trim($resp));
        }
        return $resp;
    }

    private function command(string $cmd, int $expect): string
    {
        fwrite($this->socket, $cmd . "\r\n");
        $resp = $this->readResponse();
        $code = (int) substr($resp, 0, 3);
        if ($code !== $expect) {
            throw new RuntimeException('SMTP 命令失败 [' . explode("\r\n", $cmd)[0] . ']: ' . trim($resp));
        }
        return $resp;
    }

    /** RFC 5321 点填充：行首 "." → ".."；统一 CRLF 行尾 */
    private function dotStuff(string $body): string
    {
        $body = str_replace("\r\n", "\n", $body);
        $body = str_replace("\n", "\r\n", $body);
        return preg_replace('/^\\./m', '..', $body) ?? $body;
    }

    private function encodeHeader(string $value): string
    {
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    private function buildMessage(string $from, string $fromName, string $to, string $subject, string $html, string $text): string
    {
        $boundary = 'b_' . bin2hex(random_bytes(12));
        $headers =
            'From: ' . $this->encodeHeader($fromName) . ' <' . $from . ">\r\n" .
            'To: <' . $to . ">\r\n" .
            'Subject: ' . $this->encodeHeader($subject) . "\r\n" .
            'Date: ' . date('r') . "\r\n" .
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . ($_SERVER['SERVER_NAME'] ?? 'localhost') . ">\r\n" .
            "MIME-Version: 1.0\r\n" .
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"' . "\r\n" .
            "\r\n";

        $textPart =
            '--' . $boundary . "\r\n" .
            "Content-Type: text/plain; charset=UTF-8\r\n" .
            "Content-Transfer-Encoding: base64\r\n\r\n" .
            chunk_split(base64_encode($text)) . "\r\n";

        $htmlPart =
            '--' . $boundary . "\r\n" .
            "Content-Type: text/html; charset=UTF-8\r\n" .
            "Content-Transfer-Encoding: base64\r\n\r\n" .
            chunk_split(base64_encode($html)) . "\r\n";

        return $headers . $this->dotStuff($textPart . $htmlPart . '--' . $boundary . '--');
    }
}
