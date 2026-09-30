<?php
require '../../../zb_system/function/c_system_base.php';
require '../../../zb_system/function/c_system_admin.php';
$zbp->Load();
if (!$zbp->CheckRights('root')) { $zbp->ShowError(6); exit; }
if (!$zbp->CheckPlugin('dotimagecompressor')) { $zbp->ShowError(48); exit; }
require_once __DIR__ . '/include.php';
function DIC_E($v): string { return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
$message = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $token = $_POST['csrfToken'] ?? '';
    if (!is_string($token) || !$zbp->VerifyCSRFToken($token, 'dotimagecompressor')) { http_response_code(403); exit('CSRF validation failed'); }
    $action = $_POST['action'] ?? '';
    if ($action === 'download') {
        $id = $_POST['backup'] ?? '';
        $item = is_string($id) ? DIC_Engine::readBackup(DIC_BackupDir(), $id) : null;
        if (!$item) { http_response_code(404); exit('Backup not found'); }
        header('Content-Type: application/octet-stream');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        header('Content-Length: ' . strlen($item['bytes']));
        header("Content-Disposition: attachment; filename=\"original-image\"; filename*=UTF-8''" . rawurlencode($item['name']));
        echo $item['bytes']; exit;
    }
    if ($action === 'save') {
        foreach (['max_edge', 'quality'] as $field) {
            if (!isset($_POST[$field]) || !is_string($_POST[$field]) || !ctype_digit($_POST[$field])) { http_response_code(400); exit('Invalid settings'); }
        }
        $s = DIC_Engine::settings(['enabled' => isset($_POST['enabled']), 'max_edge' => $_POST['max_edge'], 'quality' => $_POST['quality'], 'webp' => isset($_POST['webp'])]);
        foreach ($s as $k => $v) { $zbp->Config('dotimagecompressor')->$k = is_bool($v) ? (int) $v : $v; }
        $zbp->SaveConfig('dotimagecompressor');
        $message = '设置已保存';
    }
}
$settings = DIC_Settings(); $caps = DIC_Engine::capabilities();
$token = $zbp->GetCSRFToken('dotimagecompressor');
$blogtitle = '新图片自动压缩';
require $blogpath . 'zb_system/admin/admin_header.php';
require $blogpath . 'zb_system/admin/admin_top.php';
?>
<div id="divMain">
<div class="divHeader">新图片自动压缩</div>
<div id="divMain2">
<?php if ($message): ?><p><?php echo DIC_E($message); ?></p><?php endif; ?>
<p>只处理启用后新上传的本地 JPEG、PNG、WebP。不会批量改写旧文章或旧附件。最长边等比缩小，不放大；只有明显更小且校验通过才替换。</p>
<p><b>环境检测：</b>GD <?php echo $caps['gd'] ? '可用' : '不可用（将跳过所有压缩）'; ?>；JPEG <?php echo $caps['jpeg'] ? '可用' : '不可用'; ?>；PNG <?php echo $caps['png'] ? '可用' : '不可用'; ?>；WebP <?php echo $caps['webp'] ? '可用' : '不可用（使用原格式）'; ?>；EXIF <?php echo $caps['exif'] ? '可用' : '不可用（带 EXIF 的 JPEG 将跳过）'; ?>；PHP 内存限制 <?php echo DIC_E($caps['memory_limit']); ?></p>
<p>站点上传上限：<?php echo (int) $zbp->option['ZC_UPLOAD_FILESIZE']; ?> MiB。普通文件上传必须先通过 Z-Blog、编辑器、PHP 和 nginx 的限制；本插件不提高它们。UEditor 涂鸦/Base64 的原生校验不同，超过站点上限时本插件不参与压缩。</p>
<p>安全限制：单文件最多 16 MiB / 2000 万像素 / 单边 12000 像素，并按可用内存进一步限制。GIF、APNG、动画 WebP、ICC 色彩配置图片以及无法安全处理方向的图片跳过。</p>
<form method="post">
<input type="hidden" name="csrfToken" value="<?php echo DIC_E($token); ?>">
<input type="hidden" name="action" value="save">
<p><label><input type="checkbox" name="enabled" value="1" <?php echo $settings['enabled'] ? 'checked' : ''; ?>> 自动压缩新图片</label></p>
<p><label>最长边（320–4096）：<input type="number" name="max_edge" min="320" max="4096" required value="<?php echo $settings['max_edge']; ?>"> px</label></p>
<p><label>JPEG / WebP 质量（60–95）：<input type="number" name="quality" min="60" max="95" required value="<?php echo $settings['quality']; ?>"></label></p>
<p><label><input type="checkbox" name="webp" value="1" <?php echo $settings['webp'] ? 'checked' : ''; ?>> 优先 WebP（有损，保留透明通道；生成新 .webp 文件名）</label></p>
<p>仅在服务器编码/解码支持且站点允许上传 webp 时转换，否则保持原格式。PNG 原格式使用无损编码；缩小尺寸仍会改变像素。不会量化调色板。重新编码移除 EXIF 等元数据，JPEG 在移除前校正方向。</p>
<p><input type="submit" class="button" value="保存设置"></p>
</form>
<h3>原图备份与恢复</h3>
<p>采用原图备份后才会压缩，备份保存于插件目录外且受 PHP 拒绝访问保护；仅超级管理员可在本页下载。备份总量上限 512 MiB（Base64 约多占 1/3），达到上限或备份失败会跳过压缩。停用、卸载不删除备份和设置，也不回改已经压缩的图片。</p>
<p>“下载原图”会恢复原始文件字节到你的电脑。需要替换文章图片时，可暂时关闭自动压缩、重新上传原图并替换文章链接；不会把 PNG 内容直接写入现有 .webp 链接。</p>
<?php
$files = glob(DIC_BackupDir() . '/[a-f0-9]*.php') ?: [];
usort($files, static function ($a, $b) { return filemtime($b) <=> filemtime($a); });
echo '<p>最近 20 份备份：</p><ul>';
$count = 0;
foreach ($files as $f) {
    $id = basename($f, '.php');
    if (!preg_match('/\A[a-f0-9]{32}\z/D', $id)) { continue; }
    $item = DIC_Engine::readBackup(DIC_BackupDir(), $id, false);
    if (!$item) { continue; }
    echo '<li>' . DIC_E($item['name']) . ' (' . number_format($item['size'] / 1024, 1) . ' KiB) ' . DIC_E(date('Y-m-d H:i', $item['created']));
    echo '<form method="post" style="display:inline"><input type="hidden" name="csrfToken" value="' . DIC_E($token) . '"><input type="hidden" name="action" value="download"><input type="hidden" name="backup" value="' . $id . '"><button type="submit">下载原图</button></form></li>';
    if (++$count >= 20) { break; }
}
echo '</ul>';
if (!$count) { echo '<p>暂无备份（还没有新图片成功进入压缩流程）</p>'; }
$status = DIC_BackupDir() . '/status.php';
if (is_file($status) && !is_link($status) && filesize($status) < 100000) {
    $raw = file_get_contents($status);
    if (substr($raw, 0, strlen(DIC_Engine::GUARD)) === DIC_Engine::GUARD) {
        $rows = json_decode(substr($raw, strlen(DIC_Engine::GUARD)), true) ?: [];
        echo '<h3>最近处理记录</h3><ul>';
        foreach (array_slice($rows, 0, 10) as $row) {
            echo '<li>' . DIC_E(date('Y-m-d H:i:s', $row['time'])) . ' ';
            if ($row['status'] === 'saved') {
                echo DIC_E($row['name']) . '：' . number_format($row['before'] / 1024, 1) . ' → ' . number_format($row['after'] / 1024, 1) . ' KiB（本地文件已验证）';
            } elseif ($row['status'] === 'prepared_unverified') { echo '已准备压缩内容，但未验证本地保存结果，请检查附件'; }
            else { echo '跳过：' . DIC_E($row['reason'] ?? 'unknown'); }
            echo '</li>';
        }
        echo '</ul>';
    }
}
?>
<p>图片仅在本站服务器处理，不发送给第三方。与接管附件上传的云存储插件同时使用前需单独验证。</p>
</div></div>
<script>ActiveLeftMenu('aPluginMng');</script>
<?php require $blogpath . 'zb_system/admin/admin_footer.php'; ?>
