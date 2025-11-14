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
* brief description of file
*
* @author      Ian Johnson   <ian.johnson@sydney.edu.au>
* @author      Artem Osmakov   <artem.osmakov@sydney.edu.au>
* @copyright   (C) 2005-2013 University of Sydney
* @link        http://Sydney.edu.au/Heurist
* @version     3.1.0
* @license     http://www.gnu.org/licenses/gpl-3.0.txt GNU License 3.0
* @package     Heurist academic knowledge management system
*/


	require_once(dirname(__FILE__).'/../../common/connect/applyCredentials.php');
	require_once(dirname(__FILE__).'/../../common/php/dbMySqlWrappers.php');
	require_once(dirname(__FILE__).'/../../records/files/fileUtils.php');

	if (! is_logged_in()) {
		header('Location: ' . HEURIST_URL_BASE . 'common/connect/login.php?db='.HEURIST_DBNAME);
		return;
	}
?>
<html>
    <head>
        <title>Backup all my data </title>
        <link rel=stylesheet href="../../common/css/global.css">
        <link rel=stylesheet href="../../common/css/edit.css">
        <link rel=stylesheet href="../../common/css/admin.css">
        <style>
            .input-row, .input-row .input-header-cell, .input-row div.input-cell {vertical-align:middle}
        </style>

    </head>

    <body class="popup" width=500 height=300>

<?php
if(!array_key_exists('mode', $_REQUEST)) {
?>


        <p>Your data will be exported either as a fully self-documenting HML (Heurist XML) file without attached files
            (image files, video, XML, maps, documents etc.) or as a ZIP file containing everything. </P>
        <p>By default you get all the records you have entered and any records you have bookmarked,
            but you can extend this to include any data in the database which you are authorised to read.</p>

		<form name='f1' action='exportMyDataPopup.php' method='get'>
			<input name='db' value='<?=HEURIST_DBNAME?>' type='hidden'>
			<input name='mode' value='1' type='hidden'>

	        <div class="input-row">
	            <div class="input-header-cell">Include attached files (output everything as one ZIP file)</div>
	            <div class="input-cell"><input type="checkbox" name="includeresources" value="1"></div>
	        </div>

	        <div class="input-row">
	            <div class="input-header-cell">Include resources from other users (everything to which I have access)</div>
	            <div class="input-cell"><input type="checkbox" name="allrecs" value="1"></div>
	        </div>

	        <div id="buttons" class="actionButtons">
	            <input type="submit" value="backup">
	            <input type="button" value="cancel" onClick="window.close();">
	        </div>
        </form>
<?php
}else{

	$user = get_user_username();
	$folder = HEURIST_UPLOAD_DIR."backup/".$user."/";

	if(file_exists($folder)){
		//clean folder
		delTree($folder);
	}
	if (!mkdir($folder, 0777, true)) {
   		die('Failed to create folder for backup');
	}

	//load hml poutpur into string file and save it HEURIST_BASE_URL
	$url = HEURIST_BASE_URL."/../../export/xml/flathml.php?w=all&a=1&depth=0&db=".HEURIST_DBNAME;

	if(@$_REQUEST['allrecs']!="1"){
		$userid = get_user_id();
		$q = "owner:$userid"; //user:$userid OR
	}else{
		$q = "sortby:-m";
	}
	$url .= ("&q=$q&filename=".$folder."backup.xml");


	$_REQUEST['w'] = 'all';
	$_REQUEST['a'] = '1';
	$_REQUEST['depth'] = '5';
	$_REQUEST['q'] = $q;
	$_REQUEST['rev'] = 'no'; //do not include reverce pointers
	$_REQUEST['filename'] = $folder."backup.xml";

	ob_flush();
	flush();
	$to_include = dirname(__FILE__).'/../../export/xml/flathml.php';
	$content = "";
	//$content = get_include_contents($filename);

    if (is_file($to_include)) {
        ob_start();
        include $to_include;
        $content = ob_get_contents();
        ob_end_clean();
    }

	$file = fopen ($folder."backup.xml", "w");
	if(!$file){
		die("Can't write backup file. Check permissions");
	}
	fwrite($file, $content);
	fclose ($file);

	//copy database definition

	$url = HEURIST_BASE_URL."admin/structure/getDBStructure.php?db=".HEURIST_DBNAME."&pretty=1";

	saveURLasFile($url, $folder."DbStructure.txt");
	//copy(dirname(__FILE__)."/../../admin/setup/coreDefinitions.txt", $folder."coreDefinitions.txt");

	//archive folder
	//zipDirectory($folder, HEURIST_UPLOAD_DIR."backup/".$user."_backup.zip");

	print "Your data have been backed up in ".$folder."backup.xml";
	print '<div style="width:100%; text-align:center; padding-top:40px;"><input type="button" value="repeat" onclick="{location.href=\'exportMyDataPopup.php?db='.HEURIST_DBNAME.'\'}"></div>';
}

//
// remove folder and all its content
//
function delTree($dir) {
   $files = array_diff(scandir($dir), array('.','..'));
    foreach ($files as $file) {
      (is_dir("$dir/$file")) ? delTree("$dir/$file") : unlink("$dir/$file");
    }
    return rmdir($dir);
}
/*
function get_include_contents($filename) {
    if (is_file($filename)) {
        ob_start();
        include $filename;
        $contents = ob_get_contents();
        ob_end_clean();
        return $contents;
    }
    return false;
}
*/
?>

    </body>
</html>

