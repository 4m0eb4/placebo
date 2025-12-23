<?php
require 'db_config.php';

try {
    // Attempt to add the missing 'guest_color' column
    // We use VARCHAR(255) to allow for potential future styling, defaulting to Grey.
    $pdo->exec("ALTER TABLE guest_tokens ADD COLUMN guest_color VARCHAR(255) DEFAULT '#888888'");
    
    echo "<div style='color:green; font-family:monospace;'>SUCCESS: 'guest_color' column added to guest_tokens table.</div>";

} catch (PDOException $e) {
    // If it fails (likely because it already exists from a previous run), show the message
    echo "<div style='color:red; font-family:monospace;'>DB STATUS: " . htmlspecialchars($e->getMessage()) . "</div>";
}
?>