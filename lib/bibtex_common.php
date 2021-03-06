<?php

require_once 'PARSECREATORS.php';

/**
 * A list of creators (e.g., authors, editors)
 */
class PaperciteBibtexCreators {
  function __construct(&$creators) {
    $this->creators = &$creators;
  }
  function count() {
    return sizeof($this->creators);
  }
  
  static function parse($authors) {
      $parseCreators = new PaperciteParseCreators();
      $creators = $parseCreators->parse($authors);
      foreach($creators as &$cArray) {
        $cArray = array(
  		      "surname" => trim($cArray[2]),
  		      "firstname" => trim($cArray[0]),
  		      "initials" => trim($cArray[1]),
  		      "prefix" => trim($cArray[3])
  		      );
        unset($cArray);
      }
      return new PaperciteBibtexCreators($creators);
  }
  
}

/**
 * A page range
 */
class PaperciteBibtexPages {
  function __construct($start, $end) {
    $this->start = (int)$start;
    $this->end = (int)$end;
  }
  function count() {
    return ($this->start ? 1 : 0) + ($this->end ? 1 : 0);
  }
}

?>