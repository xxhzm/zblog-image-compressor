# 新图片自动压缩 1.0.1

作者：[xiaoxiao](https://xxapi.cn)

适配：Z-BlogPHP 1.7.5.3540（Optimus），PHP 8.2+。使用官方上传钩子，不修改核心/UEditor 文件。

## 功能
- 仅处理启用后通过 Z-Blog Upload 类新上传的 JPEG、PNG、WebP；不扫描旧文章，不批量改图
- 默认最长边 1600 像素、质量 82、优先 WebP。只能缩小，不能放大
- WebP 编码/解码与站点允许列表均支持时才转换；否则保留原格式。PNG 原格式不做有损调色板量化
- 只有重新编码校验成功且至少节省 1% 或 256 字节时采用结果
- 每张成功优化图片生成唯一的新文件名，扩展名、真实 MIME、附件数据库大小和 UEditor 响应保持一致
- JPEG 先修正 EXIF 方向。保留透明背景；跳过 GIF、APNG、动画 WebP、ICC 配置图片，以及无法安全处理的 EXIF 情况
- 全程在本站 PHP/GD 中处理，无第三方 API、外部上传或 shell 命令

## 安装与验证
1. 先备份站点。取得站点管理员授权后，在官方应用中心使用“本地导入应用”上传 dotimagecompressor-1.0.1.zba
2. 插件管理中启用“新图片自动压缩”，打开管理页检查 GD/JPEG/PNG/WebP/EXIF 能力
3. 上传一张测试图片，核对 URL、显示、透明度和管理页“本地文件已验证”记录
4. 下载原图并核对文件，确认备份恢复功能可用

ZIP 为源码备份，不要重命名成 .zba。ZBA 按官方 App::PackGZip 格式生成，并通过同版本 App::UnPack 与 CheckCompatibility 验证。安装包含 PHP 代码，仅在授权后执行。AppCentre 导入与插件启用是两个独立步骤。

## 限制
- 普通上传必须先通过 nginx、PHP、UEditor 和 Z-Blog 的原始文件大小限制。压缩发生在这些检查之后，不能让超过 2 MiB 上限的原图绕过检查；插件不修改上传限制
- UEditor 涂鸦/Base64 的原生大小检查与普通上传不同；插件对实际原始字节再检查站点大小限制，超限时跳过压缩，不接管原生上传行为
- 安全上限：16 MiB、2000 万像素、最长边 12000 像素；还会按 PHP 内存限制保守跳过。缺少 GD、编码器不可用、损坏文件、压缩变大、备份失败时保留原上传流程
- JPEG/WebP 为有损压缩，重新编码会移除 EXIF 等元数据。ICC 图片跳过以避免色彩管理改变
- 默认透明 WebP 需要现代浏览器。与云存储、附件接管类插件同时使用前必须另行验证
- 图像解码器的安全性依赖服务器 PHP/libgd 等组件保持更新

## 原图备份与恢复
- 采用压缩结果前必须成功备份原始字节。备份保存于 zb_users/data/dotimagecompressor，独立于插件目录
- 备份是随机命名的 .php 防访问容器：立即返回 404/exit，原始内容仅是 Base64 数据，不会执行上传内容
- 仅超级管理员可使用带 CSRF 校验的 POST“下载原图”。读取时校验大小与 SHA-256；不使用 include/eval 执行备份
- 备份磁盘配额 512 MiB（Base64 大约多占三分之一）。达到配额则停止压缩，原文件照常上传；不会自动删除旧备份
- 管理页展示最近 20 份备份。停用和卸载不删除备份/设置，已压缩图片仍可正常访问
- 恢复是下载完整原始文件，不自动修改现有文章：暂时关闭自动压缩，重新上传原图，替换文章中的图片链接。避免把 PNG 原图写入 .webp 链接造成格式不符
- 如需删除全部备份，请另行授权管理员在站点备份完成后处理；插件没有一键删除功能

## 测试环境与结果
开发验证使用 PHP 8.2.32、GD（JPEG/PNG/WebP/EXIF）、官方 Z-BlogPHP v1.7.5.3540 源码与 bundled UEditor 1.6.10。不同服务器的扩展与上传配置仍需安装后验证。
测试包括透明 PNG、8 种 EXIF 方向、动图跳过、损坏文件、扩展名错配、内存保护、无 GD、备份 SHA-256 恢复、路径/软链保护、唯一命名、UEditor JSON/JSONP、真实 multipart/base64 上传与官方打包解包。

## 官方实现依据
- https://github.com/zblogcn/zblogphp/blob/v1.7.5.3540/zb_system/function/lib/base/upload.php
- https://github.com/zblogcn/zblogphp/blob/v1.7.5.3540/zb_users/plugin/UEditor/php/Uploader.class.php
- https://github.com/zblogcn/zblogphp/blob/v1.7.5.3540/zb_system/function/lib/app.php
- https://github.com/zblogcn/zblogphp/releases/tag/v1.7.5.3540

## 1.0.1 更新
修复启用 open_basedir 时从文件系统根逐级检查祖先目录引发的警告。现在仅从 PHP 明确允许且包含目标目录的边界向下检查，继续拒绝范围内软链和控制文件软链；不更改服务器安全配置。新增 open_basedir 边界回归，并在 128 MiB 内存、无 EXIF、站点根目录加 /tmp 限制下完成 HTTP 上传回归。1.0.0 不应继续用于此受限环境。
