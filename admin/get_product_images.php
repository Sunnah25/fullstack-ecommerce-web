<?php
include 'auth.php';
include '../includes/db.php';

$id   = intval($_GET['id']);
$stmt = mysqli_prepare($conn,
    "SELECT * FROM product_images
     WHERE product_id = ?
     ORDER BY is_primary DESC, sort_order ASC");
mysqli_stmt_bind_param($stmt, "i", $id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$images = [];
while ($row = mysqli_fetch_assoc($result)) {
    $images[] = $row;
}
mysqli_stmt_close($stmt);

header('Content-Type: application/json');
echo json_encode($images);
?>