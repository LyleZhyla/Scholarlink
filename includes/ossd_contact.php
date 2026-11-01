<?php
// ✨ OSSD CONTACT DETAILS (landing page footer, Data Privacy Notice)
// From the office's Facebook page. Change them here only.
if (!defined('OSSD_OFFICE')) {
    define('OSSD_OFFICE', 'TAU - Financial and Educational Assistance Services');
    define('OSSD_UNIT', 'Office of Student Services and Development (OSSD)');
    define('OSSD_ADDRESS', 'Tarlac Agricultural University, Malacampa, Camiling, Tarlac, Philippines 2306');
    define('OSSD_MAP_URL', 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode('Tarlac Agricultural University, Malacampa, Camiling, Tarlac'));
    define('OSSD_FACEBOOK', 'https://www.facebook.com/TAUIPSU');
    define('OSSD_MESSENGER', 'https://m.me/TAUIPSU');
    // Office hours, Philippine time: ISO weekday (1 = Monday) => [opens, closes]; days not listed are closed
    define('OSSD_HOURS', [1 => ['08:00', '17:00'], 2 => ['08:00', '17:00'], 3 => ['08:00', '17:00'], 4 => ['08:00', '17:00'], 5 => ['08:00', '17:00']]);
}
