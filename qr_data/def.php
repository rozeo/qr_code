<?php

namespace roz\qr_code;
require_once "a8galois.php";
require_once "size_list.php";

function _F($p){
  return floor($p);
}

define("QR_QUIET_MARK", [
  [1, 1, 1, 1, 1, 1, 1],
  [1, 0, 0, 0, 0, 0, 1],
  [1, 0, 1, 1, 1, 0, 1],
  [1, 0, 1, 1, 1, 0, 1],
  [1, 0, 1, 1, 1, 0, 1],
  [1, 0, 0, 0, 0, 0, 1],
  [1, 1, 1, 1, 1, 1, 1],
]);

define("QR_ALIGNMENT_MARK", [
  [1, 1, 1, 1, 1],
  [1, 0, 0, 0, 1],
  [1, 0, 1, 0, 1],
  [1, 0, 0, 0, 1],
  [1, 1, 1, 1, 1]
]);

define("QR_PADDING_BIT", [0b11101100, 0b00010001]);

define("QR_LEVEL_L", 0);
define("QR_LEVEL_M", 1);
define("QR_LEVEL_Q", 2);
define("QR_LEVEL_H", 3);

define("QR_LEVEL_BIT", [
  QR_LEVEL_L => 0b01,
  QR_LEVEL_M => 0b00,
  QR_LEVEL_Q => 0b11,
  QR_LEVEL_H => 0b10
]);

define("QR_LEVEL_STR", [
  QR_LEVEL_L => "QR_LEVEL_L",
  QR_LEVEL_M => "QR_LEVEL_M",
  QR_LEVEL_Q => "QR_LEVEL_Q",
  QR_LEVEL_H => "QR_LEVEL_H"
]);

define("QR_MODE_NUMBER",   0b0001);
define("QR_MODE_ALPHABET", 0b0010);
define("QR_MODE_8BIT",     0b0100);
define("QR_MODE_KANJI",    0b1000);

define("QR_MASK_1", 0b000);
define("QR_MASK_2", 0b001);
define("QR_MASK_3", 0b010);
define("QR_MASK_4", 0b011);
define("QR_MASK_5", 0b100);
define("QR_MASK_6", 0b101);
define("QR_MASK_7", 0b110);
define("QR_MASK_8", 0b111);

define("QR_RS_DIVIDE", [
  7  => [0,  87, 229, 146, 149, 238, 102,  21],
  10 => [0, 251,  67,  46,  61, 118,  70,  64,  94,  32,  45],
  13 => [0,  74, 152, 176, 100,  86, 100, 106, 104, 130, 218, 206, 140,  78],

  15 => [0,   8, 183,  61,  91, 202,  37,  51,  58,  58, 237, 140, 124,   5,
  99, 105],

  16 => [0, 120, 104, 107, 109, 102, 161,  76,   3,  91, 191, 147, 169, 182,
  194, 225, 120],

  17 => [0,  43, 139, 206,  78,  43, 239, 123, 206, 214, 147,  24,  99, 150,
  39, 243, 163, 136],

  18 => [0, 215, 234, 158,  94, 184,  97, 118, 170,  79, 187, 152, 148, 252,
  179,   5,  98,  96, 153],

  20 => [0,  17,  60,  79,  50,  61, 163,  26, 187, 202, 180, 221, 225,  83,
            239, 156, 164, 212, 212, 188, 190],

  22 => [0, 210, 171, 247, 242,  93, 230,  14, 109, 221,  53, 200,  74,   8,
  172,  98,  80, 219, 134, 160, 105, 165, 231],

  24 => [],
  26 => [0, 173, 125, 158,   2, 103, 182, 118,  17, 145, 201, 111,  28, 165,
  53, 161,  21, 245, 142,  13, 102,  48, 227, 153, 145, 218,  70]
]);

define("QR_ALIGNMENT_POSITION", [
  [],
  [6, 18],
  [6, 22],
  [6, 26],
  [6, 30],
  [6, 34],
  [6, 22, 38]
]);

define("BCH5_TABLE", [
  0b000000000000000,
  0b000010100110111,
  0b000101001101110,
  0b000111101011001,
  0b001000111101011,
  0b001010011011100,
  0b001101110000101,
  0b001111010110010,
  0b010001111010110,
  0b010011011100001,
  0b010100110111000,
  0b010110010001111,
  0b011001000111101,
  0b011011100001010,
  0b011100001010011,
  0b011110101100100,
  0b100001010011011,
  0b100011110101100,
  0b100100011110101,
  0b100110111000010,
  0b101001101110000,
  0b101011001000111,
  0b101100100011110,
  0b101110000101001,
  0b110000101001101,
  0b110010001111010,
  0b110101100100011,
  0b110111000010100,
  0b111000010100110,
  0b111010110010001,
  0b111101011001000,
  0b111111111111111
]);

function String2Ascii(string $str){
  $d = [];
  $len = strlen($str);

  for($i = 0; $i < $len; $i++) array_push($d, ord($str[$i]));
  return $d;
}

function CalculateQRWidth(int $model){
  return 21 + $model * 4;
}

function CalculateRS(array $data, int $RS_size){
  if(!isset(QR_RS_DIVIDE[$RS_size])) return false;

  $div = QR_RS_DIVIDE[$RS_size];
  $code_size = count($data) + $RS_size;
  for($i = count($data); $i < $code_size; $i++) array_push($data, 0);

  $step = 0;
  for(; $step < $code_size - $RS_size; $step++){
    $av = GALOIS_BIN2IDX[$data[$step]];

    for($i = 0; $i <= $RS_size; $i++){
      $divide = ($div[$i] + $av) % 255;
      @$a = $data[$step + $i] ^ GALOIS_IDX2BIN[$divide];
      @$data[$step + $i] = $a;
    }
  }

  return array_slice($data, $step);
}

function MaskJudge(int $i, int $j, $mask){
  switch($mask){
  case 0:
    if(($i + $j) % 2 == 0) return true;
    return false;

  case 1:
    if($i % 2 == 0) return true;
    return false;

  case 2:
    if($j % 3 == 0) return true;
    return false;

  case 3:
    if(($i + $j) % 3 == 0) return true;
    return false;

  case 4:
    if((_F($i / 2) + _F($j / 3)) % 2 == 0) return true;
    return false;

  case 5:
    if(($i * $j) % 2 + ($i * $j) % 3 == 0) return true;
    return false;

  case 6:
    if((($i * $j) % 2 + ($i * $j) % 3) % 2 == 0) return true;
    return false;

  case 7:
    if((($i * $j) % 3 + ($i + $j) % 2) % 2 == 0) return true;
    return false;
  }
  return false;
}

?>
