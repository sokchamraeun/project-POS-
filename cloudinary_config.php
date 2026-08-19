<?php
/**
 * Cloudinary Configuration & Unified Helper Functions
 * 
 * Provides centralized Cloudinary initialization, upload, deletion,
 * and image URL resolution for Products, Categories, and Stock.
 * 
 * Fully compatible with both local development and shared/VPS hosting (Hostinger, cPanel, etc.).
 * Includes automatic native cURL fallback if Composer vendor packages are not present on the host.
 */

// Default Credentials
$cloudinary_cloud_name = 'ddroxkhuc';
$cloudinary_api_key    = '599982647463976';
$cloudinary_api_secret = 'm6sAWUsmsLQ-BNKrSF3qY9E6mmY';
$cloudinary_secure     = true;

// Support local environment override if present on hosting server
if (is_file(__DIR__ . '/cloudinary_config.local.php')) {
    require __DIR__ . '/cloudinary_config.local.php';
}

// Load Composer autoloader if present
$hasCloudinarySdk = false;
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
    if (class_exists('Cloudinary\Configuration\Configuration')) {
        $hasCloudinarySdk = true;
        try {
            \Cloudinary\Configuration\Configuration::instance([
                'cloud' => [
                    'cloud_name' => $cloudinary_cloud_name,
                    'api_key'    => $cloudinary_api_key,
                    'api_secret' => $cloudinary_api_secret,
                ],
                'url' => [
                    'secure' => (bool)$cloudinary_secure
                ]
            ]);
        } catch (\Throwable $e) {
            error_log("Cloudinary SDK Init Notice: " . $e->getMessage());
        }
    }
}

/**
 * Upload an image file (from $_FILES array or local file path) directly to Cloudinary.
 *
 * @param array|string $file Either $_FILES['image'] or an absolute/relative file path
 * @param string $folder Cloudinary destination folder (e.g., 'pos_coffee/products')
 * @return array ['success' => bool, 'url' => string, 'public_id' => string, 'error' => string]
 */
function cloudinary_upload_file($file, string $folder = 'pos_coffee/products'): array {
    global $cloudinary_cloud_name, $cloudinary_api_key, $cloudinary_api_secret, $hasCloudinarySdk;

    $tmpPath = '';
    $originalName = '';

    if (is_array($file)) {
        if (empty($file['name'])) {
            return ['success' => false, 'error' => 'No file uploaded.'];
        }
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            return ['success' => false, 'error' => 'Upload error code: ' . ($file['error'] ?? 1)];
        }
        $tmpPath = $file['tmp_name'];
        $originalName = $file['name'];
    } elseif (is_string($file)) {
        if (!file_exists($file)) {
            return ['success' => false, 'error' => 'File does not exist: ' . $file];
        }
        $tmpPath = $file;
        $originalName = basename($file);
    } else {
        return ['success' => false, 'error' => 'Invalid file input.'];
    }

    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowedExts = ['jpg','jpeg','png','gif','webp','svg','bmp','ico','avif','tiff','tif','heic','heif','jfif','pjpeg','pjp','apng'];

    $isImageMime = false;
    if (function_exists('finfo_open') && !empty($tmpPath) && file_exists($tmpPath)) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = finfo_file($finfo, $tmpPath);
            if (PHP_VERSION_ID < 80500) {
                finfo_close($finfo);
            }
            $isImageMime = ($mime && strpos($mime, 'image/') === 0);
        }
    }

    if (!in_array($ext, $allowedExts) && !$isImageMime) {
        return ['success' => false, 'error' => 'Invalid file type. Please upload a valid image file.'];
    }

    // 1. Try SDK if available
    if ($hasCloudinarySdk && class_exists('Cloudinary\Api\Upload\UploadApi')) {
        try {
            $uploadApi = new \Cloudinary\Api\Upload\UploadApi();
            $options = [
                'folder'          => $folder,
                'resource_type'   => 'image',
                'use_filename'    => false,
                'unique_filename' => true,
                'overwrite'       => false,
            ];

            $response = $uploadApi->upload($tmpPath, $options);

            if (!empty($response['secure_url'])) {
                return [
                    'success'   => true,
                    'url'       => $response['secure_url'],
                    'public_id' => $response['public_id'] ?? '',
                    'error'     => ''
                ];
            }
        } catch (\Throwable $e) {
            error_log("Cloudinary SDK Upload Notice (falling back to cURL): " . $e->getMessage());
        }
    }

    // 2. Resilient Native cURL Fallback (Guaranteed to work on any hosting with cURL)
    if (function_exists('curl_init')) {
        try {
            $timestamp = time();
            $paramsToSign = [
                'folder' => $folder,
                'timestamp' => $timestamp
            ];
            ksort($paramsToSign);

            $sigParts = [];
            foreach ($paramsToSign as $k => $v) {
                $sigParts[] = "$k=$v";
            }
            $toSign = implode('&', $sigParts) . $cloudinary_api_secret;
            $signature = sha1($toSign);

            $postData = [
                'file'      => new \CURLFile($tmpPath, null, $originalName),
                'folder'    => $folder,
                'timestamp' => $timestamp,
                'api_key'   => $cloudinary_api_key,
                'signature' => $signature
            ];

            $ch = curl_init("https://api.cloudinary.com/v1_1/$cloudinary_cloud_name/image/upload");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_TIMEOUT, 8);

            $respBody = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if (PHP_VERSION_ID < 80500) {
                curl_close($ch);
            }

            if ($httpCode >= 200 && $httpCode < 300 && !empty($respBody)) {
                $data = json_decode($respBody, true);
                if (!empty($data['secure_url'])) {
                    return [
                        'success'   => true,
                        'url'       => $data['secure_url'],
                        'public_id' => $data['public_id'] ?? '',
                        'error'     => ''
                    ];
                }
            }
        } catch (\Throwable $e) {
            error_log("Cloudinary upload notice: " . $e->getMessage());
        }
    }

    // 3. Resilient Local Storage Fallback (guarantees image save even if offline or Cloudinary is unreachable)
    try {
        $uploadDir = __DIR__ . '/uploads/';
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0777, true);
        }
        $newFileName = time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $targetLocal = $uploadDir . $newFileName;
        
        if (is_array($file) && !empty($file['tmp_name']) && file_exists($file['tmp_name'])) {
            if (@move_uploaded_file($file['tmp_name'], $targetLocal) || @copy($file['tmp_name'], $targetLocal)) {
                return [
                    'success'   => true,
                    'url'       => 'uploads/' . $newFileName,
                    'public_id' => $newFileName,
                    'error'     => ''
                ];
            }
        } elseif (is_string($file) && file_exists($file)) {
            if (@copy($file, $targetLocal)) {
                return [
                    'success'   => true,
                    'url'       => 'uploads/' . $newFileName,
                    'public_id' => $newFileName,
                    'error'     => ''
                ];
            }
        }
    } catch (\Throwable $e) {
        // Ignore fallback exception
    }

    return ['success' => false, 'error' => 'Failed to save image. Please check directory permissions.'];
}

/**
 * Extract public_id from a Cloudinary URL.
 * E.g., https://res.cloudinary.com/demo/image/upload/v123456/pos_coffee/products/abc.jpg -> pos_coffee/products/abc
 */
function cloudinary_extract_public_id(string $url): ?string {
    if (!str_contains($url, 'cloudinary.com')) {
        return null;
    }

    $urlPath = parse_url($url, PHP_URL_PATH);
    if (!$urlPath) return null;

    if (preg_match('#/upload/(?:v\d+/)?(.+?)(?:\.[a-zA-Z0-9]+)?$#', $urlPath, $matches)) {
        return $matches[1];
    }

    return null;
}

/**
 * Delete an image asset from Cloudinary.
 *
 * @param string $urlOrPublicId Full Cloudinary URL or public_id
 * @return bool
 */
function cloudinary_delete_image(string $urlOrPublicId): bool {
    global $cloudinary_cloud_name, $cloudinary_api_key, $cloudinary_api_secret, $hasCloudinarySdk;

    if (empty($urlOrPublicId)) return true;

    // If it's a local file, check and delete locally
    if (!str_starts_with($urlOrPublicId, 'http://') && !str_starts_with($urlOrPublicId, 'https://') && !str_starts_with($urlOrPublicId, '//')) {
        $path = $urlOrPublicId;
        if (!str_starts_with($path, 'uploads/') && file_exists('uploads/' . $path)) {
            $path = 'uploads/' . $path;
        }
        if (file_exists($path)) {
            @unlink($path);
        }
        return true;
    }

    // Only process Cloudinary URLs
    if (!str_contains($urlOrPublicId, 'cloudinary.com')) {
        return true;
    }

    $publicId = cloudinary_extract_public_id($urlOrPublicId);
    if (!$publicId) {
        $publicId = $urlOrPublicId;
    }

    // 1. Try SDK
    if ($hasCloudinarySdk && class_exists('Cloudinary\Api\Upload\UploadApi')) {
        try {
            $uploadApi = new \Cloudinary\Api\Upload\UploadApi();
            $uploadApi->destroy($publicId, ['resource_type' => 'image']);
            return true;
        } catch (\Throwable $e) {
            error_log("Cloudinary SDK Delete Notice: " . $e->getMessage());
        }
    }

    // 2. Native cURL fallback
    if (function_exists('curl_init')) {
        try {
            $timestamp = time();
            $paramsToSign = [
                'public_id' => $publicId,
                'timestamp' => $timestamp
            ];
            ksort($paramsToSign);

            $sigParts = [];
            foreach ($paramsToSign as $k => $v) {
                $sigParts[] = "$k=$v";
            }
            $toSign = implode('&', $sigParts) . $cloudinary_api_secret;
            $signature = sha1($toSign);

            $postData = [
                'public_id' => $publicId,
                'timestamp' => $timestamp,
                'api_key'   => $cloudinary_api_key,
                'signature' => $signature
            ];

            $ch = curl_init("https://api.cloudinary.com/v1_1/$cloudinary_cloud_name/image/destroy");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);

            curl_exec($ch);
            if (PHP_VERSION_ID < 80500) {
                curl_close($ch);
            }
            return true;
        } catch (\Throwable $e) {
            error_log("Cloudinary cURL Delete Exception: " . $e->getMessage());
            return false;
        }
    }

    return false;
}

/**
 * Resolve an image path to a valid web URL or fallback.
 *
 * @param string|null $image Image value from database
 * @param string $default Fallback image URL/path
 * @return string
 */
function get_image_url(?string $image, string $default = 'images/logo.png'): string {
    $img = trim((string)$image);
    if ($img === '') return $default;

    // Full remote URL (Cloudinary HTTPS, HTTP, CDN)
    if (str_starts_with($img, 'http://') || str_starts_with($img, 'https://') || str_starts_with($img, '//')) {
        return $img;
    }

    // Local uploads path
    if (str_starts_with($img, 'uploads/')) {
        return $img;
    }

    // Relative image name
    if (file_exists('uploads/' . $img)) {
        return 'uploads/' . $img;
    }

    if (file_exists($img)) {
        return $img;
    }

    return $img;
}
