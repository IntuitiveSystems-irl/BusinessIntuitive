<?php
// Quick read-only check of the tracker DB. Run on server: php8.3 /tmp/intel-verify.php
$path = '/var/www/geometric/data/intelligence-tracker.db';
if (!file_exists($path)) { echo "DB MISSING: $path\n"; exit; }
$db = new SQLite3($path);
$e = $db->querySingle('SELECT COUNT(*) FROM events');
$s = $db->querySingle('SELECT COUNT(*) FROM sessions');
echo "events=$e  sessions=$s\n";
$r = $db->query('SELECT id,city,region,country,isp,page,ip,created_at FROM events ORDER BY id DESC LIMIT 3');
while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
    $loc = trim(implode(', ', array_filter([$row['city'], $row['region'], $row['country']])));
    echo "#{$row['id']} | {$loc} | {$row['isp']} | {$row['page']} | {$row['ip']} | {$row['created_at']}\n";
}
