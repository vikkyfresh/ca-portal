<?php
session_start();
echo '<pre>';
echo 'All session variables:<br>';
print_r($_SESSION);
echo '</pre>';
?>