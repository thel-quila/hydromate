<?php
$host = "localhost";
$user = "thel";
$password = "helloxampp";
$database = "hydro_db";

$conn = new mysqli($host, $user, $password, $database);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

?>
