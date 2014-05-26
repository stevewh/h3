<?php
/*
 * Copyright (C) 2005-2013 University of Sydney
 *
 * Licensed under the GNU License, Version 3.0 (the "License"); you may not use this file except
 * in compliance with the License. You may obtain a copy of the License at
 *
 * http://www.gnu.org/licenses/gpl-3.0.txt
 *
 * Unless required by applicable law or agreed to in writing, software distributed under the License
 * is distributed on an "AS IS" BASIS, WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express
 * or implied. See the License for the specific language governing permissions and limitations under
 * the License.
 */
/**
 * import service for FHML xForm instance created from Heurist xForms model.
 *
 * @author      Stephen White  <stephen.white@sydney.edu.au>
 * @copyright   (C) 2005-2013 University of Sydney
 * @link        http://Sydney.edu.au/Heurist/about.html
 * @version     3.1.0
 * @license     http://www.gnu.org/licenses/gpl-3.0.txt GNU License 3.0
 * @package     Heurist academic knowledge management system
 * @subpackage  !!!subpackagename for file such as Administration, Search, Edit, Application, Library
 */
  //error_log(" processing - files ".print_r($_FILES,true));
  define('BYPASS_LOGIN',1);
  require_once(dirname(__FILE__).'/../../common/connect/applyCredentials.php');
  require_once(dirname(__FILE__).'/../../common/php/dbMySqlWrappers.php');
  require_once(dirname(__FILE__)."/../../common/php/saveRecord.php");
  require_once(dirname(__FILE__)."/../../records/files/uploadFile.php");
  require_once(dirname(__FILE__)."/../../search/getSearchResults.php");
  require_once (dirname(__FILE__) . '/../../records/files/fileUtils.php');

  mysql_connection_overwrite(DATABASE);
  if(mysql_error()) {
    die("Sorry, could not connect to the database (mysql_connection_overwrite error)");
  }

  function rtIDLookup($rtOrigID, $rtDBID) {
    $res = mysql_fetch_assoc(mysql_query("select rty_ID as localID from defRecTypes where rty_OriginatingDBID = $rtDBID  and rty_IDInOriginatingDB = $rtOrigID "));
    if ($res && array_key_exists('localID', $res)) {
      return $res['localID'];
    }
    return null;
  }

  function dtIDLookup($dtOrigID, $dtDBID) {
    $res = mysql_fetch_assoc(mysql_query("select dty_ID as localID from defDetailTypes where dty_OriginatingDBID = $dtDBID  and dty_IDInOriginatingDB = $dtOrigID "));
    if ( $res && array_key_exists('localID', $res)) {
      return $res['localID'];
    }
    return null;
  }
  $RTN = array(); //record type name
  $RQS = array(); //record type specific detail name
  $DTN = array(); //detail type name
  $DTT = array(); //detail type base type
  $TL = array(); //term lookup
  $TLV = array(); //term lookup by value
  $TLCV = array(); //term lookup by lowercase value
  $TLCID = array(); //term lookup by conceptID
  $importLog = array('errors' => array(), 'messages' => array());

  function makeLogEntry( $name = "unknown", $id = "", $msg = "no message", $isError = false ) {
    global $importLog;
    if($isError){
      array_push($importLog['errors'],count($importLog['messages']));
    }
    array_push($importLog['messages'], array($name, $id, $msg));
  }

  function echoLogExit() {
    global $importLog;
    $errCount = count($importLog['errors']);
    echo $errCount? $errCount." ERROR(S)!<br>" : "No errors encountered, SUCCESS!<br>";
    if ($errCount){
      echo "<u>Error List</u><br>";
      foreach($importLog['errors'] as $errIndex){
        list($name,$id,$msg) = $importLog['messages'][$errIndex];
        echo "Error: $name ".($id?"($id)":"")." errormsg = $msg <br>";
      }
    }
    echo getFormattedLog();
    exit;
  }

  function getFormattedLog() {
    global $importLog;
    $log = "<u>Log Messages</u><br>";
    $index = 0;
    foreach($importLog['messages'] as $entry){
      list($name,$id,$msg) = $entry;
      $isErr = in_array($index, $importLog['errors']);
      $log .= ($isErr?"Error: ":"action: ").$name.($id?"($id) ":" ").($isErr?"errormsg":"message")." = $msg <br>";
      $index++;
    }
    return $log;
  }

  $query = 'SELECT rty_ID, rty_Name FROM defRecTypes';
  $res = mysql_query($query);
  while ($row = mysql_fetch_assoc($res)) {
      $RTN[$row['rty_ID']] = $row['rty_Name'];
      $query = 'SELECT rst_RecTypeID, rst_DetailTypeID, rst_MaxValues FROM defRecStructure';
      $resFields = mysql_query($query);
      while ($fieldRow = mysql_fetch_assoc($resFields)) {
          // type-specific names for detail types
          $RQS[$fieldRow['rst_RecTypeID']][$fieldRow['rst_DetailTypeID']] = $fieldRow;
      }
  }

  /*****DEBUG****///error_log(print_r($RQS,true));
  // base names, varieties for detail types
  $query = 'SELECT dty_ID, dty_Name, dty_Type FROM defDetailTypes';
  $res = mysql_query($query);
  while ($row = mysql_fetch_assoc($res)) {
      $DTN[$row['dty_ID']] = $row['dty_Name'];
      $DTT[$row['dty_ID']] = $row['dty_Type'];
  }
  /*****DEBUG****///error_log(print_r($DTT,true));

  $query = 'SELECT * FROM defTerms';
  $res = mysql_query($query);
  if (mysql_error($res)){
    makeLogEntry("Term Lookup Tables","","mysql query error = ".mysql_error($res),true);
  }
  while ($res && $row = mysql_fetch_assoc($res)) {
      $TL[$row['trm_ID']] = $row;
      $TLV[$row['trm_Label']] = $row;
      $TLCV[strtolower($row['trm_Label'])] = $row;
      if ($row['trm_OriginatingDBID'] && $row['trm_IDInOriginatingDB']){
        $TLCID["".$row['trm_OriginatingDBID']."-".$row['trm_IDInOriginatingDB']] = $row['trm_ID'];
      }else if (is_numeric(HEURIST_DBID)){//registered
        $TLCID["".HEURIST_DBID."-".$row['trm_ID']] = $row['trm_ID'];
      }
  }

  $titleDT = (defined('DT_NAME')?DT_NAME:0);
  $fileDT = (defined('DT_FILE_RESOURCE')?DT_FILE_RESOURCE:0);
  $dateDT = (defined('DT_DATE')?DT_DATE:0);
  $importStatusDT =  dtIDLookup(699,555) ? dtIDLookup(699,555) : 699;  //555-699 import status enum DT
  $digMediaDT =  dtIDLookup(130,555) ? dtIDLookup(130,555) : 130;  //555-130  pointer to digital media record
  $digMediaRT = (defined('RT_MEDIA_RECORD')?RT_MEDIA_RECORD:0);
  $fhmlFileRt = (($rtID = rtIDLookup(202,555)) ? $rtID : 202);//rt xform instance  555-202

  makeLogEntry("Get TypeID","","rt$fhmlFileRt fhml,  rt$digMediaRT Media Record,  dt$fileDT file,  dt$digMediaDT  media Ptr, dt$importStatusDT status");

  $importTag =  "FHML import ".date('Y-m-d H');
  makeLogEntry("Set Import Tag","","importTag = '$importTag'");
  $recID = @$_REQUEST['recID'];
  if(!is_numeric($recID)) {
    die("No or unrecognized recID parameter value ($recID). Please call with ?recID= followed by a numeric only record id");
  }

  $record = loadRecord($recID,true,true);
  if (array_key_exists("error", $record)) {
    die("error while load record information - ". $record["error"]);
  }

  if ($record['rec_RecTypeID'] != $fhmlFileRt) {//not FHML record
    die("invalid import record type expecting #$fhmlFileRt and record has record type #". $record["rec_RecTypeID"]);
  }
  $title = $record['rec_Title'];
//echo " fhml rec title = $title <br>";
  $details =$record['details'];
  $bdStatus;
  if (array_key_exists($importStatusDT, $details)) {
    foreach ($details[$importStatusDT] as $dtlID => $val){
      $bdStatus = $dtlID;
      $status = $TL[$val]['trm_Label'];
//echo print_r($TL[$val],true)."<br>";
      break;
    }
  }
//echo " fhml rec = ".print_r($record,true)." <br>";
  makeLogEntry("Read import status","bd$bdStatus","Status for FHML Instance records#$recID is $status");
  if (strtolower($status) != "uploaded") {
    echoLogExit();
  }
  $file;
  if (array_key_exists($fileDT, $details)) {
    foreach ($details[$fileDT] as $dtlID => $val){
      $file = $val['file'];
      break;
    }
  }
  $fileID = $file['id'];
  $nonce = $file['nonce'];
  if ($file['origName'] !== "_remote") {
     $filename = $file['filePath'].$file['fileName'];
     $xml = file_get_contents($filename);
     if (!$xml) {
        makeLogEntry("Read FHML","f$fileID","Error while attemping to read local file ($filename)",true);
        echoLogExit();
     }
  } else {
      $filename = $file['URL'];
      if ($pos = strpos($filename, HEURIST_SERVER_NAME)){//local URL
        $fullpath = HEURIST_DOCUMENT_ROOT.substr($filename, $pos + strlen(HEURIST_SERVER_NAME));
        $xml = file_get_contents($fullpath);
      }else{
        $xml = loadRemoteURLContent($filename);
      }
      if (!$xml) {
        makeLogEntry("Read FHML","f$fileID","Error while attemping to read URL file ($filename)",true);
        echoLogExit();
      }
  }
  $mediaLookupNameToID = array();
  //for each attach media record make a filename to recID lookup
  if (array_key_exists($digMediaDT,$details)){
    foreach ($details[$digMediaDT] as $mediaRec){
      if ($mediaRec['type'] != $digMediaRT) continue;
      $mediaRecord = loadRecord($mediaRec['id'],true,true);
      if (array_key_exists("error", $mediaRecord)) {
        makeLogEntry("Loading Digital Media Info",$mediaRec['id'],"error while loading digital media record information - ". $mediaRecord["error"],true);
        continue;
      }
      $mediaLookupNameToID[$mediaRec['title']]['resource'] = $mediaRec['id'];
      $mediaDetails =$mediaRecord['details'];
      if (array_key_exists($fileDT, $mediaDetails)) {
        foreach ($mediaDetails[$fileDT] as $mediaDtlID => $mediaVal){
          $mediaLookupNameToID[$mediaRec['title']]['file'] = $mediaVal['file']['id'];
          break;
        }
      }
      makeLogEntry("Loading Digital Media Info",$mediaRec['id'],"loaded info for file ".$mediaRec['title']." which has fileID = ".$mediaVal['file']['id']);
    }
  }
//echo print_r($mediaLookupNameToID , true)."<br>";
  $linkURL = (@$record['rec_URL']?$record['rec_URL']: (@$file['URL']?$file['URL']:""));
  $fhml = simplexml_load_string($xml);
  if (!$fhml) {
    makeLogEntry("Loading FHML object","","error parsing xml in ".$RTN[$fhmlFileRt]." record",true);
    echoLogExit();
  }
  $db = $fhml->database[0];
  $dbName = (string)$db;
  $dbID = (integer)$db['id'];
  if ($dbName != HEURIST_DBNAME) {//not FHML record
    makeLogEntry("Checking DB Name",$dbID,"error parsing xml in ".$RTN[$fhmlFileRt]." record",true);
    echoLogExit();
   }
  $deviceID = (string)$fhml->deviceID[0];
  $importRecords = $fhml->records[0];
  if (array_key_exists('isrelatedto', $TLCV)) {
    $isRelatedTermID = $TLCV['isrelatedto']['trm_ID'];
  }else{
    makeLogEntry("Getting IsRelatedTo termID","","error 'isrelatedto' term not found, unable to link import records to xForm instance record",true);
  }
  foreach($importRecords as $importRecord) {
    $importResult = import($importRecord,$recID);
    if (array_key_exists("error",$importResult)) { // got error importing this record
      makeLogEntry("Saving Import Record","","error - ".join(",",$importResult['error'])." while trying to import XML ".$importRecord->asXML(), true);
      continue;
    }else{
      makeLogEntry("Save Import Record",$importResult['bibID'],"successfully saved record");
    }
    if(false && $isRelatedTermID) {
      $relResult = createRelationship($recID, $importResult['bibID'],$isRelatedTermID);
      if (array_key_exists("error",$relResult)) { // got error importing this record
        makeLogEntry("Saving Relationship Record","","error - ".join(",",$relResult['error'])." while trying relate xForm rec $recID to imported record ".$importResult['bibID'],true);
        continue;
      }else{
        makeLogEntry("Save Relationship Record",$relResult['bibID'],"successfully saved record");
      }
    }
  }
  // save log to xForms instance and set status to imported
  $dummy=null;
  $saveResults = saveRecord($recID, //record ID
                    $fhmlFileRt, //record type
                    null,  //record URL
                    getFormattedLog(),  //Notes
                    get_user_id(), //share these
                    "viewable", //viewable
                    true, //bookmark
                    null, //pnotes
                    null, //rating
                    null, //tags
                    null, //wgtags
                    array("t:".$importStatusDT => array("bd:".$bdStatus => $TLCV['imported']['trm_ID'])),
                    null, //-notify
                    null, //+notify
                    null, //-comment
                    null, //comment
                    null, //+comment
                    $dummy, //nonces
                    $dummy, //rectitle
                    2);//import mode
  echoLogExit();

// recursive import of record
function import($recordXML, $fhmlRecID){
  global $linkURL,$RTN, $RQS, $DTN, $DTT,$mediaLookupNameToID, $importTag, $TLCID;
  // find rectye information
  $rtyID = (integer)$recordXML->type['id'];
  $rtyConceptID = (string)$recordXML->type['conceptID'];
  if (!is_numeric($rtyID)){// name is of the form rt###
    $rtyID = substr($recordXML->getName(),2);
  }
  //TODO check that node is rt###
  //check concept id and lookup local.
  if($rtyConceptID){
    $temp = explode("-",$rtyConceptID);
    if (count($temp) == 2){
      $luRtyID = rtIDLookup($temp[1],$temp[0]);
      if ($luRtyID && $rtyID != $luRtyID) {
        makeLogEntry("Resolving RecType ID",$rtyID,"doesn't match conceptID ($rtyConceptID) lookup rectype $luRtyID");
        if (!array_key_exists($rtyID,$RQS)) {
          makeLogEntry("Resolving RecType ID",$rtyID,"rt$rtyID doesn't exist in database, using conceptID ($rtyConceptID) lookup recType $luRtyID");
          $rtyID = $luRtyID;
        }
      }
    } else {
      makeLogEntry("Resolving RecType ID",$rtyID,"FHML import format doesn't have conceptID for recType rt$rtyID");
    }
  }

  $recordID = (integer) $recordXML->id[0];
  if (!$recordID || !is_numeric($recordID)) {// new record
    $recordID = null;
    makeLogEntry("Importing Rectype",$rtyID,"Will create new record if successful");
  } else {
    //TODO: validate record and record's type
    makeLogEntry("Updating Record",$recordID,"Will update existing record of type rt$rtyID ($rtyConceptID)");
  }
//process details by iterating through them (skipping id and type which are already processed
  $details = array();
  foreach ($recordXML->children() as $childXML){
    $eleName = $childXML->getName();
    if ($eleName === "id" || $eleName === "type") {
      continue;
    }else{ //process detail
      //get dtID
      $dtyID = substr($eleName,2);
      $dtValue = (string)$childXML;
      if ((!$dtValue || $dtValue == "") && count($childXML->children())==0) {
        makeLogEntry("Check Detail Value",$dtyID,"No value for dt$dtyID, skipping");
        continue;
      }
      $dtConceptID = (string)$childXML['conceptID'];
      //check concept id and lookup local.
      if($dtConceptID){
        $temp = explode("-",$dtConceptID);
        if (count($temp) == 2){
          $luDtyID = dtIDLookup($temp[1],$temp[0]);
          if ($luDtyID && $dtyID != $luDtyID) {
            makeLogEntry("Resolving DetailType ID",$dtyID,"doesn't match conceptID ($dtConceptID) lookup detailtype $luDtyID");
            if (!array_key_exists($dtyID,$RQS[$rtyID])) {
              makeLogEntry("Resolving DetailType ID",$dtyID,"dt$dtyID doesn't exist in database, using conceptID ($dtConceptID) lookup detailtype $luDtyID");
              $dtyID = $luDtyID;
            }
          }
        } else {
          makeLogEntry("Resolving DetailType ID",$dtyID,"FHML import format doesn't have conceptID for detailtype $dtyID");
        }
      }

      //check repeat
      $repeatable = true; // default to true for opertunistic data capture
      if (!array_key_exists($dtyID,$RQS[$rtyID])) {
        makeLogEntry("Checking Detail Repeatability",$dtyID,"detailtype $dtyID field doesn't exist for rectype $rtyID, assuming repeatable");
        $repeatable = true;
      }else{
        $max = $RQS[$rtyID][$dtyID]['rst_MaxValues'];
        $repeatable = ($max === null || $max == "" || $max > 1) ? true : false;
        if ($repeatable) {
          makeLogEntry("Checking Detail Repeatability",$dtyID,"detailtype $dtyID field is repeatable");
        }
      }
      //get base type (enum or relationship or pointer)
      if (!array_key_exists($dtyID,$DTT)) {
        makeLogEntry("Checking DetailType Type",$dtyID,"error - detailtype dt$dtyID doesn't exist in database not importing '$dtValue'", true);
        continue;
      }
      //import sub records capture recIDs for each sub record made and feed them as values here
      if (count($childXML->children())>0 ) {
        if ($DTT[$dtyID] !== "resource"){//subrecords feed a resource detail it's id, we are here because the model is containment
          makeLogEntry("Checking for Subrecord",$dtyID,"error - detailtype dt$dtyID has children (sub records) and is not a resource, skipping XML = ".$childXML->asXML(), true);
          continue;
        }
        $subRecIDs = array();
        makeLogEntry("Start Subrecord Import",$dtyID,"detailtype dt$dtyID has children (sub records) and is a resource, beginning import");
        foreach ($childXML->children() as $subRecXML) {
          $importResult = import($subRecXML,$fhmlRecID);
          if (array_key_exists("error",$importResult)) { // got error importing this record
            makeLogEntry("Saving Import SubRecord","","error - ".join(",",$importResult['error'])." while trying to import XML ".$subRecXML->asXML(), true);
            continue;
          }else{
            array_push($subRecIDs,$importResult['bibID']);
            makeLogEntry("Saving Import SubRecord",$importResult['bibID'],"successfully saved record for dt$dtyID");
          }
        }
        makeLogEntry("End Subrecord Import",$dtyID,"end subrecord import for detailtype dt$dtyID adding import recIDs to detail value");
        if (array_key_exists("t:".$dtyID,$details)) {
          array_merge($details["t:".$dtyID],$subRecIDs);
        } else if (count($subRecIDs)>0){
          $details["t:".$dtyID] = $subRecIDs;
        }
      }else{
        if ($DTT[$dtyID] === "file") {//need to lookup fileIDs for import of resources
          if(array_key_exists($dtValue,$mediaLookupNameToID)){
            makeLogEntry("File Resource Lookup",$dtyID,"found fileID for resource $dtValue, setting dt$dtyID value to ".$mediaLookupNameToID[$dtValue]["file"]);
            $dtValue = $mediaLookupNameToID[$dtValue]["file"];
          }else{
            makeLogEntry("File Resource Lookup",$dtyID,"error - resource $dtValue not found in file lookup, ignoring detail dt$dtyID ", true);
            continue;
          }
        }
        //store detail information in array for save. need to check if existing value and push new value
        //process multi select xform result (ugh it uses space as a separator)
        makeLogEntry("Checking for Repeated Data",$dtyID,"detailtype dt$dtyID has basetype ".$DTT[$dtyID]." with value '$dtValue'");
        if (strpos($dtValue," ",1) && $repeatable && $DTT[$dtyID] === "resource" || $DTT[$dtyID] === "enum") {
          $matches = array();
          if ($DTT[$dtyID] === "enum"){//check if concept id form
            preg_match_all("/\d+-\d+/",$dtValue,$matches);
          }
          if (@$matches && count($matches)){// translate from conceptIDs and skip unknown terms
            $temp = array();
            foreach ( $matches[0] as $termConceptID){
              if ( array_key_exists($termConceptID,$TLCID)){
                array_push($temp,$TLCID[$termConceptID]);
                 makeLogEntry("Convert Term ConceptID",$termConceptID,"converted term concept $termConceptID to local term ".$TLCID[$termConceptID]);
              }else{
                 makeLogEntry("Convert Term ConceptID",$termConceptID,"ignoring data for repeatable enum dt$dtyID with unknown term concept $termConceptID",true);
              }
            }
            if (count($temp)) {// we have converted termIDs
              $dtValue = $temp;
            }
          }else{//multiple local term codes or recIDs case
            $dtValue = explode(" ",$dtValue);
          }
        }
        if (is_array($dtValue)){
           if (array_key_exists("t:".$dtyID,$details)) {
              array_merge($details["t:".$dtyID],$dtValue);
            } else {
              $details["t:".$dtyID] =$dtValue;
            }
        }else{
          if (array_key_exists("t:".$dtyID,$details)) {
            array_push($details["t:".$dtyID],$dtValue);
          }else{
            $details["t:".$dtyID] = array($dtValue);
          }
        }
      }
    }
  }
  //save record and return id
  makeLogEntry("Calling Save Record",$rtyID,($recordID?"Updating rt$rtyID record#$recordID ":"Creating new rt$rtyID ")."with details = ".json_format($details,true));
  $dummy=null;
  $saveResults = saveRecord($recordID, //record ID
                    $rtyID, //record type
                    null,  //record URL
                    "imported using XML = ".$recordXML->asXML(),  //Notes
                    get_user_id(), //share these
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
                    null, //+comment
                    $dummy, //nonces
                    $dummy, //rectitle
                    2);//import mode
  return $saveResults;
  //return array('bibID'=>"fakeIDrt$rtyID");
}

  function createRelationship($srcID, $trgID, $relTypeID){
    global $importTag;
    if (!defined('DT_TARGET_RESOURCE') || !defined('RT_RELATION') || !defined('DT_RELATION_TYPE') || !defined('DT_PRIMARY_RESOURCE')) {
      return array('error' => "standard relationship rectype and details were not found in database");
    }
    $details = array();
    $details["t:".DT_PRIMARY_RESOURCE] = array($srcID);
    $details["t:".DT_TARGET_RESOURCE] = array($trgID);
    $details["t:".DT_RELATION_TYPE] = array($relTypeID);
    $dummy=null;

    $result = saveRecord(null, //record ID
                      RT_RELATION, //record type
                      null,  //record URL
                      null,  //Notes
                      get_user_id(), //share these
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
                      null, //+comment
                      $dummy, //nonces
                      $dummy, //rectitle
                      2);//import mode
    return $result;
  }
?>
