<?php
  header("Content-type: text/xml; charset=utf-8");
  header("HTTP_X_OPENROSA_VERSION: 1.0");
  $date = date("D, M j G:i:s T Y");
  header("HTTP_DATE: $date");
  header("X-OpenRosa-Accept-Content-Length:1000000");
//error_log(print_r($_SERVER,true));
  $reqURL = (((array_key_exists("HTTPS", $_SERVER) && $_SERVER["HTTPS"] == "on")||
                     (array_key_exists("HTTP_X_FORWARDED_PROTO", $_SERVER) &&
                       strpos($_SERVER["HTTP_X_FORWARDED_PROTO"],"https")!== false)) ?
                "https://" : "http://") . @$_SERVER["SERVER_NAME"].@$_SERVER["REQUEST_URI"];

  if (@$_SERVER['REQUEST_METHOD'] == "HEAD"){
    header("Location: $reqURL",true,"204");
    exit;
  }

/* javarosa error codes
  201    Form Received    Everything went great. Thanks for submitting
  202    Accepted    We got and saved your data, but may not have fully processed it (e.g. couldn't match it to a schema). You should not try to resubmit.
  401    Unauthorized    Phone tried to post something it didn't have permission to post
  403    Forbidden    You're not allowed to post there. Ever.
  404    Not Found    Unknown url endpoint, domain, or other
  413    Request too large    The request body is too large for the server to process
  500    Internal Server Error    Something went awry on the server and we're not sure what it was
*/

  error_log(" processing - files ".print_r($_FILES,true));

  //check valid files
  $message = array();
  checkFilesAndSetDB();
  define('BYPASS_LOGIN',1);
  require_once(dirname(__FILE__).'/../common/connect/applyCredentials.php');
  require_once(dirname(__FILE__).'/../common/php/dbMySqlWrappers.php');
  require_once(dirname(__FILE__)."/../common/php/saveRecord.php");
  require_once(dirname(__FILE__)."/../records/files/uploadFile.php");

  $mysqli = mysqli_connection_overwrite(DATABASE);
  $uploadedTermID = null;
  $uploadedTerm = mysqli_fetch_assoc($mysqli->query("SELECT trm_ID, trm_OriginatingDBID,trm_IDInOriginatingDB FROM defTerms where trm_Label = 'Uploaded'"));
  if ($uploadedTerm && is_numeric($uploadedTerm['trm_ID'])){
    $uploadedTermID = $uploadedTerm['trm_ID']; //todo safeguard against multiple updated terms
  }
  function rtIDLookup($rtOrigID, $rtDBID) {
    $res = mysqli_fetch_assoc($mysqli->query("select rty_ID as localID from defRecTypes where rty_OriginatingDBID = $rtDBID  and rty_IDInOriginatingDB = $rtOrigID "));
    if ( array_key_exists('localID', $res)) {
      return $res['localID'];
    }
    return null;
  }

  function dtIDLookup($dtOrigID, $dtDBID) {
    $res = mysqli_fetch_assoc($mysqli->query("select dty_ID as localID from defDetailTypes where dty_OriginatingDBID = $dtDBID  and dty_IDInOriginatingDB = $dtOrigID "));
    if ( array_key_exists('localID', $res)) {
      return $res['localID'];
    }
    return null;
  }
  $titleDT = (defined('DT_NAME')?DT_NAME:0);
  $fileDT = (defined('DT_FILE_RESOURCE')?DT_FILE_RESOURCE:0);
  $dateDT = (defined('DT_DATE')?DT_DATE:0);
  $importStatusDT =  dtIDLookup(699,555) ? dtIDLookup(699,555) : 699;  //555-699 import status enum DT
  $digMediaDT =  dtIDLookup(130,555) ? dtIDLookup(130,555) : 130;  //555-130  pointer to digital media record
  $digMediaRT = (defined('RT_MEDIA_RECORD')?RT_MEDIA_RECORD:0);
  $fhmlFileRt = (($rtID = rtIDLookup(202,555)) ? $rtID : 202);//rt xform instance  555-202

  error_log(" defines $fhmlFileRt fhml,  $digMediaRT Media Record,  $fileDT file,  $digMediaDT  media Ptr,  $importStatusDT status");
// defines 202 fhml,  119 Media Record,  116 file,  130  media Ptr,  693 status,
  //store files
  if (!array_key_exists("error",$message)) {
    storeFiles();
  }

  if (!array_key_exists("error",$message)) {
    createMetadataRecords();
  }
  $statusCode = $message["statusCode"];

//  error_log(" made it - log ".print_r($message,true));

  header("Location: $reqURL",true,$statusCode);
  print '<OpenRosaResponse xmlns="http://openrosa.org/http/response">
  <message nature="0">';
  print print_r($message,true)."</message>
  </OpenRosaResponse>";

  exit;

  $importTag;
  function checkFilesAndSetDB(){
  global $message;
//error_log(" made it -orig files = ".print_r($_FILES,true));
//check files array are ODK
    if ( $_FILES &&
          array_key_exists('xml_submission_file',$_FILES) &&
          array_key_exists('tmp_name',$_FILES['xml_submission_file']) &&
          array_key_exists('type',$_FILES['xml_submission_file']) &&
          $_FILES['xml_submission_file']['type'] == "text/xml"){
      $message['submission structure'] = "ok";
    }else{
      //not javarosa protocol
      $message['error'] = "Invalid javarosa Post";
      $message['statusCode'] = "401"; // 401 == Unauthorised
      return;
    }
//check valid xml
    if (file_exists($_FILES['xml_submission_file']['tmp_name'])
          && $fhml = simplexml_load_file($_FILES['xml_submission_file']['tmp_name'])){
//error_log(print_r($fhml,true));
      $db = $fhml->database[0];
      $message['fhml check']="ok";
      $message['database']=(string)$db;
//find db name and set $_REQUEST
      $_REQUEST['db'] = (string)$db;
//error_log(print_r($_REQUEST,true));
    $deviceID = (string)$fhml->deviceID[0];
    $importTag = "xForm Import Device:".$deviceID;
//error_log(print_r($importTag,true));
      if(array_key_exists('file',$_FILES)){
        if(is_array(@$_FILES['file']['name'])){//translate structure of direct upload to openrosa format
          $fileCnt = count($_FILES['file']['name']);
          for ($index=0; $index<$fileCnt; $index++) {
            if (!$_FILES['file']['name'][$index]) continue; //empty file structure passed
            $_FILES[$index] = array('name'=>$_FILES['file']['name'][$index],
                                    'type'=>$_FILES['file']['type'][$index],
                                    'tmp_name'=>$_FILES['file']['tmp_name'][$index],
                                    'error'=>$_FILES['file']['error'][$index],
                                    'size'=>$_FILES['file']['size'][$index]);
          }
          unset($_FILES['file']);
        }
      }
    }else{
      //not fhml format
      $message['error'] = "Invalid fhml Post";
      $message['statusCode'] = "401"; // 401 == Unauthorised
    }
//error_log(" end check files = ".print_r($_FILES,true));
    return;
  }
//HEURIST_XFORM_PUBPATH
  function storeFiles(){
  global $message;
//construct new file path
    if( !defined('HEURIST_XFORM_PUBPATH')){
      $message['error'] = "xform pubpath invalid";
      $message['statusCode'] = "404"; // 404 == Not Found
      return;
    }
    $xmlFileName = $_FILES['xml_submission_file']['name'];
    preg_match("/_(\d\d\d\d-\d\d-\d\d)_/",$xmlFileName,$matches);
    if(count($matches)>1){
      $path = $matches[1];
    }else{
      $path = "unrecognized";
    }
    $info = new SplFileInfo(HEURIST_XFORM_PUBPATH.'submissions/'.$path);
    if (!$info->isDir()) {
      if (!mkdir($info, 0775, true)) {
        $message['submission path'] = "invalid";
        $message['statusCode'] = "401"; // 401 == Unauthorised
        return;
      }
    }
//write files
    $path = $info->getPathname()."/";
    foreach ($_FILES as $key => $fileInfo) {
      if (move_uploaded_file($fileInfo['tmp_name'], $path.$fileInfo['name'])) {
        $message['file saved'] = $fileInfo['name'];
      }else{
        $message['unsaved file'] = $fileInfo['name'];
        $message['statusCode'] = "500"; // 500 == Internal Server Error
        return;
      }
    }
//store urls with instance fhml first
    if (strpos($path, HEURIST_DOCUMENT_ROOT) !== false) {//on docroot so use URL
      $url = "http://".HEURIST_SERVER_NAME.substr($path,strlen(HEURIST_DOCUMENT_ROOT));
      foreach ($_FILES as $key => $fileInfo) {
        $_FILES[$key]['URL'] = $url.$fileInfo['name'];
        $_FILES[$key]['fullpath'] = $path.$fileInfo['name'];
      }
    }else{
      foreach ($_FILES as $key => $fileInfo) {
        $_FILES[$key]['fullpath'] = $path.$fileInfo['name'];
      }
    }
  }

  function createMetadataRecords(){
  global $digMediaRT, $fhmlFileRt, $digMediaDT, $fileDT, $titleDT, $importStatusDT, $uploadedTermID, $message, $importTag;
    error_log(" in create Records with db = ". $message['database']);
    if (!$digMediaRT || !$fhmlFileRt || !$digMediaDT || !$fileDT || !$importStatusDT) {
      return;
    }
//    error_log(" in create Records");
//go through files and create heurist records.
    $mediaRecIDs=array();
    foreach ($_FILES as $key => $fileInfo) {
        if ($key !== 'xml_submission_file'){ // skip first to get media ids
          $filename = $fileInfo['name'];
          $fullpath = $fileInfo['fullpath'];
          $path_parts = pathinfo($fullpath);
          $fileExt = strtolower($path_parts['extension']);
          $remoteURL = @$fileInfo['URL'];
          $recType = ($key === 'xml_submission_file'? $fhmlFileRt : $digMediaRT);
          $exifData = null;
          $details = array();
          $sizeKB = round($fileInfo['size']/1000);

          //read EXIF data for images
          if (strlen($fullpath) && strpos($fileInfo['type'],"image") !==false) {
  //          $exifData = readEXIF($fullpath);
          }

          if ($remoteURL) {
            $fileID = register_external(json_encode( array( "remoteURL" => $remoteURL,
                                                            "sizeKB" => $sizeKB,
                                                            "ext" => $fileExt)));
          }else{
            $fileID = register_file($fullpath,null,null);
          }

          if (is_numeric($fileID)) {
            $details["t:".$fileDT] = array("1"=>$fileID);
          }
          if ($titleDT && $filename) {
            $details["t:".$titleDT] = array("1"=>$filename);
          }

          // create Heurist record for this file
          $out = saveRecord(null, //record ID
                            $digMediaRT, //record type
                            (@$remoteURL?$remoteURL:null),  //record URL
                            $exifData,  //Notes
                            0, //share these
                            "viewable", //viewable
                            true, //bookmark
                            null, //pnotes
                            null, //rating
                            $importTag, //tags
                            null, //wgtags
                            $details,
                            null, //-notify
                            null, //+notify
                            null, //-comment
                            null, //comment
                            null);//+comment
            array_push($mediaRecIDs,$out['bibID']);
//error_log("ret from save " . print_r($out,true));
        }
    }
    // insert the fhml record
    $fileInfo = $_FILES['xml_submission_file'];
error_log("fhml fileinfo  " . print_r($fileInfo,true));
    $filename = $fileInfo['name'];
    $fullpath = $fileInfo['fullpath'];
    $path_parts = pathinfo($fullpath);
    $fileExt = strtolower($path_parts['extension']);
    $remoteURL = @$fileInfo['URL'];
    $details = array();
    $sizeKB = round($fileInfo['size']/1000);
    if ($remoteURL) {
//error_log("external URL  ");
      $fileID = register_external(json_encode( array( "remoteURL" => $remoteURL,
                                                      "sizeKB" => $sizeKB,
                                                      "ext" => $fileExt)));
    }else{
      $fileID = register_file($fullpath,null,null);
//error_log("internal file");
    }
    if (is_numeric($fileID)) {
      $details["t:".$fileDT] = array("1"=>$fileID);
    }
    if ($titleDT && $filename) {
      $details["t:".$titleDT] = array("1"=>$filename);
    }
    if ( $cnt = count($mediaRecIDs)){
//error_log("media file");
      for ($index = 0; $index<$cnt; $index++) {
         $key = "" + ($index+ 1);
         $details["t:".$digMediaDT][$key] = $mediaRecIDs[$index];
      }
    }
    if ($importStatusDT && $uploadedTermID) {//mark status as uploaded
      $details["t:".$importStatusDT] = array("1"=>$uploadedTermID);
    }
//error_log("fhml details " . print_r($details,true));
    $out = saveRecord(null, //record ID
                      $fhmlFileRt, //record type
                      (@$remoteURL?$remoteURL:null),  //record URL
                      null,  //Notes
                      0, //share these
                      "viewable", //viewable
                      false, //bookmark
                      null, //pnotes
                      null, //rating
                      $importTag, //tags
                      null, //wgtags
                      $details,
                      null, //-notify
                      null, //+notify
                      null, //-comment
                      null, //comment
                      null);//+comment
//error_log("fhml ret from save " . print_r($out,true));
    if (array_key_exists('bibID',$out)){
      $message['fhml recID'] = $out['bibID'];
      $message['statusCode'] = "201"; // 201 == Form Received
      $dummy=null;
      $saveResults = saveRecord($out['bibID'], //record ID
                        $fhmlFileRt, //record type
                        "".HEURIST_BASE_URL."import/xml/importFHML.php?db=".$_REQUEST['db']."&recID=".$out['bibID'],  //record URL
                        null,  //Notes
                        0, //share these
                        "viewable", //viewable
                        false, //bookmark
                        null, //pnotes
                        null, //rating
                        null, //tags
                        null, //wgtags
                        null, //details
                        null, //-notify
                        null, //+notify
                        null, //-comment
                        null, //comment
                        null, //+comment
                        $dummy, //nonces
                        $dummy, //rectitle
                        2);//import mode
    }else{
        $message['unsaved fhml'] = $fileInfo['name'];
        $message['statusCode'] = "500"; // 500 == Internal Server Error
    }
    return;
  }
?>
