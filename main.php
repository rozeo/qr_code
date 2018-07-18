<?php

  namespace roz\qr_code;
  require_once "qr_data/def.php";

  define("DATA_CODE_BYTES", 9);
  define("DATA_BLOCKS", 1);
  define("DATA_BLOCK_SIZE", [9]);
  define("RS_BLOCK_SIZE", [17]);

  function debugOutout($str){
    if(!isset($_GET["view"])) echo $str;
  }

  /*
    __construct :
      @str   : data (binary)string
      @level : RS level(QR_LEVEL_L, M, Q, H)
      @mode  : data code mode(only 8bit = QR_MODE_8BIT)
      @model : model number(0 <= model < 40)
  */

  class QRCode{
    private $mode = QR_MODE_8BIT;
    private $RS_block = [];
    private $data_block = [];
    private $model = 0;
    private $level = 0;
    private $qr_width = 0;

    private $map_bits = [];
    private $preserved_bits = [];

    public function __construct(string $str, int $level = QR_LEVEL_L,
                                    int $mode = QR_MODE_8BIT, int $model = -1){

      $data = [];
      $mode = QR_MODE_8BIT;
      $size = strlen($str);

      // モデル推定
      if($model < 0 || $model > 39){
        // モードビット(4bit) + サイズビット(16bit) + 終端ビット(4bit) = 3byte
        $req_size = 2 + $size;

        for($i = 6; $i > 0; $i--){
          // モデル9以下は1byte減
          if($i == 9) $req_size -= 1;

          if(QR_DATACODE_SIZE[$i][$level] < $size){
            $model = $i + 1;
            break;
          }
        }
        if($i == 0) $model = 0;
      } else {
        $model--;
      }

      if($model > 6) return;

      $this->model = $model;
      $this->level = $level;
      $this->qr_width = CalculateQRWidth($model);

      array_push($data, $mode << 4); // mode bit

      debugOutout(sprintf("MODEL: %d<br>", $model));

      if($model < 9){
        $data[0] ^= ($size >> 4);
        array_push($data, ($size & 0b1111) << 4);
      }else{
        $data[0] ^= $size >> 12;
        array_push($data, ($size >> 4) & 0xFF);
        array_push($data, ($size & 0b1111) << 4);
      }

      $byte = String2Ascii($str);

      // push main code bits
      $offset = count($data) - 1;
      for($i = 0; $i < $size; $i++){
        $data[$offset + $i] ^= $byte[$i] >> 4;
        array_push($data, ($byte[$i] & 0b1111) << 4);
      }


      // push padding bits
      $code_byte = QR_DATACODE_SIZE[$model][$level];
      for($i = count($data), $j = 0; $i < $code_byte; $i++, $j++){
        array_push($data, QR_PADDING_BIT[$j % 2]);
      }

      // split data block and calculate each RS block
      $block_size = 0;
      $split_size = QR_DATACODE_SPLIT_SIZE[$model][$level];
      $rs_size    = QR_RS_BLOCK_SIZE[$model][$level];

      for($i = 0; $i < count($split_size); $i++){
        $block_count = ($split_size[$i] < 0)? $split_size[$i++] * -1: 1;

        for($j = 0; $j < $block_count; $j++){
          $idx = array_push($this->data_block,
            array_slice($data, $block_size, $split_size[$i]));

          array_push($this->RS_block,
            CalculateRS($this->data_block[$idx - 1], $rs_size));

          $block_size += $split_size[$i];
        }
      }

      debugOutout(sprintf("%d<br>", count($this->data_block[0])));

      $this->setMapBits();
      $this->renderPic();
    }

    private function setMapBitsPreserved(int $x, int $y, $bit = 0){
      if($this->preserved_bits[$this->pos($x, $y)] == 0){
        $this->preserved_bits[$this->pos($x, $y)] = 1;
        $this->map_bits[$this->pos($x, $y)] = $bit;
      }
    }

    private function setMapBits(){

      $this->map_bits = array_pad([], $this->qr_width * $this->qr_width, 0);
      $this->preserved_bits = array_pad([], $this->qr_width * $this->qr_width, 0);

      // quiet pettern
      $q_size = count(QR_QUIET_MARK);
      for($i = 0; $i < $q_size; $i++){
        for($j = 0; $j < $q_size; $j++){
          $this->setMapBitsPreserved($j, $i, QR_QUIET_MARK[$i][$j]);
        }
      }

      for($i = $this->qr_width - $q_size, $p = 0; $i < $this->qr_width; $i++, $p++){
        for($j = 0; $j < $q_size; $j++){
          $this->setMapBitsPreserved($j, $i, QR_QUIET_MARK[$p][$j]);
          $this->setMapBitsPreserved($i, $j, QR_QUIET_MARK[$j][$p]);
        }
      }

      // void space
      for($i = 0; $i < $q_size + 1; $i++){
        $this->setMapBitsPreserved($i, $q_size);
        $this->setMapBitsPreserved($this->qr_width - 1 - $i, $q_size);
        $this->setMapBitsPreserved($this->qr_width - $q_size - 1, $i);

        $this->setMapBitsPreserved($q_size, $i);
        $this->setMapBitsPreserved($q_size, $this->qr_width - 1 - $i);
        $this->setMapBitsPreserved($i, $this->qr_width - $q_size - 1);
      }

      // timing pettern
      for($i = 0; $i < $this->qr_width; $i++){
        if($this->preserved_bits[$this->pos($i, 6)] == 0){
          $this->setMapBitsPreserved($i, 6, $i % 2 == 0 ? 1: 0);
        }
        if($this->preserved_bits[$this->pos(6, $i)] == 0){
          $this->setMapBitsPreserved(6, $i, $i % 2 == 0 ? 1: 0);
        }
      }

      // 変なタイミングビット
      $this->setMapBitsPreserved(8, $this->qr_width - 8, 1);

      // 形式情報ビット用予約

      for($i = 0; $i < 8; $i++){
        $this->setMapBitsPreserved(8, $this->qr_width - 1 - $i, 0);
        $this->setMapBitsPreserved($this->qr_width - 1 - $i, 8, 0);
      }

      for($i = 0; $i < 9; $i++){
        $this->setMapBitsPreserved(8, $i, 0);
        $this->setMapBitsPreserved($i, 8, 0);
      }

      // alignment pettern
      if(count(QR_ALIGNMENT_POSITION[$this->model]) > 0) {
        $a_position = QR_ALIGNMENT_POSITION[$this->model];
        for($i = 0; $i < count($a_position); $i++){
          $px = $a_position[$i];
          for($j = 0; $j < count($a_position); $j++){
            $py = $a_position[$j];
            if($this->preserved_bits[$this->pos($px, $py)] == 0){
              for($k = 0; $k < count(QR_ALIGNMENT_MARK); $k++){
                for($m = 0; $m < count(QR_ALIGNMENT_MARK[$k]); $m++){
                  $this->setMapBitsPreserved($px - 2 + $k, $py - 2 + $m, QR_ALIGNMENT_MARK[$k][$m]);
                }
              }
            }
          }
        }
      }

      // main data
      $tx = $this->qr_width - 1;
      $ty = $this->qr_width - 1;
      $count = 0;
      $direct = 0; // %2 == 0 上 1 下

      $put_size = 0;

      $split_size = count(QR_DATACODE_SPLIT_SIZE[$this->model][$this->level]);

      for($i = 0; $i < QR_DATACODE_SIZE[$this->model][$this->level]; $i++){
        for($j = 0; $j < 8;){
          if($this->preserved_bits[$this->pos($tx, $ty)] == 0){
            $bit = ($this->data_block[$i % $split_size][$i / $split_size] >> (7 - $j)) & 0b1;
            $this->map_bits[$this->pos($tx, $ty)] = $bit;
            $j++;
          }

          if($count % 2 == 0) $tx--;
          else{
            $tx++;
            $ty = ($direct == 0)? $ty - 1: $ty + 1;
          }

          if($ty < 0){
            $direct = 1;
            $ty = 0;
            $tx -= 2;
          }

          if($ty >= $this->qr_width){
            $direct = 0;
            $tx -= 2;
            $ty = $this->qr_width - 1;
          }

          if($tx == 6) $tx--;

          $count++;
        }
      }

      // put rs

      for($i = 0; $i < QR_RS_BLOCK_SIZE[$this->model][$this->level] * $split_size; $i++){
        for($j = 0; $j < 8;){
          if($this->preserved_bits[$this->pos($tx, $ty)] == 0){
            $bit = ($this->RS_block[$i % $split_size][$i / $split_size] >> (7 - $j)) & 0b1;
            $this->map_bits[$this->pos($tx, $ty)] = $bit;
            $j++;
          }

          if($count % 2 == 0) $tx--;
          else{
            $tx++;
            $ty = ($direct == 0)? $ty - 1: $ty + 1;
          }

          if($tx < 0){
            $tx = 0;
            $ty = ($direct == 0)? $ty - 1: $ty + 1;
          }

          if($ty < 0){
            $direct = 1;
            $ty = 0;
            $tx -= 2;
          }

          if($ty >= $this->qr_width){
            $direct = 0;
            $tx -= 2;
            $ty = $this->qr_width - 1;
          }

          if($tx == 6) $tx--;

          $count++;
        }
      }

      $mask_type = $this->CheckMask();
      $type = ((QR_LEVEL_BIT[$this->level] << 3) ^ $mask_type);
      $type_info = BCH5_TABLE[$type];;
      $type_info ^= 0b101010000010010;

      // 形式情報セット

      $offset = 0;
      for($i = 0; $i < 16; $i++){
        if($i < 8){
          $this->map_bits[$this->pos($this->qr_width - 1 - $i, 8)] = ($type_info >> $i) & 0b1;
        }else{
          if($this->map_bits[$this->pos(7 - ($i - 8), 8)] == 1){
            $offset++;
            continue;
          }
          $this->map_bits[$this->pos(7 - ($i - 8), 8)] = ($type_info >> ($i - $offset)) & 0b1;
        }
      }

      $offset = 0;
      for($i = 0; $i < 16; $i++){
        if($i < 9){
          if($this->map_bits[$this->pos(8, $i)]  > 0){
            $offset++;
            continue;
          }
          $this->map_bits[$this->pos(8, $i)] = ($type_info >> ($i - $offset)) & 0b1;
        }else{
          $this->map_bits[$this->pos(8, $this->qr_width - 1 - 7 + ($i - 8))] = ($type_info >> ($i - $offset)) & 0b1;
        }
      }
    }

    // マスク処理
    private function CheckMask(){
      $best_mask = [];
      $mask_type = 0;
      $best_score = 99999999;

      /*
      // mask 1
      {
        $arr = [];
        for($i = 0; $i < count($this->map_bits); $i++){
          $x = $i % $this->qr_width;
          $y = $i / $this->qr_width;
          if($this->preserved_bits[$i] == 0){
            array_push($arr, ($x + $y) % 2 == 0 ?
              (($this->map_bits[$i] == 0)? 1: 0): $this->map_bits[$i]);
          }else{
            array_push($arr, $this->map_bits[$i]);
          }
        }
        if($best_score >= $this->CalculateScore($arr)){
          $best_mask = $arr;
          $mask_type = QR_MASK_1;
        }
      }

      // mask 2
      {
        $arr = [];
        for($i = 0; $i < count($this->map_bits); $i++){
          $x = $i % $this->qr_width;
          $y = $i / $this->qr_width;
          if($this->preserved_bits[$i] == 0){
            array_push($arr, $x % 2 == 0 ?
              (($this->map_bits[$i] == 0)? 1: 0): $this->map_bits[$i]);
          }else{
            array_push($arr, $this->map_bits[$i]);
          }
        }
        if($best_score >= $this->CalculateScore($arr)){
          $best_mask = $arr;
          $mask_type = QR_MASK_2;
        }
      }

      // mask 3
      {
        $arr = [];
        for($i = 0; $i < count($this->map_bits); $i++){
          $x = $i % $this->qr_width;
          $y = $i / $this->qr_width;
          if($this->preserved_bits[$i] == 0){
            array_push($arr, $y % 3 == 0 ?
              (($this->map_bits[$i] == 0)? 1: 0): $this->map_bits[$i]);
          }else{
            array_push($arr, $this->map_bits[$i]);
          }
        }
        if($best_score >= $this->CalculateScore($arr)){
          $best_mask = $arr;
          $mask_type = QR_MASK_3;
        }
      }*/

      // mask 4
      {
        $arr = [];
        for($i = 0; $i < count($this->map_bits); $i++){
          $x = $i % $this->qr_width;
          $y = (int)($i / $this->qr_width);
          if($this->preserved_bits[$i] == 0){
            array_push($arr, ($x + $y) % 3 == 0 ?
              (($this->map_bits[$i] == 0)? 1: 0): $this->map_bits[$i]);
          }else{
            array_push($arr, $this->map_bits[$i]);
          }
        }
        if($best_score >= $this->CalculateScore($arr)){
          $best_mask = $arr;
          $mask_type = QR_MASK_4;
        }
      }
      /*
      // mask 5
      {
        $arr = [];
        for($i = 0; $i < count($this->map_bits); $i++){
          $x = $i % $this->qr_width;
          $y = $i / $this->qr_width;
          if($this->preserved_bits[$i] == 0){
            array_push($arr, (($x / 2) + ($y / 3)) % 2 == 0 ?
              (($this->map_bits[$i] == 0)? 1: 0): $this->map_bits[$i]);
          }else{
            array_push($arr, $this->map_bits[$i]);
          }
        }
        if($best_score >= $this->CalculateScore($arr)){
          $best_mask = $arr;
          $mask_type = QR_MASK_5;
        }
      }

      // mask 6
      {
        $arr = [];
        for($i = 0; $i < count($this->map_bits); $i++){
          $x = $i % $this->qr_width;
          $y = $i / $this->qr_width;
          if($this->preserved_bits[$i] == 0){
            array_push($arr, ($x * $y) % 2 + ($x + $y) % 3 == 0 ?
              (($this->map_bits[$i] == 0)? 1: 0): $this->map_bits[$i]);
          }else{
            array_push($arr, $this->map_bits[$i]);
          }
        }
        if($best_score >= $this->CalculateScore($arr)){
          $best_mask = $arr;
          $mask_type = QR_MASK_6;
        }
      }

      // mask 7
      {
        $arr = [];
        for($i = 0; $i < count($this->map_bits); $i++){
          $x = $i % $this->qr_width;
          $y = $i / $this->qr_width;
          if($this->preserved_bits[$i] == 0){
            array_push($arr, (($x + $y) % 2 + ($x * $y) % 3) % 2 == 0 ?
              (($this->map_bits[$i] == 0)? 1: 0): $this->map_bits[$i]);
          }else{
            array_push($arr, $this->map_bits[$i]);
          }
        }
        if($best_score >= $this->CalculateScore($arr)){
          $best_mask = $arr;
          $mask_type = QR_MASK_7;
        }
      }


      // mask 8
      {
        $arr = [];
        for($i = 0; $i < count($this->map_bits); $i++){
          $x = $i % $this->qr_width;
          $y = $i / $this->qr_width;
          if($this->preserved_bits[$i] == 0){
            array_push($arr, (($x * $y) % 3 + ($x + $y) % 2) % 2 == 0 ?
              (($this->map_bits[$i] == 0)? 1: 0): $this->map_bits[$i]);
          }else{
            array_push($arr, $this->map_bits[$i]);
          }
        }

        if($best_score >= $this->CalculateScore($arr)){
          $best_mask = $arr;
          $mask_type = QR_MASK_8;
        }
      }*/

      $this->map_bits = $best_mask;
      return $mask_type;
    }

    private function CalculateScore(array $arr){
      return 0;
    }

    private function pos(int $x, int $y){
      return $this->qr_width * $y + $x;
    }

    public function renderPic(){
      $px_size = 8;
      $size = $this->qr_width * 8 + 8 * 8;
      $img = imagecreatetruecolor($size, $size);

      $wc = imagecolorallocate($img, 255, 255, 255);
      $bc = imagecolorallocate($img, 0, 0, 0);
      $gc = imagecolorallocate($img, 128, 128, 128);
      $rc = imagecolorallocate($img, 255, 0, 0);

      imagefilledrectangle($img, 0, 0, $size, $size, $wc);

      for($i = 0; $i < count($this->map_bits); $i++){
        $x = ($i % $this->qr_width) + 4;
        $y = (int)($i / $this->qr_width) + 4;

        imagefilledrectangle($img, $x * $px_size, $y * $px_size, ($x + 1) * $px_size - 1, ($y + 1) * $px_size - 1,
                              $this->map_bits[$i] == 1? $bc: $wc);
      }

      if(isset($_GET["view"])){
        header("Content-Type: image/png");
        imagepng($img);
      }else{
        print_r($this->RS_block);
      }
      imagedestroy($img);
    }

    public function renderHtml(){

    }
  }
