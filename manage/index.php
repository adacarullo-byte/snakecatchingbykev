<?php
/*
 * Snake Catching By Kev — gallery manager
 * Single-file admin: first visit sets a password, then upload/caption/delete
 * photos & videos that the public gallery (index.html) renders from
 * ../gallery-data/gallery.json
 */
declare(strict_types=1);

const ROOT  = __DIR__ . '/..';
const DATA  = ROOT . '/gallery-data';
const MEDIA = DATA . '/media';
const MANIFEST = DATA . '/gallery.json';
const CONFIG   = __DIR__ . '/config.php';
const LOCKFILE = DATA . '/.loginlock';
const MAX_FAILS = 8;          // lock after this many bad passwords
const LOCK_MINS = 15;

session_set_cookie_params(['lifetime' => 60*60*24*30, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
ini_set('session.gc_maxlifetime', (string)(60*60*24*30));
session_start();

/* ---------- helpers ---------- */
function cfg(): array {
    $c = @include CONFIG;
    return is_array($c) ? $c : [];
}
function save_cfg(array $c): void {
    file_put_contents(CONFIG, "<?php\nreturn " . var_export($c, true) . ";\n", LOCK_EX);
}
function manifest(): array {
    $j = @json_decode((string)@file_get_contents(MANIFEST), true);
    return is_array($j) ? $j : [];
}
function save_manifest(array $items): void {
    if (!is_dir(DATA)) mkdir(DATA, 0755, true);
    $tmp = MANIFEST . '.tmp';
    file_put_contents($tmp, json_encode(array_values($items), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    rename($tmp, MANIFEST);
}
function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function logged_in(): bool { return !empty($_SESSION['kev_ok']); }
function csrf(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function check_csrf(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) { http_response_code(400); exit('Bad request'); }
}
function lock_state(): array {
    $s = @json_decode((string)@file_get_contents(LOCKFILE), true);
    return is_array($s) ? $s : ['fails' => 0, 'ts' => 0];
}
function locked(): bool {
    $s = lock_state();
    return $s['fails'] >= MAX_FAILS && (time() - $s['ts']) < LOCK_MINS * 60;
}
function note_fail(): void {
    $s = lock_state();
    $s = ['fails' => $s['fails'] + 1, 'ts' => time()];
    @file_put_contents(LOCKFILE, json_encode($s), LOCK_EX);
}
function clear_fails(): void { @unlink(LOCKFILE); }

$msg = ''; $err = '';

/* ---------- actions ---------- */
$action = $_POST['action'] ?? '';

/* friendly error when a video exceeds the server's post size */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && empty($_FILES)
    && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    $err = 'That file was too big for the server to accept. Try a shorter video (or send it to Ada).';
}

if ($action === 'setup' && !cfg()) {
    $p1 = $_POST['pw'] ?? ''; $p2 = $_POST['pw2'] ?? '';
    if (strlen($p1) < 8)      $err = 'Password needs at least 8 characters.';
    elseif ($p1 !== $p2)      $err = "Those two didn't match — try again.";
    else { save_cfg(['password_hash' => password_hash($p1, PASSWORD_DEFAULT)]); $_SESSION['kev_ok'] = true; $msg = 'Password set. Welcome!'; }
}

if ($action === 'login') {
    if (locked()) $err = 'Too many tries. Wait 15 minutes and try again.';
    elseif (password_verify($_POST['pw'] ?? '', cfg()['password_hash'] ?? '')) {
        session_regenerate_id(true); $_SESSION['kev_ok'] = true; clear_fails();
    } else { sleep(2); note_fail(); $err = "That password isn't right."; }
}

if ($action === 'logout') { check_csrf(); session_destroy(); header('Location: ./'); exit; }

if (logged_in() && $action === 'passwd') {
    check_csrf();
    if (!password_verify($_POST['cur'] ?? '', cfg()['password_hash'] ?? '')) $err = "Current password isn't right.";
    elseif (strlen($_POST['new'] ?? '') < 8) $err = 'New password needs at least 8 characters.';
    elseif (($_POST['new'] ?? '') !== ($_POST['new2'] ?? '')) $err = "The two new passwords didn't match.";
    else { save_cfg(['password_hash' => password_hash($_POST['new'], PASSWORD_DEFAULT)]); $msg = 'Password changed.'; }
}

if (logged_in() && $action === 'upload') {
    check_csrf();
    if (!is_dir(MEDIA)) mkdir(MEDIA, 0755, true);
    $items = manifest(); $added = 0; $failed = [];
    $files = $_FILES['media'] ?? null;
    if ($files) {
        $n = is_array($files['name']) ? count($files['name']) : 0;
        $fi = new finfo(FILEINFO_MIME_TYPE);
        for ($i = 0; $i < $n; $i++) {
            if ($files['error'][$i] !== UPLOAD_ERR_OK) { $failed[] = $files['name'][$i]; continue; }
            $tmp  = $files['tmp_name'][$i];
            $mime = (string)$fi->file($tmp);
            $id   = date('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 6);
            if (str_starts_with($mime, 'image/')) {
                $src = @imagecreatefromstring((string)file_get_contents($tmp));
                if (!$src) { $failed[] = $files['name'][$i] . ' (format not supported — try a JPEG)'; continue; }
                $w = imagesx($src); $hgt = imagesy($src);
                $scale = min(1, 1600 / max($w, $hgt));
                $nw = (int)($w * $scale); $nh = (int)($hgt * $scale);
                $dst = imagecreatetruecolor($nw, $nh);
                imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
                imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $hgt);
                $name = $id . '.jpg';
                imagejpeg($dst, MEDIA . '/' . $name, 82);
                imagedestroy($src); imagedestroy($dst);
                $items[] = ['id' => $name, 'src' => 'gallery-data/media/' . $name, 'type' => 'image', 'caption' => '', 'added' => time()];
                $added++;
            } elseif (str_starts_with($mime, 'video/')) {
                $ext = strtolower(pathinfo($files['name'][$i], PATHINFO_EXTENSION));
                if (!in_array($ext, ['mp4', 'mov', 'm4v', 'webm'], true)) { $failed[] = $files['name'][$i] . ' (use MP4 or MOV)'; continue; }
                $name = $id . '.' . $ext;
                if (!move_uploaded_file($tmp, MEDIA . '/' . $name)) { $failed[] = $files['name'][$i]; continue; }
                $items[] = ['id' => $name, 'src' => 'gallery-data/media/' . $name, 'type' => 'video', 'caption' => '', 'added' => time()];
                $added++;
            } else { $failed[] = $files['name'][$i] . ' (not a photo or video)'; }
        }
    }
    if ($added) { save_manifest($items); $msg = $added . ($added === 1 ? ' item' : ' items') . ' added to the gallery.'; }
    if ($failed) $err = "Couldn't add: " . implode(', ', array_map('strip_tags', $failed));
}

if (logged_in() && $action === 'caption') {
    check_csrf();
    $items = manifest();
    foreach ($items as &$it) if ($it['id'] === ($_POST['id'] ?? '')) $it['caption'] = trim(substr((string)($_POST['caption'] ?? ''), 0, 160));
    save_manifest($items); $msg = 'Caption saved.';
}

if (logged_in() && $action === 'delete') {
    check_csrf();
    $items = manifest(); $keep = [];
    foreach ($items as $it) {
        if ($it['id'] === ($_POST['id'] ?? '')) {
            $f = MEDIA . '/' . basename($it['id']);           // only ever delete inside media/
            if (str_starts_with($it['src'], 'gallery-data/media/') && is_file($f)) @unlink($f);
            continue;
        }
        $keep[] = $it;
    }
    save_manifest($keep); $msg = 'Removed from the gallery.';
}

$items = array_reverse(manifest());   // newest first for the manage list
$needs_setup = !cfg();
?>
<!DOCTYPE html>
<html lang="en-AU">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Gallery Manager</title>
<link rel="icon" href="../images/favicon.png">
<style>
  :root { --navy:#17253F; --gold:#E8A63D; --gold-deep:#C9882A; --cream:#F5EFE2; --soft:#5E594E; --red:#A33B2E; --green:#3F7044; }
  * { box-sizing:border-box; }
  body { margin:0; background:var(--cream); color:#22201B; font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif; font-size:17px; line-height:1.5; }
  .top { background:var(--navy); color:var(--cream); padding:14px 18px; display:flex; align-items:center; gap:12px; }
  .top img { width:42px; height:42px; border-radius:50%; }
  .top b { font-size:18px; text-transform:uppercase; letter-spacing:.02em; }
  .top form { margin-left:auto; }
  .wrap { max-width:560px; margin:0 auto; padding:18px 16px 70px; }
  .card { background:#fff; border-radius:12px; padding:20px; margin:0 0 18px; box-shadow:0 2px 8px rgba(34,32,27,.1); }
  h1 { font-size:22px; margin:0 0 4px; color:var(--navy); }
  p.hint { color:var(--soft); font-size:15px; margin:4px 0 16px; }
  input[type=password], input[type=text], textarea { width:100%; font-size:17px; padding:12px; border:1.5px solid #D8D2C2; border-radius:8px; background:#FDFCF9; }
  textarea { resize:vertical; min-height:44px; }
  .btn { display:inline-block; width:100%; text-align:center; background:var(--gold); color:var(--navy); border:0; border-radius:8px; font-size:18px; font-weight:700; padding:14px 18px; cursor:pointer; box-shadow:0 3px 0 var(--gold-deep); text-transform:uppercase; font-family:inherit; }
  .btn:active { transform:translateY(2px); box-shadow:none; }
  .btn.small { width:auto; font-size:14px; padding:8px 14px; text-transform:none; box-shadow:none; }
  .btn.ghost { background:#EDE4D0; color:var(--navy); }
  .btn.danger { background:#F0D9D4; color:var(--red); }
  .msg { padding:12px 16px; border-radius:8px; margin:0 0 16px; font-weight:600; }
  .msg.ok { background:#E3EEE4; color:var(--green); }
  .msg.bad { background:#F4DEDA; color:var(--red); }
  .item { display:flex; gap:12px; align-items:flex-start; }
  .item + .item { margin-top:16px; padding-top:16px; border-top:1px solid #EFEAD9; }
  .item img, .item video { width:104px; height:104px; object-fit:cover; border-radius:8px; background:#000; flex:none; }
  .item .grow { flex:1; min-width:0; }
  .item .row { display:flex; gap:8px; margin-top:8px; }
  .tag { font-size:11px; text-transform:uppercase; letter-spacing:.06em; font-weight:700; color:var(--soft); }
  #bar { height:10px; background:#EDE4D0; border-radius:6px; overflow:hidden; margin-top:12px; display:none; }
  #bar i { display:block; height:100%; width:0; background:var(--gold); transition:width .2s; }
  details { margin-top:4px; }
  summary { color:var(--soft); font-size:15px; cursor:pointer; }
  label.filebtn { display:block; }
  label.filebtn input { display:none; }
</style>
</head>
<body>
<div class="top">
  <img src="../images/logo-small.webp" alt="">
  <b>Gallery Manager</b>
  <?php if (logged_in()): ?>
  <form method="post"><input type="hidden" name="action" value="logout"><input type="hidden" name="csrf" value="<?=csrf()?>"><button class="btn small ghost">Log out</button></form>
  <?php endif; ?>
</div>
<div class="wrap">
  <?php if ($msg): ?><div class="msg ok"><?=h($msg)?></div><?php endif; ?>
  <?php if ($err): ?><div class="msg bad"><?=h($err)?></div><?php endif; ?>

<?php if ($needs_setup): ?>
  <div class="card">
    <h1>First time setup</h1>
    <p class="hint">Pick a password for managing the gallery. At least 8 characters — write it down somewhere safe.</p>
    <form method="post">
      <input type="hidden" name="action" value="setup">
      <p><input type="password" name="pw" placeholder="New password" autocomplete="new-password"></p>
      <p><input type="password" name="pw2" placeholder="Same password again" autocomplete="new-password"></p>
      <button class="btn">Set password</button>
    </form>
  </div>

<?php elseif (!logged_in()): ?>
  <div class="card">
    <h1>G'day Kev 👋</h1>
    <p class="hint">Log in to add photos and videos to your website.</p>
    <form method="post">
      <input type="hidden" name="action" value="login">
      <p><input type="password" name="pw" placeholder="Password" autocomplete="current-password" autofocus></p>
      <button class="btn">Log in</button>
    </form>
  </div>

<?php else: ?>
  <div class="card">
    <h1>Add to your gallery</h1>
    <p class="hint">Straight from your phone — pick photos or videos from your camera roll. They appear on the website right away.</p>
    <form id="upform" method="post" enctype="multipart/form-data">
      <input type="hidden" name="action" value="upload">
      <input type="hidden" name="csrf" value="<?=csrf()?>">
      <label class="filebtn"><span class="btn">📷 Add photos or videos</span>
        <input id="file" type="file" name="media[]" accept="image/*,video/mp4,video/quicktime,video/webm" multiple>
      </label>
      <div id="bar"><i></i></div>
      <p class="hint" id="upnote" style="display:none">Uploading — keep this page open…</p>
    </form>
  </div>

  <div class="card">
    <h1>What's in the gallery</h1>
    <p class="hint">Newest first. Captions are optional — a few words is plenty.</p>
    <?php if (!$items): ?><p class="hint">Nothing here yet.</p><?php endif; ?>
    <?php foreach ($items as $it): ?>
    <div class="item">
      <?php if ($it['type'] === 'video'): ?>
        <video src="../<?=h($it['src'])?>" preload="metadata" muted playsinline></video>
      <?php else: ?>
        <img src="../<?=h($it['src'])?>" alt="" loading="lazy">
      <?php endif; ?>
      <div class="grow">
        <span class="tag"><?=$it['type']==='video' ? '🎬 Video' : '📷 Photo'?></span>
        <form method="post">
          <input type="hidden" name="action" value="caption">
          <input type="hidden" name="csrf" value="<?=csrf()?>">
          <input type="hidden" name="id" value="<?=h($it['id'])?>">
          <textarea name="caption" maxlength="160" placeholder="Caption (optional)"><?=h($it['caption'])?></textarea>
          <div class="row">
            <button class="btn small ghost">Save caption</button>
          </div>
        </form>
        <form method="post" onsubmit="return confirm('Remove this from the website?');" class="row">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="csrf" value="<?=csrf()?>">
          <input type="hidden" name="id" value="<?=h($it['id'])?>">
          <button class="btn small danger">Remove</button>
        </form>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="card">
    <details>
      <summary>Change password</summary>
      <form method="post" style="margin-top:12px">
        <input type="hidden" name="action" value="passwd">
        <input type="hidden" name="csrf" value="<?=csrf()?>">
        <p><input type="password" name="cur" placeholder="Current password" autocomplete="current-password"></p>
        <p><input type="password" name="new" placeholder="New password (8+ characters)" autocomplete="new-password"></p>
        <p><input type="password" name="new2" placeholder="New password again" autocomplete="new-password"></p>
        <button class="btn small ghost">Change password</button>
      </form>
    </details>
  </div>

<script>
/* upload with a progress bar, then reload */
const fi = document.getElementById('file');
fi && fi.addEventListener('change', () => {
  if (!fi.files.length) return;
  const fd = new FormData(document.getElementById('upform'));
  const xhr = new XMLHttpRequest();
  const bar = document.getElementById('bar'), fill = bar.querySelector('i');
  bar.style.display = 'block';
  document.getElementById('upnote').style.display = 'block';
  xhr.upload.onprogress = e => { if (e.lengthComputable) fill.style.width = (100 * e.loaded / e.total).toFixed(1) + '%'; };
  xhr.onload = xhr.onerror = () => location.reload();
  xhr.open('POST', location.href);
  xhr.send(fd);
});
</script>
<?php endif; ?>
</div>
</body>
</html>
