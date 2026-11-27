<?php
/**
 * POST /api/upload.php  (admin)  multipart/form-data: file, type = 'menu' | 'qr_logo'
 * Validasi MIME ketat (jpeg/png/webp), maks 3 MB, resize otomatis via GD
 * (menu: lebar maks 800px -> JPG, logo QR: lebar maks 300px -> PNG transparan).
 */
declare(strict_types=1);
require __DIR__ . '/../config/database.php';

if (request_method() !== 'POST') {
    json_error('Method tidak didukung.', 405);
}
require_staff('admin');

if (!extension_loaded('gd')) {
    json_error('Ekstensi PHP GD tidak aktif di hosting. Aktifkan lewat cPanel > Select PHP Version > Extensions.', 500);
}

$type = ($_POST['type'] ?? 'menu') === 'qr_logo' ? 'qr_logo' : 'menu';
$file = $_FILES['file'] ?? null;
if (!$file || !is_array($file) || is_array($file['error'])) {
    // post_max_size terlampaui -> $_FILES & $_POST kosong
    json_error('File tidak diterima server. Pastikan ukuran file maksimal 3 MB.', 422);
}
$errors = [
    UPLOAD_ERR_INI_SIZE => 'Ukuran file melebihi batas upload hosting.',
    UPLOAD_ERR_FORM_SIZE => 'Ukuran file terlalu besar.',
    UPLOAD_ERR_PARTIAL => 'Upload terputus, silakan coba lagi.',
    UPLOAD_ERR_NO_FILE => 'Pilih file gambar terlebih dahulu.',
    UPLOAD_ERR_NO_TMP_DIR => 'Folder temporary server tidak tersedia.',
    UPLOAD_ERR_CANT_WRITE => 'Server gagal menulis file.',
];
if ($file['error'] !== UPLOAD_ERR_OK) {
    json_error($errors[$file['error']] ?? 'Upload gagal.', 422);
}
if ($file['size'] > 3 * 1024 * 1024) {
    json_error('Ukuran file maksimal 3 MB.', 422);
}
if (!is_uploaded_file($file['tmp_name'])) {
    json_error('Upload tidak valid.', 422);
}

$allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
$mime = '';
if (function_exists('finfo_open')) {
    $fi = finfo_open(FILEINFO_MIME_TYPE);
    $mime = (string) finfo_file($fi, $file['tmp_name']);
    finfo_close($fi);
}
$info = @getimagesize($file['tmp_name']);
if (!$info || !isset($allowed[$info['mime']]) || ($mime !== '' && !isset($allowed[$mime]))) {
    json_error('Format file harus JPG, PNG, atau WEBP.', 422);
}
$ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
    json_error('Ekstensi file harus .jpg, .jpeg, .png, atau .webp.', 422);
}
[$w, $h] = $info;
if ($w < 16 || $h < 16 || $w * $h > 40000000) {
    json_error('Dimensi gambar tidak didukung.', 422);
}

@ini_set('memory_limit', '256M');
$src = match ($info['mime']) {
    'image/jpeg' => @imagecreatefromjpeg($file['tmp_name']),
    'image/png' => @imagecreatefrompng($file['tmp_name']),
    'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($file['tmp_name']) : false,
    default => false,
};
if (!$src) {
    json_error('Gambar tidak dapat dibaca (format tidak didukung server).', 422);
}

// Koreksi orientasi foto dari kamera HP (EXIF)
if ($info['mime'] === 'image/jpeg' && function_exists('exif_read_data')) {
    $exif = @exif_read_data($file['tmp_name']);
    $orientation = (int) ($exif['Orientation'] ?? 1);
    $rotated = match ($orientation) {
        3 => imagerotate($src, 180, 0),
        6 => imagerotate($src, -90, 0),
        8 => imagerotate($src, 90, 0),
        default => null,
    };
    if ($rotated) {
        imagedestroy($src);
        $src = $rotated;
    }
}
$w = imagesx($src);
$h = imagesy($src);

$maxW = $type === 'menu' ? 800 : 300;
$newW = min($w, $maxW);
$newH = (int) max(1, round($h * $newW / $w));
$dst = imagecreatetruecolor($newW, $newH);

$dir = GAHC_ROOT . '/uploads/' . ($type === 'menu' ? 'menus' : 'qr');
if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
    json_error('Folder uploads tidak dapat dibuat. Periksa permission folder.', 500);
}
$name = ($type === 'menu' ? 'menu_' : 'logo_') . date('Ymd') . '_' . bin2hex(random_bytes(8));

if ($type === 'menu') {
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255)); // latar putih untuk PNG transparan
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $w, $h);
    $filename = $name . '.jpg';
    $ok = imagejpeg($dst, $dir . '/' . $filename, 82);
} else {
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $w, $h);
    $filename = $name . '.png';
    $ok = imagepng($dst, $dir . '/' . $filename, 8);
}
imagedestroy($src);
imagedestroy($dst);
if (!$ok) {
    json_error('Gagal menyimpan gambar.', 500);
}
@chmod($dir . '/' . $filename, 0644);

json_ok([
    'url' => 'uploads/' . ($type === 'menu' ? 'menus' : 'qr') . '/' . $filename,
    'width' => $newW,
    'height' => $newH,
], 'Upload berhasil.');
