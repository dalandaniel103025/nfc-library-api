<?php

$host = "sql301.infinityfree.com";

$user = "if0_41967008";

$password = "TMKNFCLib26";

$database = "if0_41967008_nfclibrary";

$conn = new mysqli(
    $host,
    $user,
    $password,
    $database
);

if($conn->connect_error)
{
    die(
        "Connection failed: " .
        $conn->connect_error
    );
}
?>
