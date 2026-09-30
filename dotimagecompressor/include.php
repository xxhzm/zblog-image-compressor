<?php
if (!defined('ZBP_PATH')) { exit('Access denied'); }
require_once __DIR__ . '/engine.php';
RegisterPlugin('dotimagecompressor', 'ActivePlugin_dotimagecompressor');

function ActivePlugin_dotimagecompressor()
{
    Add_Filter_Plugin('Filter_Plugin_Upload_SaveFile', 'DIC_UploadFile');
    Add_Filter_Plugin('Filter_Plugin_Upload_SaveBase64File', 'DIC_UploadBase64');
}
function InstallPlugin_dotimagecompressor()
{
    global $zbp;
    $c = $zbp->Config('dotimagecompressor');
    foreach (['enabled' => 1, 'max_edge' => 1600, 'quality' => 82, 'webp' => 1] as $k => $v) {
        if (!$c->HasKey($k)) { $c->$k = $v; }
    }
    $zbp->SaveConfig('dotimagecompressor');
}
function UninstallPlugin_dotimagecompressor() { /* Preserve originals and configuration. */ }
function DIC_Settings(): array
{
    global $zbp;
    $c = $zbp->Config('dotimagecompressor');
    $s = [];
    foreach (['enabled', 'max_edge', 'quality', 'webp'] as $key) {
        if ($c->HasKey($key)) { $s[$key] = $c->$key; }
    }
    return DIC_Engine::settings($s);
}
function DIC_BackupDir(): string
{
    global $zbp;
    return rtrim($zbp->usersdir, '/\\') . '/data/dotimagecompressor';
}
function DIC_Record(array $record): void
{
    $dir = DIC_BackupDir();
    if (!DIC_Engine::safeDirectory($dir, true)) { return; }
    if (!DIC_Engine::safeFilePath($dir . '/status.php')) { return; }
    $f = @fopen($dir . '/status.php', 'c+');
    if (!$f || !flock($f, LOCK_EX)) { if ($f) { fclose($f); } return; }
    $all = stream_get_contents($f);
    $rows = [];
    if (substr($all, 0, strlen(DIC_Engine::GUARD)) === DIC_Engine::GUARD) {
        $rows = json_decode(substr($all, strlen(DIC_Engine::GUARD)), true) ?: [];
    }
    $record['time'] = time();
    array_unshift($rows, $record);
    $text = DIC_Engine::GUARD . json_encode(array_slice($rows, 0, 30), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    rewind($f); ftruncate($f, 0); fwrite($f, $text); fflush($f); flock($f, LOCK_UN); fclose($f);
    @chmod($dir . '/status.php', 0600);
}
function DIC_SafeUpload($upload): bool
{
    global $zbp;
    if ((int) $upload->ID !== 0) { return false; }
    $dir = (string) $upload->Dir;
    $name = (string) $upload->Name;
    if (!preg_match('~\Aupload/[0-9]{4}/[0-9]{2}/(?:[0-9]{2}/)?\z~D', $dir) || $name === '' || strlen($name) > 240 || preg_match('~[/\\\\\x00-\x1f]~', $name)) { return false; }
    $p = rtrim($zbp->usersdir, '/\\');
    $state = DIC_Engine::pathInfo($p);
    if ($state === null || !$state['directory'] || $state['link']) { return false; }
    foreach (explode('/', rtrim($dir, '/')) as $part) {
        $p .= '/' . $part;
        $state = DIC_Engine::pathInfo($p);
        if ($state === null || $state['link'] || ($state['exists'] && !$state['directory'])) { return false; }
    }
    return true;
}
function DIC_Prepare(string $source, $upload): ?array
{
    global $zbp;
    if (!DIC_SafeUpload($upload)) { return null; }
    $size = is_file($source) ? filesize($source) : 0;
    if (!$size || $size > 1048576 * (int) $zbp->option['ZC_UPLOAD_FILESIZE']) { DIC_Record(['status' => 'skipped', 'reason' => 'site_size_limit']); return null; }
    $settings = DIC_Settings();
    $ext = strtolower(pathinfo((string) $upload->Name, PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) { return null; }
    if (!$upload->CheckExtName()) { return null; }
    $allowed = array_map('strtolower', explode('|', $zbp->option['ZC_UPLOAD_FILETYPE']));
    if (!in_array('webp', $allowed, true)) { $settings['webp'] = false; }
    $candidate = DIC_Engine::candidate($source, $ext, $settings);
    if (!$candidate['ok']) { DIC_Record(['status' => 'skipped', 'reason' => $candidate['reason']]); return null; }
    // Every optimized image gets a fresh name: no same-format races or dangling-link reuse.
    do { $newName = date('YmdHis') . '_' . bin2hex(random_bytes(16)) . '.' . $candidate['ext']; }
    while (file_exists($zbp->usersdir . $upload->Dir . $newName) || is_link($zbp->usersdir . $upload->Dir . $newName));
    $sourceInfo = @getimagesize($source);
    $id = DIC_Engine::backup(DIC_BackupDir(), $source, (string) ($upload->SourceName ?: $upload->Name), $sourceInfo['mime'] ?? 'application/octet-stream');
    if (!$id) { @unlink($candidate['path']); DIC_Record(['status' => 'skipped', 'reason' => 'backup_failed_or_quota']); return null; }
    $candidate['backup'] = $id;
    $candidate['name'] = $newName;
    return $candidate;
}
function DIC_ApplyMetadata($upload, array $r): void
{
    $upload->Name = $r['name'];
    $upload->MimeType = $r['mime'];
    $upload->Size = $r['after'];
    $upload->Metas->dic_backup = $r['backup'];
    $upload->Metas->dic_original_size = $r['before'];
    $upload->Metas->dic_version = '1.0.1';
    DIC_NormalizeUEditor($upload);
    // Do not log success until the core's local save is independently verified.
    register_shutdown_function(static function () use ($upload, $r) {
        $path = (string) $upload->FullFile;
        clearstatcache(true, $path);
        $ok = is_file($path) && !is_link($path) && filesize($path) === $r['after'];
        $info = $ok ? @getimagesize($path) : false;
        $ok = $ok && $info && $info['mime'] === $r['mime'];
        DIC_Record(['status' => $ok ? 'saved' : 'prepared_unverified', 'backup' => $r['backup'], 'name' => $r['name'], 'before' => $r['before'], 'after' => $r['after']]);
    });
}
function DIC_UploadFile($tmp, $upload): void
{
    if (!is_string($tmp) || !is_uploaded_file($tmp)) { return; }
    $r = null;
    try {
        $r = DIC_Prepare($tmp, $upload);
        if (!$r) { return; }
        // Atomic replacement keeps the registered upload pathname for move_uploaded_file.
        if (!@rename($r['path'], $tmp)) { DIC_Record(['status' => 'skipped', 'reason' => 'replace_failed']); return; }
        DIC_ApplyMetadata($upload, $r);
    } catch (Throwable $e) { DIC_Record(['status' => 'skipped', 'reason' => 'hook_failed']); }
    finally { if ($r && is_file($r['path'])) { @unlink($r['path']); } }
}
function DIC_UploadBase64(&$str64, $upload): void
{
    if (!is_string($str64) || strlen($str64) > (int) (DIC_Engine::MAX_BYTES * 4 / 3) + 8) { return; }
    $source = null; $r = null;
    try {
        $bytes = base64_decode($str64, true);
        if ($bytes === false || strlen($bytes) > DIC_Engine::MAX_BYTES) { return; }
        $source = tempnam(sys_get_temp_dir(), 'dic-src-');
        if (!$source || file_put_contents($source, $bytes) !== strlen($bytes)) { return; }
        unset($bytes);
        $r = DIC_Prepare($source, $upload);
        if (!$r) { return; }
        $bytes = file_get_contents($r['path']);
        if ($bytes === false || strlen($bytes) !== $r['after']) { return; }
        $str64 = base64_encode($bytes);
        DIC_ApplyMetadata($upload, $r);
    } catch (Throwable $e) { DIC_Record(['status' => 'skipped', 'reason' => 'hook_failed']); }
    finally { if ($source && is_file($source)) { @unlink($source); } if ($r && is_file($r['path'])) { @unlink($r['path']); } }
}
function DIC_NormalizeUEditor($upload): void
{
    global $zbp;
    $expected = realpath($zbp->usersdir . 'plugin/UEditor/php/controller.php');
    $actual = realpath($_SERVER['SCRIPT_FILENAME'] ?? '');
    if (!$expected || $expected !== $actual || !in_array($_GET['action'] ?? '', ['uploadimage', 'uploadscrawl', 'uploadfile', 'uploadvideo', 'catchimage'], true)) { return; }
    $GLOBALS['dic_ue_uploads'][] = $upload;
    if (empty($GLOBALS['dic_ue_buffer'])) {
        $GLOBALS['dic_ue_buffer'] = true;
        ob_start('DIC_UEditorResponse');
    }
}
function DIC_UEditorResponse(string $body): string
{
    $prefix = ''; $suffix = ''; $json = $body;
    if (preg_match('/\A([A-Za-z0-9_]+)\((.*)\);?\s*\z/sD', $body, $m)) {
        $prefix = $m[1] . '('; $suffix = ');'; $json = $m[2];
    }
    $data = json_decode($json, true);
    if (!is_array($data)) { return $body; }
    $normalize = static function (&$row) {
        if (!is_array($row) || !isset($row['url'])) { return; }
        foreach ($GLOBALS['dic_ue_uploads'] ?? [] as $upload) {
            if ($row['url'] === $upload->Url) {
                $row['title'] = (string) $upload->Name;
                $row['type'] = '.' . strtolower(pathinfo($upload->Name, PATHINFO_EXTENSION));
                $row['size'] = (int) $upload->Size;
            }
        }
    };
    $normalize($data);
    if (isset($data['list']) && is_array($data['list'])) { foreach ($data['list'] as &$row) { $normalize($row); } unset($row); }
    $new = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $new === false ? $body : $prefix . $new . $suffix;
}
