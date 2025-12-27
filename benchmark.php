<?php
// benchmark.php - Server Performance Test (CPU & Disk)
// NO JAVASCRIPT - Pure PHP server-side metrics
session_start();
header('Content-Type: text/html; charset=utf-8');

$start_global = microtime(true);

// --- TEST 1: CPU (Math Crunching) ---
$math_start = microtime(true);
$math_loops = 2000000; // 2 Million Calculations
$t = 0;
for($i = 0; $i < $math_loops; $i++) {
    $t += sqrt($i * 1.5) + pow($i, 0.5);
}
$math_end = microtime(true);
$math_time = number_format(($math_end - $math_start), 4);


// --- TEST 2: DISK I/O (Write Speed) ---
// Writes a 5MB file and then deletes it
$disk_start = microtime(true);
$temp_file = 'io_test_' . uniqid() . '.tmp';
$data_chunk = str_repeat("0123456789", 1024); // 10KB Chunk
$fp = fopen($temp_file, 'w');
for($j=0; $j < 500; $j++) { // 500 * 10KB = ~5MB
    fwrite($fp, $data_chunk);
}
fclose($fp);
$disk_end = microtime(true);
$disk_time = number_format(($disk_end - $disk_start), 4);

// Cleanup
@unlink($temp_file);

// --- TOTAL EXECUTION TIME ---
$total_time = number_format((microtime(true) - $start_global), 4);

// --- INTERPRETATION ---
$cpu_status = ($math_time < 0.200) ? "FAST" : (($math_time < 0.500) ? "OKAY" : "SLOW");
$disk_status = ($disk_time < 0.100) ? "FAST (SSD/NVMe)" : (($disk_time < 0.300) ? "OKAY" : "SLOW (HDD/Overload)");

?>
<!DOCTYPE html>
<html>
<head>
    <title>Server Status</title>
    <style>
        body { background: #0d0d0d; color: #ccc; font-family: monospace; display: flex; align-items: center; justify-content: center; height: 100vh; margin:0; }
        .box { border: 1px solid #333; padding: 20px; width: 400px; background: #161616; }
        h2 { border-bottom: 1px solid #333; padding-bottom: 10px; margin-top: 0; color: #fff; }
        .row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px dashed #222; }
        .val { font-weight: bold; }
        .fast { color: #98c379; }
        .okay { color: #e5c07b; }
        .slow { color: #e06c75; }
    </style>
</head>
<body>
    <div class="box">
        <h2>SYSTEM DIAGNOSTICS</h2>
        
        <div class="row">
            <span>CPU TEST (2M Ops):</span>
            <span class="val <?= strtolower(explode(' ', $cpu_status)[0]) ?>"><?= $math_time ?>s</span>
        </div>
        <div class="row">
            <span>STATUS:</span>
            <span class="val <?= strtolower(explode(' ', $cpu_status)[0]) ?>"><?= $cpu_status ?></span>
        </div>
        <br>
        
        <div class="row">
            <span>DISK TEST (5MB Write):</span>
            <span class="val <?= strtolower(explode(' ', $disk_status)[0]) ?>"><?= $disk_time ?>s</span>
        </div>
        <div class="row">
            <span>STATUS:</span>
            <span class="val <?= strtolower(explode(' ', $disk_status)[0]) ?>"><?= $disk_status ?></span>
        </div>
        
        <br>
        <div class="row" style="border:none; border-top: 1px solid #333; padding-top:15px;">
            <span>TOTAL SERVER TIME:</span>
            <span style="color:#fff;"><?= $total_time ?>s</span>
        </div>
        
        <div style="font-size:0.7rem; color:#555; margin-top:15px;">
            * If "Total Server Time" is <strong>under 0.5s</strong> but the page took 5 seconds to load in Tor Browser, the lag is 100% the Tor Network, not your server.
        </div>
    </div>
</body>
</html>