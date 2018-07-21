<?php

  require_once "main.php";
  use roz\qr_code;

  if(!isset($_GET["data"])){
    echo "No input data.";
    exit();
  }

  $level = QR_LEVEL_H;

  $a = new qr_code\QRCode($_GET["data"], $level);

?>
