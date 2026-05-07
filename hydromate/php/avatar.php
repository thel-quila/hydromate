<?php
$conn = new mysqli("localhost", "thel", "helloxampp", "hydro_db");

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$check = $conn->query("SHOW COLUMNS FROM `user` LIKE 'avatar'");
if ($check->num_rows == 0) {
  
    $sql = "ALTER TABLE `user` ADD COLUMN `avatar` VARCHAR(10) DEFAULT '💧' AFTER `is_admin`";
    if ($conn->query($sql)) {
        echo "Avatar column added successfully!";
    } else {
        echo "Error: " . $conn->error;
    }
} else {
    echo "Avatar column already exists.";
}

$conn->close();
?>