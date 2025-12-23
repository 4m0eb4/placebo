<?php
require 'db_config.php';

try {
    echo "Attempting to update database...<br>";
    
    // Add is_pinned column
    try {
        $pdo->exec("ALTER TABLE posts ADD COLUMN is_pinned TINYINT(1) DEFAULT 0");
        echo "Successfully added 'is_pinned'.<br>";
    } catch (Exception $e) {
        echo "'is_pinned' likely already exists.<br>";
    }

    // Add pin_weight column
    try {
        $pdo->exec("ALTER TABLE posts ADD COLUMN pin_weight INT DEFAULT 0");
        echo "Successfully added 'pin_weight'.<br>";
    } catch (Exception $e) {
        echo "'pin_weight' likely already exists.<br>";
    }

    echo "<strong>DATABASE UPDATE COMPLETE. You may now delete this file.</strong>";
    
} catch (PDOException $e) {
    die("DB Error: " . $e->getMessage());
}
?>