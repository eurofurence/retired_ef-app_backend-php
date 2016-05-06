<?

define('ROOT_PATH', getcwd() . '/');
define('SHARED_PATH', ROOT_PATH . '../Shared/');
define('VENDOR_PATH', SHARED_PATH . './vendor/');

require SHARED_PATH . 'config.inc.php';
require VENDOR_PATH . 'autoload.php';
require VENDOR_PATH . 'sergeytsalkov/meekrodb/db.class.php';

// Stub

?>