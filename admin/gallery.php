<?php
/**
 * Gallery manager: upload, caption, reorder and delete the photos shown on the website.
 */
require __DIR__ . '/_init.php';
require_login();

const MAX_UPLOAD_BYTES = 8 * 1024 * 1024;
const ALLOWED_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];

$pdo = db();
$dir = dirname(__DIR__) . '/' . GALLERY_DIR;

// The drag-and-drop uploader (assets/gallery-upload.js) sends one file per request and wants JSON back.
$ajax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
function upload_reply(bool $ok, string $message): void
{
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => $ok, 'message' => $message]);
    exit;
}

// A POST larger than post_max_size arrives with empty $_POST/$_FILES (so no CSRF token either).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    $msg = 'The upload was too large for the server (limit ' . ini_get('post_max_size') . ').';
    if ($ajax) upload_reply(false, $msg);
    flash($msg . ' Upload fewer or smaller images at a time.', 'error');
    redirect('gallery.php');
}
if ($ajax && $_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_valid()) {
    upload_reply(false, 'Your session expired. Refresh the page and try again.');
}
require_post_csrf();

$action = (string)($_POST['action'] ?? '');

if ($action === 'upload') {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $files = $_FILES['images'] ?? null;
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $order = (int)$pdo->query('SELECT COALESCE(MAX(sort_order), 0) FROM gallery_images')->fetchColumn();
    $insert = $pdo->prepare('INSERT INTO gallery_images (filename, caption, sort_order) VALUES (?, ?, ?)');
    $ok = 0;
    $problems = [];

    foreach ((array)($files['name'] ?? []) as $i => $original) {
        $label = '"' . mb_substr((string)$original, 0, 60) . '"';
        $err = $files['error'][$i];
        if ($err === UPLOAD_ERR_NO_FILE) continue;
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE || $files['size'][$i] > MAX_UPLOAD_BYTES) {
            $problems[] = "$label is larger than 8 MB.";
            continue;
        }
        if ($err !== UPLOAD_ERR_OK) {
            $problems[] = "$label failed to upload (error $err).";
            continue;
        }
        $tmp = $files['tmp_name'][$i];
        $mime = $finfo->file($tmp);
        if (!isset(ALLOWED_TYPES[$mime]) || @getimagesize($tmp) === false) {
            $problems[] = "$label is not a JPG, PNG, WebP or GIF image.";
            continue;
        }
        $name = date('Ymd') . '-' . bin2hex(random_bytes(8)) . '.' . ALLOWED_TYPES[$mime];
        if (!move_uploaded_file($tmp, "$dir/$name")) {
            $problems[] = "$label could not be saved — check that the uploads/gallery folder is writable.";
            continue;
        }
        $caption = mb_substr(trim((string)($_POST['caption'] ?? '')), 0, 200);
        $insert->execute([$name, $caption, ++$order]);
        $ok++;
    }

    if ($ajax) {
        upload_reply($ok > 0, $ok ? 'Uploaded.' : ($problems[0] ?? 'No image received.'));
    }
    if ($ok) flash("$ok image(s) added to the gallery.");
    foreach ($problems as $p) flash($p, 'error');
    if (!$ok && !$problems) flash('Choose at least one image to upload.', 'error');
    redirect('gallery.php');
}

if ($action === 'save') {
    $update = $pdo->prepare('UPDATE gallery_images SET caption = ?, sort_order = ? WHERE id = ?');
    foreach ((array)($_POST['caption'] ?? []) as $imgId => $caption) {
        $update->execute([
            mb_substr(trim((string)$caption), 0, 200),
            (int)($_POST['sort_order'][$imgId] ?? 0),
            (int)$imgId,
        ]);
    }
    flash('Gallery updated.');
    redirect('gallery.php');
}

if ($action === 'delete') {
    $stmt = $pdo->prepare('SELECT filename FROM gallery_images WHERE id = ?');
    $stmt->execute([(int)($_POST['id'] ?? 0)]);
    if ($file = $stmt->fetchColumn()) {
        $pdo->prepare('DELETE FROM gallery_images WHERE id = ?')->execute([(int)$_POST['id']]);
        $path = $dir . '/' . basename($file);
        if (is_file($path)) {
            unlink($path);
        }
        flash('Image removed from the gallery.');
    }
    redirect('gallery.php');
}

$images = gallery_images();

admin_header('Gallery', 'gallery.php');
?>
<h1>Gallery</h1>
<?php if (($added = (int)($_GET['added'] ?? 0)) > 0): ?>
    <div class="alert alert-success"><?= $added ?> photo<?= $added === 1 ? '' : 's' ?> added to the gallery.</div>
<?php endif; ?>
<p class="muted">These photos appear in the Gallery section of the website, in the order shown here.</p>

<section class="panel">
    <h2>Add images</h2>
    <form method="post" enctype="multipart/form-data" class="upload-form" id="upload-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="upload">
        <label class="dropzone" id="dropzone">
            <input type="file" name="images[]" accept="image/jpeg,image/png,image/webp,image/gif" multiple required>
            <span class="dz-text"><strong>Click to choose photos</strong> or drag them here<br>
                <small>JPG, PNG, WebP or GIF · up to 8 MB each · pick as many as you like, you can keep adding more</small></span>
        </label>
        <div class="upload-queue" id="upload-queue" hidden></div>
        <label>Caption for these images (optional) <input name="caption" maxlength="200"></label>
        <div class="upload-actions">
            <button class="btn" id="upload-btn">Upload</button>
            <button type="button" class="btn btn-outline" id="upload-clear" hidden>Clear selection</button>
            <span class="upload-summary muted" id="upload-summary"></span>
        </div>
        <div class="upload-progress" id="upload-progress" hidden><div></div></div>
        <ul class="upload-errors" id="upload-errors" hidden></ul>
    </form>
</section>

<section class="panel">
    <h2><?= count($images) ?> image<?= count($images) === 1 ? '' : 's' ?> in the gallery</h2>
    <?php if (!$images): ?>
        <p class="muted">The gallery is empty. Upload some photos above — the Gallery section is hidden on the website until there is at least one.</p>
    <?php else: ?>
        <form method="post" id="gallery-save">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
        </form>
        <div class="gallery-admin">
            <?php foreach ($images as $img): $id = (int)$img['id']; ?>
                <figure>
                    <img src="../<?= GALLERY_DIR ?>/<?= e($img['filename']) ?>" alt="" loading="lazy">
                    <figcaption>
                        <label>Caption <input form="gallery-save" name="caption[<?= $id ?>]" value="<?= e($img['caption']) ?>" maxlength="200"></label>
                        <div class="row">
                            <label>Order <input form="gallery-save" type="number" name="sort_order[<?= $id ?>]" value="<?= (int)$img['sort_order'] ?>"></label>
                            <form method="post" onsubmit="return confirm('Remove this image from the gallery?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $id ?>">
                                <button class="btn btn-sm btn-danger">Remove</button>
                            </form>
                        </div>
                    </figcaption>
                </figure>
            <?php endforeach; ?>
        </div>
        <p><button class="btn" form="gallery-save">Save captions &amp; order</button> <small class="muted">Lower order numbers show first.</small></p>
    <?php endif; ?>
</section>
<?php admin_footer(['assets/gallery-upload.js']);
