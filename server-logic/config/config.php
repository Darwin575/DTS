<?php
// config/config.php
require_once __DIR__ . '/../../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

// Validate required environment variables
$required_env_vars = [
    'DB_HOST',
    'DB_USERNAME',
    'DB_PASSWORD',
    'DB_NAME',
    'MAIL_HOST',
    'MAIL_USERNAME',
    'MAIL_PASSWORD',
    'MAIL_PORT',
    'MAIL_FROM',
    'MAIL_FROM_NAME'
];

foreach ($required_env_vars as $var) {
    if (!isset($_ENV[$var])) {
        die("Error: Environment variable $var is not set");
    }
}
// Dynamically determine the base URL based on the server environment
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
$host = $_SERVER['HTTP_HOST'];
$project_folder = "/DTS"; // The folder name of your project in htdocs

// Construct the base URL
$base_url = $protocol . $host . $project_folder . "/";

// Export the base URL for use in other files
// return $base_url;



// Include database connection
// require_once __DIR__ . '/db.php';
