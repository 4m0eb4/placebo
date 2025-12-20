<?php
// db_fix_links.php
require 'db_config.php';

try {
    echo "Attempting to create Link Categories...<br>";
    
    // 1. Create Categories Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS link_categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(50) NOT NULL,
        display_order INT DEFAULT 0
    )");
    echo "Table 'link_categories' created.<br>";

    // 2. Add category_id to shared_links if missing
    try {
        $pdo->exec("ALTER TABLE shared_links ADD COLUMN category_id INT DEFAULT 1");
        echo "Column 'category_id' added to shared_links.<br>";
    } catch (Exception $e) {
        echo "Column 'category_id' already exists (Safe to ignore).<br>";
    }

    // 3. Insert Default Categories
    $stmt = $pdo->query("SELECT COUNT(*) FROM link_categories");
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO link_categories (name, display_order) VALUES ('General', 1), ('News', 2), ('Resources', 3)");
        echo "Default categories inserted.<br>";
    }

    echo "<h3 style='color:green'>SUCCESS: Database Updated. You can now use links.php</h3>";

} catch (PDOException $e) {
    echo "<h3 style='color:red'>Error: " . $e->getMessage() . "</h3>";
}
?>