<?php
// view.php - Serve hosted HTML, CSS, JS files with password protection
session_start();

// Get requested site name and user ID
$site_name = isset($_GET['site']) ? trim($_GET['site']) : '';
$user_id = isset($_GET['user']) ? trim($_GET['user']) : '';

if (empty($site_name) || empty($user_id)) {
    showError("Invalid request parameters");
    exit;
}

// Security check - prevent directory traversal
if (strpos($site_name, '..') !== false || strpos($site_name, '/') !== false || 
    strpos($site_name, '\\') !== false || strpos($user_id, '..') !== false ||
    strpos($user_id, '/') !== false || strpos($user_id, '\\') !== false) {
    http_response_code(403);
    showError("Access forbidden - Invalid parameters");
    exit;
}

// Check if password is required and submitted
$meta_path = 'sites/' . $user_id . '/' . $site_name . '.json';
$requires_password = false;
$password_correct = false;

if (file_exists($meta_path)) {
    $metadata = json_decode(file_get_contents($meta_path), true);
    $requires_password = !empty($metadata['password']);
    
    // Check if password was submitted
    if ($requires_password && isset($_POST['site_password'])) {
        if ($_POST['site_password'] === $metadata['password']) {
            $password_correct = true;
            $_SESSION['site_auth_' . $user_id . '_' . $site_name] = true;
        }
    } elseif ($requires_password && isset($_SESSION['site_auth_' . $user_id . '_' . $site_name])) {
        $password_correct = true;
    }
}

// Show password form if required and not correct
if ($requires_password && !$password_correct) {
    showPasswordForm($site_name, $user_id);
    exit;
}

// Look for the site file
$base_path = 'sites/' . $user_id . '/';
$file_path = null;
$is_zip_site = false;

// First check if it's a directory (ZIP extracted site)
$dir_path = $base_path . $site_name;
if (is_dir($dir_path)) {
    // Look for HTML files in the directory
    $html_files = glob($dir_path . '/*.{html,htm}', GLOB_BRACE);
    if (!empty($html_files)) {
        $file_path = $html_files[0];
        $is_zip_site = true;
    }
} else {
    // Look for single files with different extensions
    $extensions = ['html', 'htm', 'css', 'js'];
    foreach ($extensions as $ext) {
        $possible_path = $base_path . $site_name . '.' . $ext;
        if (file_exists($possible_path)) {
            $file_path = $possible_path;
            break;
        }
    }
}

// Check if file exists
if (!$file_path || !file_exists($file_path)) {
    http_response_code(404);
    showError("Site not found - '$site_name' doesn't exist or has been removed");
    exit;
}

// Get file extension and set content type
$file_ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
$content_types = [
    'html' => 'text/html; charset=UTF-8',
    'htm' => 'text/html; charset=UTF-8',
    'css' => 'text/css; charset=UTF-8',
    'js' => 'application/javascript; charset=UTF-8'
];

if (isset($content_types[$file_ext])) {
    header('Content-Type: ' . $content_types[$file_ext]);
} else {
    header('Content-Type: text/plain; charset=UTF-8');
}

// Add security headers
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("X-XSS-Protection: 1; mode=block");

// Output file content
readfile($file_path);
exit;

function showPasswordForm($site_name, $user_id) {
    echo "<!DOCTYPE html>
    <html>
    <head>
        <title>⚡ Password Required - HOWLER HOSTING</title>
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            }
            body { 
                background: linear-gradient(135deg, #0f0c29, #302b63, #24243e);
                color: white;
                min-height: 100vh;
                display: flex;
                justify-content: center;
                align-items: center;
                padding: 20px;
            }
            .password-container {
                max-width: 400px;
                background: rgba(255, 255, 255, 0.05);
                backdrop-filter: blur(10px);
                padding: 40px;
                border-radius: 20px;
                border: 1px solid rgba(255, 255, 255, 0.1);
                box-shadow: 0 20px 50px rgba(0, 0, 0, 0.3);
                text-align: center;
            }
            h1 { 
                font-size: 1.8rem;
                margin-bottom: 10px;
                background: linear-gradient(45deg, #00ffff, #ff00ff);
                -webkit-background-clip: text;
                -webkit-text-fill-color: transparent;
            }
            p {
                font-size: 1rem;
                line-height: 1.6;
                margin-bottom: 25px;
                color: #e0e0ff;
                opacity: 0.9;
            }
            .site-info {
                background: rgba(0, 0, 0, 0.2);
                padding: 15px;
                border-radius: 10px;
                margin-bottom: 20px;
                border: 1px solid rgba(0, 255, 255, 0.2);
            }
            .site-info strong {
                color: #00ffff;
            }
            form {
                margin-top: 20px;
            }
            .form-group {
                margin-bottom: 20px;
            }
            label {
                display: block;
                margin-bottom: 8px;
                font-weight: 600;
                color: #e0e0ff;
                text-align: left;
            }
            input[type='password'] {
                width: 100%;
                padding: 12px;
                background: rgba(255, 255, 255, 0.1);
                border: 1px solid rgba(255, 255, 255, 0.2);
                border-radius: 8px;
                font-size: 1rem;
                color: #fff;
                transition: all 0.3s ease;
            }
            input[type='password']:focus {
                outline: none;
                border-color: #00ffff;
                box-shadow: 0 0 10px rgba(0, 255, 255, 0.3);
            }
            .btn {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 10px;
                width: 100%;
                padding: 14px;
                background: linear-gradient(135deg, #00ffff, #0099ff);
                color: #000;
                border: none;
                border-radius: 10px;
                font-size: 1rem;
                font-weight: 700;
                cursor: pointer;
                transition: all 0.3s ease;
                text-transform: uppercase;
                letter-spacing: 1px;
            }
            .btn:hover {
                transform: translateY(-3px);
                box-shadow: 0 10px 25px rgba(0, 255, 255, 0.4);
            }
            .error-message {
                background: rgba(255, 0, 85, 0.1);
                color: #ff0055;
                padding: 10px;
                border-radius: 8px;
                margin-bottom: 15px;
                border: 1px solid rgba(255, 0, 85, 0.3);
                display: none;
            }
            .lock-icon {
                font-size: 3rem;
                color: #00ffff;
                margin-bottom: 15px;
                opacity: 0.7;
            }
            .back-btn {
                display: inline-block;
                margin-top: 15px;
                color: #b0b0ff;
                text-decoration: none;
                font-size: 0.9rem;
            }
            .back-btn:hover {
                color: #00ffff;
            }
        </style>
        <link rel=\"stylesheet\" href=\"https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css\">
    </head>
    <body>
        <div class='password-container'>
            <div class='lock-icon'>
                <i class='fas fa-lock'></i>
            </div>
            <h1>🔒 Password Required</h1>
            <p>This website is protected with a password</p>
            
            <div class='site-info'>
                <strong>Website:</strong> $site_name<br>
                <small>Enter the password to access this site</small>
            </div>
            
            " . (isset($_POST['site_password']) ? "<div class='error-message' style='display: block;'>
                <i class='fas fa-exclamation-circle'></i> Incorrect password. Please try again.
            </div>" : "") . "
            
            <form method='POST'>
                <div class='form-group'>
                    <label><i class='fas fa-key'></i> Enter Password</label>
                    <input type='password' name='site_password' placeholder='Enter site password' required autofocus>
                </div>
                <button type='submit' class='btn'>
                    <i class='fas fa-unlock'></i> Access Website
                </button>
            </form>
            
            <a href='javascript:history.back()' class='back-btn'>
                <i class='fas fa-arrow-left'></i> Go Back
            </a>
        </div>
        
        <script>
            // Auto-focus password field
            document.querySelector('input[name=\"site_password\"]').focus();
            
            // Clear error on new input
            document.querySelector('input[name=\"site_password\"]').addEventListener('input', function() {
                document.querySelector('.error-message').style.display = 'none';
            });
        </script>
    </body>
    </html>";
}

function showError($message) {
    echo "<!DOCTYPE html>
    <html>
    <head>
        <title>⚡ HOWLER HOSTING - Error</title>
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            }
            body { 
                background: linear-gradient(135deg, #0f0c29, #302b63, #24243e);
                color: white;
                min-height: 100vh;
                display: flex;
                justify-content: center;
                align-items: center;
                padding: 20px;
            }
            .error-container {
                max-width: 500px;
                background: rgba(255, 255, 255, 0.05);
                backdrop-filter: blur(10px);
                padding: 40px;
                border-radius: 20px;
                border: 1px solid rgba(255, 255, 255, 0.1);
                box-shadow: 0 20px 50px rgba(0, 0, 0, 0.3);
                text-align: center;
            }
            h1 { 
                font-size: 2rem;
                margin-bottom: 20px;
                background: linear-gradient(45deg, #ff0055, #ff5500);
                -webkit-background-clip: text;
                -webkit-text-fill-color: transparent;
            }
            p {
                font-size: 1.1rem;
                line-height: 1.6;
                margin-bottom: 30px;
                color: #e0e0ff;
                opacity: 0.9;
            }
            .btn {
                display: inline-flex;
                align-items: center;
                gap: 10px;
                background: linear-gradient(135deg, #00ffff, #0099ff);
                color: #000;
                padding: 15px 30px;
                text-decoration: none;
                border-radius: 10px;
                font-weight: bold;
                transition: all 0.3s ease;
            }
            .btn:hover {
                transform: translateY(-3px);
                box-shadow: 0 10px 25px rgba(0, 255, 255, 0.4);
            }
            .icon {
                font-size: 3rem;
                color: #ff0055;
                margin-bottom: 20px;
                opacity: 0.7;
            }
        </style>
        <link rel=\"stylesheet\" href=\"https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css\">
    </head>
    <body>
        <div class='error-container'>
            <div class='icon'>
                <i class='fas fa-exclamation-triangle'></i>
            </div>
            <h1>⚡ ERROR</h1>
            <p>$message</p>
            <a href='index.php' class='btn'>
                <i class='fas fa-home'></i> Back to Hosting
            </a>
        </div>
    </body>
    </html>";
}
?>