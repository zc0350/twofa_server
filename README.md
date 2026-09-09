# 风信密码器 · 服务端（原生 PHP + MySQL，零框架依赖）

两步验证（TOTP）应用「风信密码器」的端到端加密同步后端。
**服务器对数据内容与同步密码均零知识**：

- **登录密码**仅用于身份认证（bcrypt 存储），签发访问令牌；
- **同步密码**永不上传——客户端本地经 PBKDF2 派生 AES-256-GCM 密钥加密账户集合，
  KDF 盐由客户端生成并随密文保存（非机密）；
- 新设备验证同步密码的方式是"解密成功即密码正确"（GCM 认证标签），服务器无需也无法校验；
- 同步密码遗失则云端密文永久不可解密（产品语义：无法恢复）。

## 对外入口（宣传页与分发）

部署后访问站点根路径即项目宣传页（`IndexController@index`）：

| 路径 | 说明 |
|---|---|
| `GET /` | 项目宣传落地页：特性介绍、安装指南、APK 下载（自动列出 downloads/ 下全部版本）、服务端源码下载入口 |
| `GET /downloads/*` | APK 静态文件（`public/downloads/`） |
| `GET /source` | 服务端源码实时打包下载（zip，自动排除 `.env`、`runtime/`、`downloads/`，附部署说明） |
| `/v1/*` | 同步 API（见下） |

**发布新版 APK**：把构建产物复制进 `server/public/downloads/` 即可，页面按文件名中的
`v<主>.<次>.<修订>` 自动解析版本并置顶展示，无需改代码：

```bash
cp out/Fengxin2FA-v1.9.7.apk server/public/downloads/
```

## 架构（自研轻量 MVC，零 Composer 依赖）

```
server/
├── public/                   # 生产文档根（nginx/Apache 指向此处）
│   ├── index.php             # 前端控制器（路由分发 + CORS + 自动加载）
│   ├── downloads/            # APK 安装包（页面自动列出版本）
│   └── favicon.png
├── router.php                # PHP 内置服务器路由脚本（开发调试）
├── app/
│   ├── Core/
│   │   ├── Env.php           # .env 加载器
│   │   ├── Database.php      # PDO 单例（MySQL）
│   │   ├── Cache.php         # 文件缓存（限流/待验证注册/冷却计数）
│   │   ├── Request.php       # 请求封装（JSON body / header / IP）
│   │   └── Tokens.php        # Bearer 令牌签发与校验
│   ├── Controllers/
│   │   ├── Controller.php    # 基类（鉴权 + JSON 失败响应）
│   │   ├── IndexController.php # 宣传页 + /source 源码打包
│   │   ├── AuthController.php
│   │   └── VaultController.php
│   ├── Views/
│   │   └── home.php          # 宣传落地页模板（无外部依赖）
│   └── Services/
│       ├── Mailer.php        # 验证码邮件（HTML + 纯文本双格式）
│       └── SmtpClient.php    # 最小 SMTP 客户端（SSL/STARTTLS/AUTH LOGIN）
├── sql/init.sql              # 建表脚本
├── runtime/cache/            # 文件缓存数据（自动创建，勿公开访问）
├── .env                      # 环境配置（不入库、不打包）
└── .example.env              # 环境配置模板
```

## 接口一览

| 方法 | 路径 | 说明 |
|---|---|---|
| POST | `/v1/auth/register` | `{email, password}`（登录密码）→ 发送验证码邮件，返回 `{pending: true, expiresIn}`（账号暂存 Cache，未入库） |
| POST | `/v1/auth/verify` | `{email, code}` → 验证通过后账号写入数据库并签发令牌：`{userId, token, version}` |
| POST | `/v1/auth/login` | `{email, password}` → `{userId, token, version}`（含云端当前 version） |
| GET  | `/v1/vault` | Header `Authorization: Bearer <token>` → `{version, cipher, kdfSalt}` |
| POST | `/v1/vault` | `{baseVersion, cipher, kdfSalt, deviceId}` → `{version}`；冲突 409 |
| GET  | `/v1/vault/history` | 历史版本列表；`?version=N` 取单个归档密文 |

错误统一为 `{"error": "..."}` + 对应 HTTP 状态码（401/409/410/422/429/500）。

### 注册防刷（两步邮箱验证，未验证不入库）

- 单 IP 每日发码 20 次；单邮箱每日发码 10 次；同邮箱发码冷却 60 秒；
- 验证码 6 位数字、10 分钟有效、最多 5 次尝试（错误后提示剩余次数）；
- 未验证邮箱前账号只存文件缓存（`reg_pending_*`），验证通过才写入 `users` 表。

### 同步防刷

- 推送频率限制：60 次/分钟/用户（文件缓存计数）。

## 部署

### 本地开发

```bash
cd server
php -S 0.0.0.0:8000 router.php
# 访问 http://localhost:8000       → 项目宣传页
# 访问 http://localhost:8000/v1/*   → API
# 访问 http://localhost:8000/source → 源码包下载
```

### 生产部署（nginx，文档根指向 public/）

```nginx
server {
    listen 443 ssl;
    server_name twofa.fxgx.cn;

    root /var/www/twofa/server/public;   # 前端文档根
    index index.php;

    # 动态入口（宣传页 / 源码下载 / API）
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

> 文档根锁定在 `public/` 后，`app/`、`sql/`、`.env` 等内部文件天然不可访问；
> `public/downloads/` 内的 APK 直接静态下载，无需额外配置。

### 原生 APK 构建

```bash
cd android
gradlew assembleRelease
# 产物：android/app/build/outputs/apk/release/Fengxin2FA-v<version>.apk
# 发布：cp <产物> server/public/downloads/
```

### 初始化数据库

```bash
mysql -u root -p < sql/init.sql
```

> 全新部署只需执行 `init.sql`。

### 配置环境

```bash
cp .example.env .env
```

编辑 `.env` 填写两类凭据：

- **数据库**：`DB_HOST / DB_NAME / DB_USER / DB_PASS`；
- **SMTP 邮箱**（注册验证码发送，必填）：`SMTP_HOST`（如 smtp.qq.com）、`SMTP_PORT`（SSL 465 / TLS 587）、`SMTP_SECURE`（ssl/tls）、`SMTP_USER`、`SMTP_PASS`（QQ 邮箱填 16 位授权码而非登录密码）、`SMTP_FROM`、`SMTP_FROM_NAME`。

生产环境务必设置 `APP_DEBUG=false`，并使用 HTTPS 域名。

## 常见 SMTP 配置参考

| 服务商 | SMTP_HOST | 端口 | 加密 | 密码说明 |
|---|---|---|---|---|
| QQ 邮箱 | smtp.qq.com | 465 | ssl | 设置→账户→开启 SMTP→生成 16 位授权码 |
| 腾讯企业邮 | smtp.exmail.qq.com | 465 | ssl | 登录密码或授权码 |
| 网易 163 | smtp.163.com | 465 | ssl | 客户端授权密码 |
| 阿里企业邮 | smtp.qiye.aliyun.com | 465 | ssl | 登录密码 |

## 客户端交互流程（双密码）

1. **注册（两步）**：邮箱 + 登录密码 → 收验证码邮件 → 输入验证码完成注册获得令牌；
2. **开启同步 / 解锁**：设置同步密码（两次一致）→ 本机生成 KDF 盐 → 加密上传；新设备输入同一同步密码解锁云端数据；
3. **修改同步密码**：旧密码解密验证 → 新盐重新加密 → 整体推送；其他设备需用新密码重新解锁；
4. 解锁前的本地变更会在解锁后的首次同步中一并上传；离线操作不受任何影响。

## 代价与边界（须知）

- 用户忘记同步密码 → 云端密文永久不可解密（端到端加密的属性，产品语义为"无法恢复"）；
- 「整体密文 + 客户端合并」模型的流量为 O(账户集合大小)；100 个账户以内密文约几十 KB；
- 令牌 30 天有效，过期后需重新登录；登录时自动清理过期令牌；
- 同步密码在本机保存（应用私有加密存储），用于自动后台同步；设备丢失场景由应用锁保护。