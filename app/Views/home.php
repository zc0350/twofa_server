<?php
/**
 * 风信密码器 · 项目宣传落地页模板
 * 数据由 IndexController@index 注入：$downloads / $apkCount / $sourceUrl / $genAt
 * 纯 HTML+CSS，无任何外部依赖（字体/图标/脚本均为零）。
 */
declare(strict_types=1);

$latest = $downloads[0] ?? null;
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>风信密码器 · 两步验证 &amp; 端到端加密云同步</title>
<meta name="description" content="风信密码器：Android 两步验证（TOTP）专用应用，扫码添加、离线取码，端到端加密云同步，PIN 与生物识别双重守护。开源服务端，可私有化部署。">
<meta name="theme-color" content="#0b1020">
<link rel="icon" href="/favicon.png" type="image/png">
<style>
  :root {
    --bg: #0b1020;
    --bg-soft: #111731;
    --card: #17203f;
    --line: rgba(255, 255, 255, .09);
    --text: #eef1f8;
    --text-2: #a7b0c6;
    --accent: #3d7bff;
    --accent-2: #6ee7ff;
    --ok: #34d399;
  }
  * { box-sizing: border-box; margin: 0; padding: 0; }
  html { scroll-behavior: smooth; }
  body {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang SC",
                 "Hiragino Sans GB", "Microsoft YaHei", sans-serif;
    background: radial-gradient(1200px 600px at 70% -10%, #1b2a55 0%, var(--bg) 55%) var(--bg);
    color: var(--text);
    line-height: 1.65;
    -webkit-font-smoothing: antialiased;
  }
  .wrap { max-width: 1040px; margin: 0 auto; padding: 0 22px; }

  /* ---- 顶栏 ---- */
  header.nav {
    position: sticky; top: 0; z-index: 10;
    background: rgba(11, 16, 32, .82);
    backdrop-filter: blur(10px);
    border-bottom: 1px solid var(--line);
  }
  .nav-inner { display: flex; align-items: center; gap: 14px; height: 60px; }
  .nav-logo { display: flex; align-items: center; gap: 10px; font-weight: 700; font-size: 17px; }
  .nav-logo img { width: 30px; height: 30px; border-radius: 8px; }
  .nav-links { margin-left: auto; display: flex; gap: 22px; font-size: 14px; }
  .nav-links a { color: var(--text-2); text-decoration: none; padding: 4px 2px; }
  .nav-links a:hover { color: var(--text); }
  /* 锚点目标避开 60px 吸顶导航（滚动后标题不被盖住） */
  section[id] { scroll-margin-top: 74px; }

  /* ---- Hero ---- */
  .hero { padding: 76px 0 64px; text-align: center; }
  .hero .badge {
    display: inline-flex; align-items: center; gap: 8px;
    font-size: 13px; color: var(--ok);
    border: 1px solid rgba(45, 211, 153, .35);
    background: rgba(45, 211, 153, .08);
    padding: 5px 14px; border-radius: 999px; margin-bottom: 22px;
  }
  .hero h1 { font-size: clamp(30px, 5vw, 46px); font-weight: 800; letter-spacing: .5px; }
  .hero h1 .accent {
    background: linear-gradient(90deg, var(--accent), var(--accent-2));
    -webkit-background-clip: text; background-clip: text; color: transparent;
  }
  .hero .sub { margin: 18px auto 0; max-width: 640px; color: var(--text-2); font-size: 16.5px; }
  .hero-btns { margin-top: 34px; display: flex; gap: 14px; justify-content: center; flex-wrap: wrap; }
  .btn {
    display: inline-flex; align-items: center; gap: 9px;
    padding: 13px 26px; border-radius: 12px; text-decoration: none;
    font-size: 15.5px; font-weight: 600; transition: transform .15s, box-shadow .15s;
  }
  .btn:hover { transform: translateY(-2px); }
  .btn-primary { background: linear-gradient(135deg, var(--accent), #2f63e0); color: #fff; box-shadow: 0 10px 26px rgba(61, 123, 255, .35); }
  .btn-ghost { border: 1px solid rgba(255, 255, 255, .18); color: var(--text); background: rgba(255, 255, 255, .05); }
  .hero-meta { margin-top: 16px; font-size: 13px; color: var(--text-2); }
  .hero-meta b { color: var(--text); }

  /* ---- 区块标题 ---- */
  section.page { padding: 54px 0; }
  h2.title { font-size: 25px; font-weight: 800; margin-bottom: 8px; }
  p.lead { color: var(--text-2); margin-bottom: 30px; max-width: 720px; }

  /* ---- 特性卡片 ---- */
  .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px; }
  .card {
    background: var(--card); border: 1px solid var(--line);
    border-radius: 16px; padding: 24px;
    transition: border-color .15s, transform .15s;
  }
  .card:hover { border-color: rgba(61, 123, 255, .45); transform: translateY(-2px); }
  .card .ico { font-size: 22px; }
  .card h3 { font-size: 16.5px; margin: 12px 0 8px; }
  .card p { font-size: 14px; color: var(--text-2); }

  /* ---- 安装步骤 ---- */
  .steps { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; counter-reset: step; }
  .step { position: relative; background: var(--bg-2); border: 1px solid var(--line); border-radius: 16px; padding: 22px 22px 22px 58px; }
  .step::before {
    counter-increment: step; content: counter(step);
    position: absolute; left: 18px; top: 22px;
    width: 28px; height: 28px; border-radius: 50%;
    background: linear-gradient(135deg, var(--accent), var(--accent-2));
    color: #fff; font-weight: 700; font-size: 15px;
    display: flex; align-items: center; justify-content: center;
  }
  .step h4 { font-size: 15px; margin-bottom: 6px; }
  .step p { font-size: 13.5px; color: var(--text-2); }
  code.kbd {
    font-family: ui-monospace, Consolas, monospace; font-size: 12.5px;
    background: rgba(255, 255, 255, .08); border: 1px solid var(--line);
    padding: 1px 7px; border-radius: 6px; color: var(--accent-2);
  }

  /* ---- 版本历史表格 ---- */
  .table { width: 100%; border-collapse: collapse; font-size: 14px; }
  .table th, .table td { text-align: left; padding: 11px 14px; border-bottom: 1px solid var(--line); }
  .table th { color: var(--text-2); font-weight: 600; font-size: 12.5px; text-transform: uppercase; letter-spacing: .4px; }
  .table td a { color: var(--accent-2); text-decoration: none; font-weight: 600; }
  .table tr:last-child td { border-bottom: none; }
  .tag-latest {
    font-size: 11.5px; color: #052e16; background: var(--ok);
    padding: 2px 8px; border-radius: 999px; font-weight: 700; margin-left: 8px;
  }

  /* ---- 源码区 ---- */
  .source {
    display: flex; align-items: center; gap: 18px; flex-wrap: wrap;
    background: linear-gradient(120deg, var(--bg-2), var(--card));
    border: 1px solid var(--line); border-radius: 16px; padding: 24px;
  }
  .source .txt { flex: 1 1 320px; }
  .source .txt h3 { font-size: 17px; margin-bottom: 6px; }
  .source .txt p { font-size: 13.5px; color: var(--text-2); }
  .source pre {
    margin-top: 12px; font-size: 12.5px; color: var(--text-2);
    background: rgba(0, 0, 0, .25); border: 1px solid var(--line);
    padding: 10px 14px; border-radius: 10px; overflow-x: auto;
  }

  footer {
    border-top: 1px solid var(--line); margin-top: 30px; padding: 26px 0 40px;
    color: var(--text-2); font-size: 13px;
  }
  footer .wrap { display: flex; justify-content: space-between; gap: 12px; flex-wrap: wrap; }

  @media (max-width: 700px) {
    .nav-logo .en { display: none; }
    /* 窄屏导航保持可见可点，紧凑排列并允许换行（移除后旧机型/嵌 WebView 不致丢失入口） */
    .nav-links { display: flex; gap: 14px; font-size: 13.5px; }
    .hero { padding: 56px 0 44px; }
    .hero-btns { flex-direction: column; align-items: stretch; }
    .btn { justify-content: center; }
  }
</style>
</head>
<body>

<header class="nav">
  <div class="wrap nav-inner">
    <div class="nav-logo">
      <img src="favicon.png" alt="风信密码器图标">
      风信密码器 <span class="en" style="color:var(--text-2);font-weight:500">Fengxin 2FA</span>
    </div>
    <nav class="nav-links">
      <a href="#features">特性</a>
      <a href="#install">安装</a>
      <a href="#versions">版本</a>
      <a href="#source">服务端源码</a>
    </nav>
  </div>
</header>

<!-- Hero -->
<section class="hero">
  <div class="wrap">
    <div class="badge">🔐 端到端加密 · 服务器零知识</div>
    <h1>两步验证，<span class="accent">安心随身</span></h1>
    <p class="sub">
      风信密码器是一款 Android 原生两步验证（TOTP）应用：扫码绑定、离线取码，
      验证码数据经客户端点对点加密后再上云，多设备安全同步，亦可完全离线使用。
    </p>
    <div class="hero-btns">
      <?php if ($latest): ?>
        <a class="btn btn-primary" href="<?= htmlspecialchars($latest['href']) ?>" download>⬇ 下载 APK v<?= htmlspecialchars($latest['version']) ?>（<?= htmlspecialchars($latest['sizeMb']) ?>）</a>
      <?php else: ?>
        <a class="btn btn-primary" href="#" onclick="return false">APK 即将发布</a>
      <?php endif; ?>
      <a class="btn btn-ghost" href="<?= htmlspecialchars($sourceUrl) ?>" target="_blank">⌥ 服务端源码（Github）</a>
    </div>
    <div class="hero-meta">
      最新版本 <b>v<?= $latest ? htmlspecialchars($latest['version']) : '—' ?></b>
      · 已收录 <?= (int) $apkCount ?> 个版本
      · 页面更新于 <?= htmlspecialchars($genAt) ?>
    </div>
  </div>
</section>

<!-- 特性 -->
<section class="page" id="features">
  <div class="wrap">
    <h2 class="title">为什么选择风信密码器</h2>
    <p class="lead">小而克制，只做好一件事：把认证码这件事做得安全、可靠、不打扰。</p>
    <div class="grid">
      <div class="card"><div class="ico">⚡</div><h3>离线取码，零网络依赖</h3><p>TOTP 算法完全在本机运行，飞行模式也能正常出码；云同步只是可选项，数据主权始终在设备上。</p></div>
      <div class="card"><div class="ico">🔐</div><h3>端到端加密云同步</h3><p>客户端 AES-256-GCM 加密后再上传，服务器只存密文与版本号，无法读取任何账号内容——零知识架构。</p></div>
      <div class="card"><div class="ico">📷</div><h3>扫码即绑，无需 GMS</h3><p>内置离线条码识别引擎，不依赖 Google Play 服务，华为等国产设备开箱即用。</p></div>
      <div class="card"><div class="ico">🛡</div><h3>PIN · 生物识别双重守护</h3><p>离开应用自动锁定，支持 4-8 位 PIN 与指纹/人脸解锁；长时间闲置自动重新加锁，防窥防误触。</p></div>
      <div class="card"><div class="ico">🔄</div><h3>版本历史可回滚</h3><p>每次同步均保存版本快照（保留最近 10 版 / 30 天），误改误删可从历史版本一键恢复，数据多一重安全网。</p></div>
      <div class="card"><div class="ico">🧩</div><h3>迁移自由，随时带走</h3><p>支持加密导出 / 明文导出 / 批量导入，换机迁移完整一致，不绑定任何厂商生态。</p></div>
    </div>
  </div>
</section>

<!-- 安装 -->
<section class="page" id="install">
  <div class="wrap">
    <h2 class="title">安装与使用</h2>
    <p class="lead">三步完成入门，全程可离线。</p>
    <div class="steps">
      <div class="step"><h4>下载 APK</h4><p>在上方点击下载按钮获取最新安装包（约 6 MB，兼容 Android 8.0+）。</p></div>
      <div class="step"><h4>允许安装未知来源应用</h4><p>安装时系统会提示未知来源，选择「继续安装」即可（仅限本安装包）。</p></div>
      <div class="step"><h4>扫码或手动添加账号</h4><p>选择「扫码添加」对准服务商提供的二维码，或手动输入秘钥（Secret）。</p></div>
      <div class="step"><h4>开启同步（可选）</h4><p>注册账号后开启云端同步，多设备自动合并；也可保持完全离线。「极简模式」</p></div>
    </div>
  </div>
</section>

<!-- 版本历史 -->
<section class="page" id="versions">
  <div class="wrap">
    <h2 class="title">下载与版本</h2>
    <p class="lead">安装包直接托管于本服务器，历经版本全部保留，可按需回退。</p>
    <div class="table-wrap"><table class="table">
      <thead><tr><th>版本</th><th>大小</th><th>发布日期</th><th>下载</th></tr></thead>
      <tbody>
      <?php foreach ($downloads as $i => $item): ?>
        <tr>
          <td>v<?= htmlspecialchars($item['version']) ?><?= $i === 0 ? '<span class="tag-latest">最新</span>' : '' ?></td>
          <td><?= htmlspecialchars($item['sizeMb']) ?></td>
          <td><?= htmlspecialchars($item['date']) ?></td>
          <td><a href="<?= htmlspecialchars($item['href']) ?>" download>下载</a></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$downloads): ?>
        <tr><td colspan="4" style="color:var(--text-2)">安装包整理中，敬请期待</td></tr>
      <?php endif; ?>
      </tbody>
    </table></div>
  </div>
</section>

<!-- 服务端源码 -->
<section class="page" id="source">
  <div class="wrap">
    <h2 class="title">服务端 · 开源可自部署</h2>
    <p class="lead">同步服务端为原生 PHP 零依赖 MVC，接口公开、模型清晰，任何人均可下载源码审计或私有化部署。</p>
    <div class="source">
      <div class="txt">
        <h3>fengxin-2fa-server</h3>
        <p>PHP 8.1+ · MySQL · 约 2 千行 · 无框架、无 Composer 依赖。打包即所得，不含任何敏感信息。</p>
        <pre># 本地运行
php -S 0.0.0.0:8000 router.php
# 接口一览
GET  /v1/vault         读取最新密文快照
POST /v1/vault         乐观并发写入（版本号递增）
GET  /v1/vault/history 历史归档（10 版 / 30 天）</pre>
      </div>
      <a class="btn btn-ghost" href="<?= htmlspecialchars($sourceUrl) ?>" TARGET="_blank">⬇ 下载服务端源码 GitHub</a>
    </div>
  </div>
</section>

<footer>
  <div class="wrap">
    <span>风信密码器 Fengxin 2FA · 两步验证 + 端到端加密同步</span>
    <span>© <?= date('Y') ?> · 保留所有权利 · 页面更新 <?= htmlspecialchars($genAt) ?></span>
  </div>
</footer>

<script>
  // 导航锚点兜底：不依赖浏览器对 #片段跳转的原生处理（部分内嵌 WebView / 代理会吞掉），
  // 统一改为 scrollIntoView 平滑滚动；目标段已用 scroll-margin-top 避开吸顶导航。
  (function () {
    var links = document.querySelectorAll('.nav-links a[href^="#"]');
    for (var i = 0; i < links.length; i++) {
      links[i].addEventListener('click', function (e) {
        var id = this.getAttribute('href').slice(1);
        var el = id && document.getElementById(id);
        if (!el) return; // 目标不存在时保持默认行为
        e.preventDefault();
        el.scrollIntoView({ behavior: 'smooth', block: 'start' });
      });
    }
  })();
</script>

</body>
</html>