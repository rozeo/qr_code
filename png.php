<?php

  require_once "main.php";
  use roz\qr_code;

  define("CELLS", 21);
  define("CELL_SIZE", 12);

  $a = new qr_code\QRCode("https://twitter.com/rozeo_s/", QR_LEVEL_H);
  // print_r($s);

?>
