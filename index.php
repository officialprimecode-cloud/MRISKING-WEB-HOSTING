<?php
// Premium Web Hosting - HTML, CSS, JS Only
session_start();

// Generate unique user ID if not exists
if (!isset($_COOKIE['user_id'])) {
    $user_id = 'user_' . uniqid() . '_' . time();
    setcookie('user_id', $user_id, time() + (86400 * 365), "/"); // 1 year
    $_COOKIE['user_id'] = $user_id;
} else {
    $user_id = $_COOKIE['user_id'];
}

// User's directory
$user_dir = 'sites/' . $user_id;

// Handle file upload with custom URL
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Single file upload
    if (isset($_FILES['html_file']) && !empty($_FILES['html_file']['name'])) {
        $file = $_FILES['html_file'];
        $custom_url = trim($_POST['custom_url']) ?: generateCustomUrl($file['name']);
        $site_password = trim($_POST['site_password']) ?: '';
        
        if ($file['error'] === UPLOAD_ERR_OK) {
            $file_name = $file['name'];
            $file_tmp = $file['tmp_name'];
            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
            
            // Allowed file types - ONLY HTML, CSS, JS, ZIP
            $allowed_types = ['html', 'htm', 'css', 'js', 'zip'];
            
            if (in_array($file_ext, $allowed_types)) {
                if (!is_dir($user_dir)) {
                    mkdir($user_dir, 0777, true);
                }
                
                // Clean custom URL
                $custom_url = cleanCustomUrl($custom_url);
                
                if ($file_ext === 'zip') {
                    // Handle ZIP file upload
                    $result = handleZipUpload($file_tmp, $custom_url, $user_id, $site_password);
                    if ($result['success']) {
                        $website_url = getWebsiteUrl($custom_url, $user_id);
                        $_SESSION['success'] = "ZIP file extracted successfully! " . $result['message'];
                        $_SESSION['file_url'] = $website_url;
                        $_SESSION['file_name'] = $file_name;
                    } else {
                        $_SESSION['error'] = $result['message'];
                    }
                } else {
                    // Handle single file upload
                    $file_path = $user_dir . '/' . $custom_url . '.' . $file_ext;
                    $meta_path = $user_dir . '/' . $custom_url . '.json';
                    
                    // Check if URL already exists
                    if (file_exists($file_path)) {
                        $_SESSION['error'] = "This URL is already taken. Please choose a different one.";
                    } else {
                        if (move_uploaded_file($file_tmp, $file_path)) {
                            // Save metadata (password, etc.)
                            $metadata = [
                                'password' => $site_password,
                                'created' => time(),
                                'filename' => $file_name,
                                'original_name' => $custom_url
                            ];
                            file_put_contents($meta_path, json_encode($metadata));
                            
                            $website_url = getWebsiteUrl($custom_url, $user_id);
                            $_SESSION['success'] = "File uploaded successfully!";
                            $_SESSION['file_url'] = $website_url;
                            $_SESSION['file_name'] = $file_name;
                        } else {
                            $_SESSION['error'] = "Error uploading file. Check folder permissions.";
                        }
                    }
                }
            } else {
                $_SESSION['error'] = "Only HTML, CSS, JavaScript, and ZIP files are allowed.";
            }
        } else {
            $_SESSION['error'] = "File upload error. Please try again. Error code: " . $file['error'];
        }
    }
    
    // Code editor upload
    elseif (isset($_POST['html_code'])) {
        $html_code = $_POST['html_code'];
        $custom_url = trim($_POST['code_custom_url']) ?: generateCustomUrl('my-website');
        $site_password = trim($_POST['code_site_password']) ?: '';
        
        if (!empty($html_code)) {
            if (!is_dir($user_dir)) {
                mkdir($user_dir, 0777, true);
            }
            
            $custom_url = cleanCustomUrl($custom_url);
            $file_path = $user_dir . '/' . $custom_url . '.html';
            $meta_path = $user_dir . '/' . $custom_url . '.json';
            
            // Check if URL already exists
            if (file_exists($file_path)) {
                $_SESSION['error'] = "This URL is already taken. Please choose a different one.";
            } else {
                if (file_put_contents($file_path, $html_code)) {
                    // Save metadata
                    $metadata = [
                        'password' => $site_password,
                        'created' => time(),
                        'filename' => $custom_url . '.html',
                        'original_name' => $custom_url
                    ];
                    file_put_contents($meta_path, json_encode($metadata));
                    
                    $website_url = getWebsiteUrl($custom_url, $user_id);
                    $_SESSION['success'] = "Website created successfully!";
                    $_SESSION['file_url'] = $website_url;
                    $_SESSION['file_name'] = $custom_url . '.html';
                } else {
                    $_SESSION['error'] = "Error creating file. Check folder permissions.";
                }
            }
        } else {
            $_SESSION['error'] = "Please enter HTML code.";
        }
    }
    
    // Handle site deletion
    elseif (isset($_POST['delete_site'])) {
        $site_to_delete = $_POST['delete_site'];
        deleteSite($site_to_delete, $user_id);
        $_SESSION['success'] = "Site deleted successfully!";
    }
    
    // Handle site renaming
    elseif (isset($_POST['rename_site'])) {
        $old_name = $_POST['old_name'];
        $new_name = cleanCustomUrl($_POST['new_name']);
        
        if (renameSite($old_name, $new_name, $user_id)) {
            $_SESSION['success'] = "Site renamed successfully!";
        } else {
            $_SESSION['error'] = "Error renaming site. Name might be taken.";
        }
    }
    
    // Handle password update
    elseif (isset($_POST['update_password'])) {
        $site_name = $_POST['site_name'];
        $new_password = $_POST['new_password'];
        
        if (updateSitePassword($site_name, $new_password, $user_id)) {
            $_SESSION['success'] = "Password updated successfully!";
        } else {
            $_SESSION['error'] = "Error updating password.";
        }
    }
    
    header("Location: index.php");
    exit;
}

// Handle ZIP file upload and extraction
function handleZipUpload($zip_tmp, $custom_url, $user_id, $password) {
    if (!class_exists('ZipArchive')) {
        return ['success' => false, 'message' => 'ZIP extraction not supported on this server.'];
    }
    
    $zip = new ZipArchive();
    $result = ['success' => false, 'message' => ''];
    
    if ($zip->open($zip_tmp) === TRUE) {
        $extract_path = 'sites/' . $user_id . '/' . $custom_url;
        
        // Create directory for extracted files
        if (!is_dir($extract_path)) {
            mkdir($extract_path, 0777, true);
        }
        
        $allowed_extensions = ['html', 'htm', 'css', 'js', 'txt'];
        $has_html = false;
        $extracted_files = [];
        
        // Extract allowed files only
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);
            $file_ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            
            // Skip directories and non-allowed files
            if (substr($filename, -1) === '/' || !in_array($file_ext, $allowed_extensions)) {
                continue;
            }
            
            // Extract file
            $file_content = $zip->getFromIndex($i);
            $safe_filename = basename($filename);
            
            if ($file_content !== false) {
                file_put_contents($extract_path . '/' . $safe_filename, $file_content);
                $extracted_files[] = $safe_filename;
                
                if ($file_ext === 'html' || $file_ext === 'htm') {
                    $has_html = true;
                }
            }
        }
        
        $zip->close();
        
        if ($has_html) {
            // Save metadata for ZIP site
            $meta_path = 'sites/' . $user_id . '/' . $custom_url . '.json';
            $metadata = [
                'password' => $password,
                'created' => time(),
                'filename' => $custom_url,
                'original_name' => $custom_url,
                'is_zip' => true,
                'files' => $extracted_files
            ];
            file_put_contents($meta_path, json_encode($metadata));
            
            $result['success'] = true;
            $result['message'] = count($extracted_files) . " files extracted successfully.";
        } else {
            // Clean up if no HTML file found
            array_map('unlink', glob("$extract_path/*"));
            rmdir($extract_path);
            $result['message'] = "ZIP file must contain at least one HTML file.";
        }
    } else {
        $result['message'] = "Failed to open ZIP file.";
    }
    
    return $result;
}

// Generate custom URL from filename
function generateCustomUrl($filename) {
    $name = pathinfo($filename, PATHINFO_FILENAME);
    $name = preg_replace('/[^a-z0-9]/', '-', strtolower($name));
    $name = preg_replace('/-+/', '-', $name);
    $name = trim($name, '-');
    return $name ?: 'my-site';
}

// Clean custom URL
function cleanCustomUrl($url) {
    $url = preg_replace('/[^a-z0-9-]/', '', strtolower($url));
    $url = preg_replace('/-+/', '-', $url);
    $url = trim($url, '-');
    return $url ?: 'my-site';
}

// Get website full URL
function getWebsiteUrl($path, $user_id) {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $base_url = $protocol . '://' . $host . dirname($_SERVER['PHP_SELF']);
    return rtrim($base_url, '/') . '/view.php?site=' . $path . '&user=' . $user_id;
}

// Delete site
function deleteSite($site_name, $user_id) {
    $base_path = 'sites/' . $user_id . '/';
    
    // Check if it's a ZIP site (directory)
    $dir_path = $base_path . $site_name;
    if (is_dir($dir_path)) {
        // Delete all files in directory
        array_map('unlink', glob("$dir_path/*.*"));
        rmdir($dir_path);
        
        // Delete metadata
        $meta_file = $base_path . $site_name . '.json';
        if (file_exists($meta_file)) {
            unlink($meta_file);
        }
    } else {
        // Delete single file with all extensions
        $extensions = ['html', 'htm', 'css', 'js', 'json'];
        foreach ($extensions as $ext) {
            $file_path = $base_path . $site_name . '.' . $ext;
            if (file_exists($file_path)) {
                unlink($file_path);
            }
        }
    }
    
    return true;
}

// Rename site
function renameSite($old_name, $new_name, $user_id) {
    $base_path = 'sites/' . $user_id . '/';
    
    // Check if new name already exists
    $new_meta = $base_path . $new_name . '.json';
    if (file_exists($new_meta)) {
        return false;
    }
    
    // Check if it's a ZIP site
    $old_dir = $base_path . $old_name;
    if (is_dir($old_dir)) {
        $new_dir = $base_path . $new_name;
        if (!rename($old_dir, $new_dir)) {
            return false;
        }
        
        // Rename metadata file
        $old_meta = $base_path . $old_name . '.json';
        if (file_exists($old_meta)) {
            rename($old_meta, $new_meta);
            
            // Update metadata
            $metadata = json_decode(file_get_contents($new_meta), true);
            $metadata['filename'] = $new_name;
            $metadata['original_name'] = $new_name;
            file_put_contents($new_meta, json_encode($metadata));
        }
    } else {
        // Rename single file
        $extensions = ['html', 'htm', 'css', 'js'];
        $renamed = false;
        
        foreach ($extensions as $ext) {
            $old_file = $base_path . $old_name . '.' . $ext;
            if (file_exists($old_file)) {
                $new_file = $base_path . $new_name . '.' . $ext;
                if (!rename($old_file, $new_file)) {
                    return false;
                }
                $renamed = true;
                break;
            }
        }
        
        if ($renamed) {
            // Rename metadata
            $old_meta = $base_path . $old_name . '.json';
            $new_meta = $base_path . $new_name . '.json';
            if (file_exists($old_meta)) {
                rename($old_meta, $new_meta);
                
                // Update metadata
                $metadata = json_decode(file_get_contents($new_meta), true);
                $metadata['filename'] = $new_name . '.' . pathinfo($new_file, PATHINFO_EXTENSION);
                $metadata['original_name'] = $new_name;
                file_put_contents($new_meta, json_encode($metadata));
            }
        }
    }
    
    return true;
}

// Update site password
function updateSitePassword($site_name, $new_password, $user_id) {
    $meta_path = 'sites/' . $user_id . '/' . $site_name . '.json';
    
    if (file_exists($meta_path)) {
        $metadata = json_decode(file_get_contents($meta_path), true);
        $metadata['password'] = $new_password;
        file_put_contents($meta_path, json_encode($metadata));
        return true;
    }
    
    return false;
}

// Get list of uploaded sites for current user
$uploaded_sites = [];
$total_size = 0;

if (is_dir($user_dir)) {
    $files = scandir($user_dir);
    foreach ($files as $file) {
        if ($file !== '.' && $file !== '..' && !str_ends_with($file, '.json')) {
            $file_path = $user_dir . '/' . $file;
            
            if (is_dir($file_path)) {
                // Handle directory (ZIP extracted sites)
                $html_files = glob($file_path . '/*.{html,htm}', GLOB_BRACE);
                if (!empty($html_files)) {
                    $site_name = $file;
                    $meta_path = $user_dir . '/' . $site_name . '.json';
                    $metadata = file_exists($meta_path) ? json_decode(file_get_contents($meta_path), true) : [];
                    
                    $uploaded_sites[] = [
                        'name' => $site_name,
                        'filename' => $file,
                        'url' => getWebsiteUrl($site_name, $user_id),
                        'time' => filemtime($file_path),
                        'size' => getDirectorySize($file_path),
                        'type' => 'zip',
                        'is_dir' => true,
                        'has_password' => !empty($metadata['password']),
                        'metadata' => $metadata
                    ];
                    
                    $total_size += getDirectorySize($file_path);
                }
            } else {
                // Handle single files
                $file_ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                
                // Only show allowed file types
                if (in_array($file_ext, ['html', 'htm', 'css', 'js'])) {
                    $site_name = pathinfo($file, PATHINFO_FILENAME);
                    $meta_path = $user_dir . '/' . $site_name . '.json';
                    $metadata = file_exists($meta_path) ? json_decode(file_get_contents($meta_path), true) : [];
                    
                    $uploaded_sites[] = [
                        'name' => $site_name,
                        'filename' => $file,
                        'url' => getWebsiteUrl($site_name, $user_id),
                        'time' => filemtime($file_path),
                        'size' => filesize($file_path),
                        'type' => $file_ext,
                        'is_dir' => false,
                        'has_password' => !empty($metadata['password']),
                        'metadata' => $metadata
                    ];
                    
                    $total_size += filesize($file_path);
                }
            }
        }
    }
    
    // Sort by newest first
    usort($uploaded_sites, function($a, $b) {
        return $b['time'] - $a['time'];
    });
}

// Get directory size recursively
function getDirectorySize($path) {
    $total_size = 0;
    $files = scandir($path);
    
    foreach ($files as $file) {
        if ($file !== '.' && $file !== '..') {
            $file_path = $path . '/' . $file;
            if (is_file($file_path)) {
                $total_size += filesize($file_path);
            } elseif (is_dir($file_path)) {
                $total_size += getDirectorySize($file_path);
            }
        }
    }
    
    return $total_size;
}

// Convert bytes to human readable format
function formatSize($bytes) {
    if ($bytes == 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, 2) . ' ' . $units[$pow];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SMT FREE HOSTING</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Mobile First Design */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body {
            background: linear-gradient(135deg, #0f0c29, #302b63, #24243e);
            color: #fff;
            min-height: 100vh;
            padding: 15px;
            font-size: 14px;
        }
        
        .container {
            max-width: 100%;
            margin: 0 auto;
        }
        
        .header {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            padding: 20px 15px;
            border-radius: 15px;
            text-align: center;
            margin-bottom: 20px;
            position: relative;
        }
        
        .header h1 {
            font-size: 1.8rem;
            margin-bottom: 8px;
            background: linear-gradient(45deg, #00ffff, #ff00ff);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        
        .header p {
            color: #e0e0ff;
            font-size: 0.9rem;
            opacity: 0.9;
            margin-bottom: 10px;
        }
        
        .domain-example {
            background: rgba(0, 0, 0, 0.3);
            padding: 10px;
            border-radius: 8px;
            margin: 15px 0;
            font-family: monospace;
            color: #00ffff;
            font-size: 0.8rem;
            word-break: break-all;
            border: 1px solid rgba(0, 255, 255, 0.2);
        }
        
        .main-content {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        
        @media (min-width: 768px) {
            .main-content {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 25px;
            }
            .header h1 {
                font-size: 2.2rem;
            }
            body {
                font-size: 16px;
                padding: 20px;
            }
        }
        
        .card {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.2);
        }
        
        .card-title {
            font-size: 1.2rem;
            color: #00ffff;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
            padding-bottom: 10px;
            border-bottom: 1px solid rgba(0, 255, 255, 0.2);
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        label {
            display: block;
            margin-bottom: 6px;
            font-weight: 600;
            color: #e0e0ff;
            font-size: 0.9rem;
        }
        
        input, textarea, select {
            width: 100%;
            padding: 12px;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 8px;
            font-size: 0.9rem;
            color: #fff;
            transition: all 0.3s ease;
        }
        
        input:focus, textarea:focus {
            outline: none;
            border-color: #00ffff;
            box-shadow: 0 0 10px rgba(0, 255, 255, 0.3);
        }
        
        textarea {
            min-height: 180px;
            font-family: monospace;
            resize: vertical;
            font-size: 0.85rem;
        }
        
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px 16px;
            background: linear-gradient(135deg, #00ffff, #0099ff);
            color: #000;
            border: none;
            border-radius: 8px;
            font-size: 0.9rem;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 255, 255, 0.3);
        }
        
        .btn-block {
            width: 100%;
            padding: 14px;
        }
        
        .btn-small {
            padding: 6px 10px;
            font-size: 0.8rem;
        }
        
        .btn-success {
            background: linear-gradient(135deg, #00ff88, #00cc66);
        }
        
        .btn-danger {
            background: linear-gradient(135deg, #ff3366, #cc0033);
        }
        
        .btn-warning {
            background: linear-gradient(135deg, #ffaa00, #ff7700);
        }
        
        .btn-telegram {
            background: linear-gradient(135deg, #0088cc, #006699);
        }
        
        .btn-whatsapp {
            background: linear-gradient(135deg, #25D366, #128C7E);
        }
        
        .password-input-group {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        
        .password-input-group input {
            flex: 1;
        }
        
        .password-toggle {
            background: transparent;
            border: none;
            color: #00ffff;
            cursor: pointer;
            font-size: 1.2rem;
            padding: 5px;
        }
        
        .site-list {
            max-height: 400px;
            overflow-y: auto;
        }
        
        .site-item {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 10px;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .site-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        
        .site-name {
            font-weight: bold;
            color: #fff;
            font-size: 1rem;
            word-break: break-all;
        }
        
        .site-meta {
            color: #b0b0ff;
            font-size: 0.8rem;
            display: flex;
            gap: 10px;
            margin-bottom: 8px;
        }
        
        .site-url {
            color: #00ffff;
            font-size: 0.8rem;
            word-break: break-all;
            margin-bottom: 10px;
            padding: 8px;
            background: rgba(0, 0, 0, 0.2);
            border-radius: 5px;
        }
        
        .site-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 10px;
        }
        
        .site-actions .btn {
            flex: 1;
            min-width: 100px;
        }
        
        .password-badge {
            display: inline-block;
            padding: 3px 8px;
            background: rgba(255, 0, 85, 0.2);
            color: #ff0055;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: bold;
            margin-left: 8px;
        }
        
        .message {
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 15px;
            text-align: center;
            border: 1px solid;
        }
        
        .success {
            background: rgba(0, 255, 136, 0.1);
            color: #00ff88;
            border-color: rgba(0, 255, 136, 0.3);
        }
        
        .error {
            background: rgba(255, 0, 85, 0.1);
            color: #ff0055;
            border-color: rgba(255, 0, 85, 0.3);
        }
        
        footer {
            text-align: center;
            color: #fff;
            margin-top: 30px;
            padding: 20px;
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .footer-content {
            display: flex;
            flex-direction: column;
            gap: 15px;
            align-items: center;
            margin-bottom: 15px;
        }
        
        @media (min-width: 768px) {
            .footer-content {
                flex-direction: row;
                justify-content: space-between;
            }
        }
        
        .developer {
            font-weight: 700;
            font-size: 1rem;
            background: linear-gradient(45deg, #00ffff, #ff00ff);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        
        .channel-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            justify-content: center;
        }
        
        .tabs {
            display: flex;
            margin-bottom: 15px;
            border-bottom: 2px solid rgba(255, 255, 255, 0.1);
        }
        
        .tab {
            flex: 1;
            padding: 12px;
            cursor: pointer;
            text-align: center;
            background: rgba(255, 255, 255, 0.05);
            color: #b0b0ff;
            font-size: 0.9rem;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .tab.active {
            background: rgba(0, 255, 255, 0.15);
            color: #00ffff;
            border-bottom: 3px solid #00ffff;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .file-input {
            padding: 25px;
            border: 2px dashed rgba(0, 255, 255, 0.3);
            border-radius: 10px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-bottom: 15px;
            background: rgba(0, 0, 0, 0.2);
        }
        
        .preview-container {
            border: 1px solid rgba(0, 255, 255, 0.2);
            border-radius: 10px;
            padding: 15px;
            margin-top: 15px;
            background: rgba(0, 0, 0, 0.2);
        }
        
        .preview-iframe {
            width: 100%;
            height: 300px;
            border: none;
            border-radius: 5px;
            background: white;
        }
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .modal-content {
            background: rgba(30, 30, 60, 0.95);
            padding: 25px;
            border-radius: 15px;
            max-width: 500px;
            width: 100%;
            border: 1px solid rgba(0, 255, 255, 0.3);
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .modal-title {
            color: #00ffff;
            font-size: 1.2rem;
        }
        
        .close-modal {
            background: none;
            border: none;
            color: #ff0055;
            font-size: 1.5rem;
            cursor: pointer;
        }
        
        .modal-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
        }
        
        .stats {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-top: 15px;
        }
        
        .stat-card {
            background: linear-gradient(135deg, rgba(0, 255, 255, 0.2), rgba(155, 0, 255, 0.2));
            color: white;
            padding: 15px;
            border-radius: 10px;
            text-align: center;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .stat-number {
            font-size: 1.4rem;
            font-weight: bold;
            color: #00ffff;
        }
        
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #b0b0ff;
        }
        
        .empty-state i {
            font-size: 3rem;
            color: #00ffff;
            opacity: 0.5;
            margin-bottom: 15px;
        }
        
        /* Touch-friendly sizes */
        @media (max-width: 480px) {
            .btn {
                padding: 14px;
                font-size: 0.85rem;
            }
            
            .site-actions .btn {
                min-width: 80px;
            }
            
            .card {
                padding: 15px;
            }
            
            .header {
                padding: 15px 10px;
            }
            
            .header h1 {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="notification" id="notification"></div>

    <!-- Modals -->
    <div class="modal" id="editModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Edit Site</h3>
                <button class="close-modal" onclick="closeModal('editModal')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" id="editSiteName" name="old_name">
                <div class="form-group">
                    <label>New Site Name</label>
                    <input type="text" id="newSiteName" name="new_name" required>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-danger" onclick="closeModal('editModal')">Cancel</button>
                    <button type="submit" name="rename_site" class="btn btn-success">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal" id="passwordModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Set Password</h3>
                <button class="close-modal" onclick="closeModal('passwordModal')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" id="passwordSiteName" name="site_name">
                <div class="form-group">
                    <label>Password (Leave empty for no password)</label>
                    <div class="password-input-group">
                        <input type="password" id="sitePassword" name="new_password" placeholder="Optional password">
                        <button type="button" class="password-toggle" onclick="togglePassword('sitePassword')">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-danger" onclick="closeModal('passwordModal')">Cancel</button>
                    <button type="submit" name="update_password" class="btn btn-success">Set Password</button>
                </div>
            </form>
        </div>
    </div>

    <div class="container">
        <div class="header">
            <h1>SMT FREE HOSTING</h1>
            <p>Mobile Friendly • Password Protected • Your Files Only</p>
            
            <div class="domain-example">
                Your User ID: <strong><?php echo substr($user_id, 0, 15); ?>...</strong>
            </div>
        </div>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="message success">
                ✅ <?php echo $_SESSION['success']; ?>
                <?php 
                unset($_SESSION['success']);
                unset($_SESSION['error']);
                ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="message error">
                ❌ <?php echo $_SESSION['error']; ?>
                <?php 
                unset($_SESSION['error']);
                ?>
            </div>
        <?php endif; ?>

        <div class="main-content">
            <!-- Left Column - Upload -->
            <div>
                <div class="card">
                    <h2 class="card-title"><i class="fas fa-cloud-upload-alt"></i> UPLOAD FILES</h2>
                    
                    <div class="tabs">
                        <div class="tab active" onclick="switchTab('single-tab')">Upload File</div>
                        <div class="tab" onclick="switchTab('code-tab')">Code Editor</div>
                        <div class="tab" onclick="switchTab('preview-tab')">Live Preview</div>
                    </div>
                    
                    <!-- Single File Upload Tab -->
                    <div class="tab-content active" id="single-tab">
                        <form method="POST" enctype="multipart/form-data" id="upload-form">
                            <div class="form-group">
                                <label>Website Name</label>
                                <input type="text" name="custom_url" placeholder="my-site" id="single-url" required>
                                <div class="domain-example" style="margin: 10px 0; padding: 8px; font-size: 0.8rem;">
                                    URL: view.php?site=<span id="single-preview-url">my-site</span>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label>Password Protection (Optional)</label>
                                <div class="password-input-group">
                                    <input type="password" name="site_password" placeholder="Leave empty for no password">
                                    <button type="button" class="password-toggle" onclick="togglePassword(this.previousElementSibling)">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label>Upload File</label>
                                <div class="file-input" onclick="document.getElementById('html_file').click()">
                                    <i class="fas fa-cloud-upload-alt"></i>
                                    <p>Click to Upload</p>
                                    <small>HTML, CSS, JS, ZIP files</small>
                                </div>
                                <input type="file" id="html_file" name="html_file" accept=".html,.htm,.css,.js,.zip" style="display: none;" required>
                                <div id="file-name" style="margin-top: 10px; color: #00ffff; text-align: center; font-size: 0.85rem;"></div>
                            </div>
                            
                            <button type="submit" class="btn btn-block">
                                <i class="fas fa-rocket"></i> Upload & Host
                            </button>
                        </form>
                    </div>
                    
                    <!-- Code Editor Tab -->
                    <div class="tab-content" id="code-tab">
                        <form method="POST">
                            <div class="form-group">
                                <label>Website Name</label>
                                <input type="text" name="code_custom_url" placeholder="my-website" id="code-url" required>
                                <div class="domain-example" style="margin: 10px 0; padding: 8px; font-size: 0.8rem;">
                                    URL: view.php?site=<span id="code-preview-url">my-website</span>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label>Password Protection (Optional)</label>
                                <div class="password-input-group">
                                    <input type="password" name="code_site_password" placeholder="Leave empty for no password">
                                    <button type="button" class="password-toggle" onclick="togglePassword(this.previousElementSibling)">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label>HTML Code</label>
                                <textarea name="html_code" id="code-editor" placeholder="Paste your HTML code here..." required oninput="updatePreview()"></textarea>
                            </div>
                            
                            <button type="submit" class="btn btn-block">
                                <i class="fas fa-bolt"></i> Create Website
                            </button>
                        </form>
                    </div>
                    
                    <!-- Live Preview Tab -->
                    <div class="tab-content" id="preview-tab">
                        <div class="preview-container">
                            <h4 style="color: #00ffff; margin-bottom: 10px;">Live Preview</h4>
                            <iframe class="preview-iframe" id="live-preview"></iframe>
                        </div>
                        <div style="text-align: center; margin-top: 15px; color: #b0b0ff; font-size: 0.9rem;">
                            <i class="fas fa-info-circle"></i> Type code in editor tab to see preview
                        </div>
                    </div>
                </div>

                <div class="stats">
                    <div class="stat-card">
                        <div class="stat-number"><?php echo count($uploaded_sites); ?></div>
                        <div class="stat-label">Your Sites</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo formatSize($total_size); ?></div>
                        <div class="stat-label">Storage Used</div>
                    </div>
                </div>
            </div>

            <!-- Right Column - Sites List -->
            <div>
                <div class="card">
                    <h2 class="card-title"><i class="fas fa-server"></i> YOUR SITES</h2>
                    
                    <div class="site-list">
                        <?php if (!empty($uploaded_sites)): ?>
                            <?php foreach($uploaded_sites as $site): ?>
                                <div class="site-item">
                                    <div class="site-header">
                                        <div class="site-name">
                                            <?php echo $site['name']; ?>
                                            <?php if ($site['has_password']): ?>
                                                <span class="password-badge">🔒</span>
                                            <?php endif; ?>
                                            <?php if ($site['is_dir']): ?>
                                                <span style="color: #00ffff; font-size: 0.7rem;">(ZIP)</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <div class="site-meta">
                                        <span><i class="fas fa-database"></i> <?php echo formatSize($site['size']); ?></span>
                                        <span><i class="fas fa-calendar"></i> <?php echo date('M j, Y', $site['time']); ?></span>
                                        <span style="color: <?php echo $site['type'] === 'html' ? '#e34c26' : ($site['type'] === 'css' ? '#264de4' : ($site['type'] === 'js' ? '#f7df1e' : '#6c757d')); ?>">
                                            .<?php echo strtoupper($site['type']); ?>
                                        </span>
                                    </div>
                                    
                                    <div class="site-url">
                                        <?php echo $site['url']; ?>
                                    </div>
                                    
                                    <div class="site-actions">
                                        <a href="<?php echo $site['url']; ?>" target="_blank" class="btn btn-success">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                        <button onclick="copyToClipboard('<?php echo $site['url']; ?>')" class="btn">
                                            <i class="fas fa-copy"></i> Copy
                                        </button>
                                        <button onclick="openEditModal('<?php echo $site['name']; ?>')" class="btn btn-warning">
                                            <i class="fas fa-edit"></i> Edit
                                        </button>
                                        <button onclick="openPasswordModal('<?php echo $site['name']; ?>')" class="btn">
                                            <i class="fas fa-lock"></i> Password
                                        </button>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="delete_site" value="<?php echo $site['name']; ?>">
                                            <button type="submit" class="btn btn-danger" onclick="return confirm('Delete this site?')">
                                                <i class="fas fa-trash"></i> Delete
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-cloud-upload-alt"></i>
                                <h3>No Websites Yet</h3>
                                <p>Upload your first file to get started!</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <footer>
            <div class="footer-content">
                <div class="developer">DEVELOPED BY MR IS KING--</div>
                <div class="channel-buttons">
                    <a href="https://whatsapp.com/channel/0029Vb7U3cL0bIdwdarxzz1l" target="_blank" class="btn btn-whatsapp btn-small">
                        <i class="fab fa-whatsapp"></i> WhatsApp
                    </a>
                    <a href="https://t.me/MRISKING12" target="_blank" class="btn btn-telegram btn-small">
                        <i class="fab fa-telegram"></i> Telegram
                    </a>
                </div>
            </div>
            <p style="margin-top: 10px; font-size: 0.8rem; color: #b0b0ff;">
                2023 © SpiDer Man TECH • All Rights Reserved 
            </p>
        </footer>
    </div>

    <script>
        // Tab switching
        function switchTab(tabId) {
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            document.getElementById(tabId).classList.add('active');
            event.target.classList.add('active');
            
            // Update preview if switching to preview tab
            if (tabId === 'preview-tab') {
                updatePreview();
            }
        }

        // URL preview update
        function updateUrlPreview(inputId, previewId) {
            const input = document.getElementById(inputId);
            const preview = document.getElementById(previewId);
            
            input.addEventListener('input', function() {
                let url = this.value.trim().toLowerCase();
                url = url.replace(/[^a-z0-9-]/g, '-');
                url = url.replace(/-+/g, '-');
                url = url.replace(/^-|-$/g, '');
                
                if (url === '') {
                    url = 'my-site';
                }
                
                preview.textContent = url;
            });
        }

        // Initialize URL previews
        updateUrlPreview('single-url', 'single-preview-url');
        updateUrlPreview('code-url', 'code-preview-url');

        // File input display
        document.getElementById('html_file').addEventListener('change', function(e) {
            const fileName = document.getElementById('file-name');
            if (this.files.length > 0) {
                const file = this.files[0];
                const fileExt = file.name.split('.').pop().toLowerCase();
                const allowedExt = ['html', 'htm', 'css', 'js', 'zip'];
                
                if (!allowedExt.includes(fileExt)) {
                    fileName.innerHTML = '<span style="color: #ff0055;"><i class="fas fa-exclamation-circle"></i> Invalid file type</span>';
                    this.value = '';
                    return;
                }
                
                const fileSize = (file.size / 1024 / 1024).toFixed(2);
                fileName.innerHTML = `<i class="fas fa-file"></i> ${file.name} (${fileSize} MB)`;
                
                // Auto-fill URL from filename
                const filename = file.name.replace(/\.[^/.]+$/, "");
                document.getElementById('single-url').value = filename.toLowerCase().replace(/[^a-z0-9]/g, '-');
                document.getElementById('single-preview-url').textContent = filename.toLowerCase().replace(/[^a-z0-9]/g, '-');
            } else {
                fileName.innerHTML = '';
            }
        });

        // Copy to clipboard
        function copyToClipboard(text) {
            navigator.clipboard.writeText(text).then(() => {
                showNotification('✅ URL copied to clipboard!');
            }).catch(err => {
                showNotification('❌ Failed to copy');
            });
        }

        // Show notification
        function showNotification(message) {
            const notification = document.getElementById('notification');
            notification.textContent = message;
            notification.className = 'notification';
            notification.style.display = 'block';
            
            setTimeout(() => {
                notification.style.display = 'none';
            }, 2000);
        }

        // Toggle password visibility
        function togglePassword(input) {
            const passwordInput = typeof input === 'string' ? document.getElementById(input) : input;
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
        }

        // Modal functions
        function openEditModal(siteName) {
            document.getElementById('editSiteName').value = siteName;
            document.getElementById('newSiteName').value = siteName;
            document.getElementById('editModal').style.display = 'flex';
        }

        function openPasswordModal(siteName) {
            document.getElementById('passwordSiteName').value = siteName;
            document.getElementById('passwordModal').style.display = 'flex';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        // Live preview update
        function updatePreview() {
            const code = document.getElementById('code-editor').value;
            const preview = document.getElementById('live-preview');
            const previewDoc = preview.contentDocument || preview.contentWindow.document;
            
            previewDoc.open();
            previewDoc.write(`
                <!DOCTYPE html>
                <html>
                <head>
                    <style>
                        body { margin: 0; padding: 20px; font-family: Arial; }
                        ${code.includes('</style>') ? '' : code}
                    </style>
                </head>
                <body>
                    ${code.includes('</body>') ? code : code + '<div>Live Preview</div>'}
                </body>
                </html>
            `);
            previewDoc.close();
        }

        // Initialize code editor with default content
        document.addEventListener('DOMContentLoaded', function() {
            const codeEditor = document.getElementById('code-editor');
            if (codeEditor && !codeEditor.value) {
                codeEditor.value = `<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Website</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            text-align: center;
            padding: 50px;
        }
        h1 {
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <h1>Welcome to My Website!</h1>
    <p>Hosted with SMT FREE HOSTING</p>
</body>
</html>`;
                updatePreview();
            }
            
            // Close modals on outside click
            window.onclick = function(event) {
                if (event.target.className === 'modal') {
                    event.target.style.display = 'none';
                }
            }
        });

        // Form validation
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function(e) {
                const urlInput = this.querySelector('input[type="text"]');
                if (urlInput) {
                    let url = urlInput.value.trim().toLowerCase();
                    url = url.replace(/[^a-z0-9-]/g, '-');
                    url = url.replace(/-+/g, '-');
                    url = url.replace(/^-|-$/g, '');
                    urlInput.value = url || 'my-site';
                }
                
                // Show loading
                const submitBtn = this.querySelector('button[type="submit"]');
                if (submitBtn) {
                    const originalText = submitBtn.innerHTML;
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
                    submitBtn.disabled = true;
                    
                    setTimeout(() => {
                        submitBtn.innerHTML = originalText;
                        submitBtn.disabled = false;
                    }, 3000);
                }
            });
        });
    </script>
</body>
</html>