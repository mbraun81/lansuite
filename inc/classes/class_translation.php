<?php

/**
 * Translate the given String into the selectd Language.
 *
 * The first Argument is the String to translate, the following Parameters
 * are Placeholders for dynamic Data.
 * Example : t('Your name is %1 and your Email is %2', $row['name'], $row['email'])
 * Important use %1, %2, %3 for dynamic Data.
 *
 * @return string the translated String
 */
$translation_no_html_replace = false;
function t(/*$input, $parameter1, $parameter2....*/) {
    global $db, $translation, $func;
    
    ### Prepare Functionparameters
        // First argument is the Inputstring, the following are Parameters
        $args = func_get_args();
        (string)$input = array_shift($args);
        foreach ($args as $CurrentArg) {
            // If second Parameter is Array (old Style)
            if (!is_array($CurrentArg)) $parameters[] = $CurrentArg;
                else $parameters = $CurrentArg;
        }
    ### End prepare Functionparameters

    if ($input == '') return '';
    $key = md5($input);
    $modul = $_GET['mod'];
    $trans_text = '';
    (strlen($input) > 255)? $long = '_long' : $long = '';

    if ($translation->lang_cache[$modul][$key] != '') {
        // Already in Memorycache ($this->lang_cache[key])
        $output = $translation->ReplaceParameters($translation->lang_cache[$modul][$key], $parameters, $key);
    } else {
        // Try to read from DB
        if ($translation->language == "de") {
            // All Texts in Source are German at the Moment
            $output = $translation->ReplaceParameters($input, $parameters, $key);
        } else {
            if ($db->success) $trans_text = $translation->get_trans_db($key, $_GET['mod'], $long);
            // If DB fails Try to read from XML-Files
            // if ($trans_text == '') $trans_text = $translation->get_trans_file($key, $_GET['mod']);
            // If OK replace Parameter
            if ($trans_text != '' AND $trans_text != null) $output = $translation->ReplaceParameters($trans_text, $parameters);
            // If any Problem on get Translation just return $input
                else $output = $translation->ReplaceParameters($input, $parameters, $key);
        }
    }

    if (!$translation_no_html_replace) {
      // Deprecated. Should be replaced in t() by '<', '>' and '[br]'
      $output = str_replace("--lt--", "<", $output);
      $output = str_replace("--gt--", ">", $output);
      $output = str_replace("HTML_NEWLINE", "<br />", $output);
  
      return $func->text2html($output, 4);
    } else return $output;
}

function t_no_html() {
  $args = func_get_args();
  $translation_no_html_replace = true;
  t($args);
  $translation_no_html_replace = false;
}
/**
 * Global Translation
 *
 * @package lansuite_core
 * @author bytekilla, knox
 * @version $Id$
 * @access public
 * @todo Remove Dialogfunctions and create own class
 */
class translation {

  /**#@+
   * Intern Variables
   * @access private
   * @var mixed
   */
    var $language             = "de";                                 // Global Language
    var $transfile_name       = 'translation.xml';                    // Basename of Translationfile
    var $lang_names           = array('de' => 'Deutsch', 'en' => 'Englisch', 'es' => 'Spanisch', 'fr' => 'Französich', 'nl' => 'Holländisch', 'it' => 'Italienisch');
    var $valid_lang           = array('de','en','es','fr','nl','it'); // Valid Languages
    var $lang_cache           = array();                              // Temporary Translations
    var $cachemod_loaded_db   = 0;                                    // Is Cache for Modul loaded (db)
    var $cachemod_loaded_xml  = 0;                                    // Is Cache for Modul loaded (xml)
  /**#@-*/

  /**
   * CONSTRUCTOR Translation
   *
   */
    function translation() {
        $this->get_lang();          // Read Language from GET, POST & set
    }

  /**
   * Load Translation
   *
   * @param string Datasourchemode (xml for install, db for running System)
   * @param string Aktive Module
   */
    function load_trans($mode, $akt_modul) {
        if ($mode == 'db') {
            // System is configured, Language will be loaded from DB
            #$this->load_cache_bydb('System'); // Both included in the third query for performance reasons
            #$this->load_cache_bydb('DB');
            $this->load_cache_bydb($akt_modul);
            $this->cachemod_loaded_db = 1;
        } elseif ($mode == 'xml') {
            // System is on Install, Language will be loaded from XML
            $this->load_cache_byfile('System');
            $this->load_cache_byfile('DB');
            $this->load_cache_byfile($akt_modul);
            $this->cachemod_loaded_xml = 1;
        }
    }


  /**
   * Select and set the global Language
   *
   * @return string Returns a valid Language selected by User
   */
    function get_lang(){
        global $cfg;

        if     ($_POST['language']) $_SESSION['language'] = $_POST['language'];
        elseif ($_GET['language'])  $_SESSION['language'] = $_GET['language'];
        
        if ($_SESSION['language']) $this->language = $_SESSION['language'];
        elseif ($cfg["sys_language"]) $this->language = $cfg["sys_language"];
        else $this->language = "de";

        // Protect from bad Code/Injections
        if (!in_array($this->language,$this->valid_lang)) $this->language = "de";
        return $this->language;
    }
    
  /**
   * Load Translation for a Modul from DB into Memory
   *
   * @param string Modulname or DB / System
   */
    function load_cache_bydb($modul) {
        global $db;
        if ($db->success) {
            // Load from DB
            $res = $db->qry('SELECT id, org, '. $this->language .' FROM %prefix%translation WHERE file = %string% OR file = \'DB\' OR file = \'System\' ORDER BY FIELD(file, \'System,DB,'. $modul .'\')', $modul);
            while ($row = $db->fetch_array($res, 0)) {
                if ($row[$this->language] != '') {
                    if ($this->lang_cache[$modul][$row['id']] == '' ) $this->lang_cache[$modul][$row['id']] = $row[$this->language];
                }
            }
        }
    }

  /**
   * Load Translation from XML-File into Memory
   *
   * @param string Modulname or DB / System
   */
    function load_cache_byfile($modul) {
        // Load from File
        $xmldata = $this->xml_read_to_array($modul);
        if (is_array($xmldata)) {
            foreach ($xmldata as $id => $data) {
                $text = $data[$this->language];
                if ($this->lang_cache[$modul][$id] == '' AND $text != '') $this->lang_cache[$modul][$id] = $text;
            }
        }
    }

  /**
   * Get the Translation from DB via hashcode
   *
   * @param string  Text with Placeholders (blabla %1 bla %2)
   * @param array   Array with Parameters
   * @param
   * @return string Text with inserted Parameters
   * @static
   */
    function ReplaceParameters($input, $parameters = NULL, $key = NULL) {
        global $cfg, $auth;
        $z = 1;
        if ($parameters) foreach ($parameters as $parameter) {
          $input = str_replace('%'.$z, $parameter, $input);
          $z++;
        }
        if ($key and $auth['type'] >= 2 and $cfg['show_translation_links']) $input .= ' <a href=index.php?mod=misc&action=translation&step=40&id='. $key .'><img src=design/images/icon_translate.png height=10 width=10 border=0></a>';
        return $input;
    }
    
  /**
   * Get the calling Module for a t() function.
   *
   * @return string Returns the Calling Module
   */    
    function get_calling_module () {
        // NOT WORKING
        $out = debug_backtrace();
        return $out;
    }

  /**
   * Get the Translation from DB via hashcode
   *
   * @param string Hashcode from originaltext in Sourcecode
   * @return string Translationstring
   */
    function get_trans_db($hashkey, $modul, $long) {
        global $db;
        if ($this->lang_cache[$modul][$hashkey]) {
            $translated = $this->lang_cache[$modul][$hashkey];
        } else {
            $row = $db->qry_first('SELECT id, org, '. $this->language .' FROM %prefix%translation'.$long.' WHERE id = %string%', $hashkey);
            if ($row[$this->language]) $translated = $row[$this->language];
               else $translated = '';
        }
        return $translated;
    }

  /**
   * Get the Translation from tmod_ranslation.xml via hashcode
   *
   * @param string Hashcode from originaltext in Sourcecode
   * @param string Active Module
   * @return string Translationstring
   */
    function get_trans_file($hashkey, $modul) {
        if ($this->lang_cache[$modul][$hashkey]) {
            $translated = $this->lang_cache[$modul][$hashkey];
        } else {
           if ($this->cachemod_loaded_xml == 0) $this->load_cache_byfile($modul);
           $this->cachemod_loaded_xml = 1;
           if ($this->lang_cache[$modul][$hashkey]) $translated = $this->lang_cache[$modul][$hashkey];
               else $translated = '';
        }
        return $translated;
    }

  /**
   * Read complete Modultranslation from File and write to DB.
   * This will be normaly used on Installation
   *
   * @param string filename
   * @return boolean Success
   */
    function xml_write_file_to_db($modul) {
        global $db, $func;
        $lang_file = $this->get_trans_filename($modul);
        $count_update = 0;
        $count_insert = 0;
        // Open XML-File
        $xmldata = $this->xml_read_to_array($modul);
        if (is_array($xmldata)) {
            //var_dump($xmldata);
            foreach ($xmldata as $id => $data) if ($data['org']) {
                //echo "<hr>";
                //echo "ID:".$id."<br />\n";
                //var_dump($data);
                if (strlen($data['org']) > 255) $long = '_long'; else $long = '';
                // Search existing Translation in DB
                $row = $db->qry("SELECT tid FROM %prefix%translation".$long."
                          WHERE file = %string% AND id = %string%",
                          $modul,
                          $id);
                //var_dump($row);
                $affrow = $db->get_affected_rows();
                //echo "aff:".$affrow."<br />\n";
                if ($affrow>0) {
                    // Update if Row exists
                    $tr_update_set = Array();
                    if ($data['de']) $tr_update_set[] = "de='".$func->escape_sql($data['de'])."'";
                    if ($data['en']) $tr_update_set[] = "en='".$func->escape_sql($data['en']) ."'";
                    if ($data['es']) $tr_update_set[] = "es='".$func->escape_sql($data['es']) ."'";
                    if ($data['fr']) $tr_update_set[] = "fr='".$func->escape_sql($data['fr']) ."'";
                    if ($data['nl']) $tr_update_set[] = "nl='".$func->escape_sql($data['nl']) ."'";
                    if ($data['it']) $tr_update_set[] = "it='".$func->escape_sql($data['it']) ."'";
                    $tr_update_set_str = implode(',',$tr_update_set);
                    // FIX echo "Update ".$tr_update_set_str."<br />\n";
                    if ($tr_update_set_str) {
                        $count_update++;
                        $db->qry("UPDATE %prefix%translation".$long." SET ".$tr_update_set_str."
                                  WHERE file = %string% AND id = %string%",
                                  $modul,
                                  $id);
                    }

                } else {
                    // Insert if new Row
                    // FIX echo "Insert ".$data['tid']."<br />\n";
                    $count_insert++;
                    $db->qry_first("INSERT INTO %prefix%translation{$long} SET
                                          id= %int%,
                                          org= %string%,
                                          de= %string%,
                                          en= %string%,
                                          es= %string%,
                                          fr= %string%,
                                          nl= %string%,
                                          it= %string%,
                                          file= %string%
                                          ", $id, $data['org'], $data['de'], $data['en'], $data['es'], $data['fr'], $data['nl'], $data['it'], $modul);
                }
            }
        }
        $output = "Updates : ".$count_update." Inserts : ".$count_insert;
        return $output;
    }

  /**
   * Read complete Modultranslation from DB and write to back File
   * This will be only used for maintenace Translation
   *
   * @param string Modulname
   * @return boolean Success
   * @todo Errorhandler for xml and fileacces
   */
    function xml_write_db_to_file($modul) {
        global $db;

        include_once("inc/classes/class_xml.php");
        $xml = new xml;

        // Load old Translation from File to merge
        #$xml_old = $this->xml_read_to_array($modul);
        
        /* Header */
        $output = '<?xml version="1.0" encoding="UTF-8"?'.">\r\n\r\n";
        $header = $xml->write_tag("filetype", "LanSuite", 2);
        $header .= $xml->write_tag("version", "2.0", 2);
        $header .= $xml->write_tag("source", "http://www.lansuite.de", 2);
        $header .= $xml->write_tag("date", date("Y-m-d h:i"), 2);
        $header = $xml->write_master_tag("header", $header, 1);
        
        $table_head = $xml->write_tag('name', 'translation', 3);
        $tables = $xml->write_master_tag("table_head", $table_head, 2);
    
        $content = '';
        // read normal Translation
        $res = $db->qry("SELECT * FROM %prefix%translation WHERE file = %string%", $modul);
        while ($row = $db->fetch_array($res)) {
            $entry = $xml->write_tag('id', $row['id'], 4);
            $entry .= $xml->write_tag('org', $this->xml_var_merge($row['org'],$xml_old[$row['file']][$row['id']]['org']), 4);
            $entry .= $xml->write_tag('de', $this->xml_var_merge($row['de'],$xml_old[$row['file']][$row['id']]['de']), 4);
            $entry .= $xml->write_tag('en', $this->xml_var_merge($row['en'],$xml_old[$row['file']][$row['id']]['en']), 4);
            $entry .= $xml->write_tag('es', $this->xml_var_merge($row['es'],$xml_old[$row['file']][$row['id']]['es']), 4);
            $entry .= $xml->write_tag('fr', $this->xml_var_merge($row['fr'],$xml_old[$row['file']][$row['id']]['fr']), 4);
            $entry .= $xml->write_tag('nl', $this->xml_var_merge($row['nl'],$xml_old[$row['file']][$row['id']]['nl']), 4);
            $entry .= $xml->write_tag('it', $this->xml_var_merge($row['it'],$xml_old[$row['file']][$row['id']]['it']), 4);
            $entry .= $xml->write_tag('file', $modul, 4);
            $content .= $xml->write_master_tag("entry", $entry, 3);
        }
        $db->free_result($res);
        // read long Translation
        $res2 = $db->qry("SELECT * FROM %prefix%translation_long WHERE file = %string%", $modul);
        while ($row2 = $db->fetch_array($res2)) {
            $entry = $xml->write_tag('id', $row2['id'], 4);
            $entry .= $xml->write_tag('org', $this->xml_var_merge($row['org'],$xml_old[$row['file']][$row['id']]['org']), 4);
            $entry .= $xml->write_tag('de', $this->xml_var_merge($row['de'],$xml_old[$row['file']][$row['id']]['de']), 4);
            $entry .= $xml->write_tag('en', $this->xml_var_merge($row['en'],$xml_old[$row['file']][$row['id']]['en']), 4);
            $entry .= $xml->write_tag('es', $this->xml_var_merge($row['es'],$xml_old[$row['file']][$row['id']]['es']), 4);
            $entry .= $xml->write_tag('fr', $this->xml_var_merge($row['fr'],$xml_old[$row['file']][$row['id']]['fr']), 4);
            $entry .= $xml->write_tag('nl', $this->xml_var_merge($row['nl'],$xml_old[$row['file']][$row['id']]['nl']), 4);
            $entry .= $xml->write_tag('it', $this->xml_var_merge($row['it'],$xml_old[$row['file']][$row['id']]['it']), 4);
            $entry .= $xml->write_tag('file', $modul, 4);
            $content .= $xml->write_master_tag("entry", $entry, 3);
        }            
                       
        $db->free_result($res2);
    
        $tables .= $xml->write_master_tag("content", $content, 2);
        $lansuite .= $xml->write_master_tag("table", $tables, 1);
        $output .= $xml->write_master_tag("lansuite", $header.$lansuite, 0);

        // Filehandler. Make Backupcopy if Translationsfile exits
        $file = $this->get_trans_filename($modul);
        //if (file_exists($file)) copy($file, $file.time().'.bak'); // Backup
        $file_handle = @fopen($file, "w");
        @fputs($file_handle, $output);
        @fclose($file_handle);
    }

  /**
   * Works like an String-OR.
   * If Var1 is emty, return Var2
   *
   * @param string Variable 1 prior 
   * @param string Variable 2
   * @return string Output
   * @access private 
   */
    function xml_var_merge($var1, $var2) {
        if ($var2 != "") $out = $var2;
        if ($var1 != "") $out = $var1; // no error, var2 is prior
        return $out;
    }

  /**
   * Parse all Languagesets in Array
   *
   * @param string Modulname e.g. file-field
   * @return array Temporary XML-Data
   */
    function xml_read_to_array($modul) {

      if (!is_object($xml)) {
        include_once('inc/classes/class_xml.php');
        $xml = new xml();
      }

      $lang_file = $this->get_trans_filename($modul);
      if (file_exists($lang_file)) {
    		$xml_file = fopen($lang_file, "r");
    		$file_cont = fread($xml_file, filesize($lang_file));
    		fclose($xml_file);

        $entries = $xml->getTagContentArray('entry', $file_cont);
        foreach ($entries as $entry) {
          $id = $xml->getFirstTagContent('id', $entry, 1);
          $file = $xml->getFirstTagContent('file', $entry, 1);
          $records[$id]['org'] = $xml->getFirstTagContent('org', $entry, 1);
          $records[$id]['de'] = $xml->getFirstTagContent('de', $entry, 1);
          $records[$id]['en'] = $xml->getFirstTagContent('en', $entry, 1);
          $records[$id]['fr'] = $xml->getFirstTagContent('fr', $entry, 1);
          $records[$id]['it'] = $xml->getFirstTagContent('it', $entry, 1);
          $records[$id]['es'] = $xml->getFirstTagContent('es', $entry, 1);
          $records[$id]['nl'] = $xml->getFirstTagContent('nl', $entry, 1);
        }
      }

      return $records;
    }

  /**
   * Get the Filepath for a Languagefile. 
   * Path for System/DB and Modul is different
   *
   * @param string Modulname (System, DB, Modul..)
   * @return string Filepath to Languagefile
   */
    function get_trans_filename($modul) {
        switch ($modul) {
            case 'DB':
                $file = "inc/language/".$modul."_".$this->transfile_name;
            break;
            
            case 'System':
                $file = "inc/language/".$modul."_".$this->transfile_name;
            break;

            default:
                $file = "modules/".$modul."/mod_settings/".$this->transfile_name;
        }
        return $file;
    }

  /**
   * Read Languagespecific Strings from User DB Tables and write to Tranlationtable.
   * Exampe : TUpdateFromDB('menu', 'caption') read all Captions-Strings from
   * Table menue
   *
   * @param string Tablename e.g. menu
   * @param string Fieldname e.g. caption
   * @return integer Number of insert entrys
   */

    function TUpdateFromDB($table, $field) {
        global $db;
        $i = 0;
        $res = $db->qry('SELECT '. $field .' FROM %prefix%'. $table);
        while ($row = $db->fetch_array($res)) if ($row[$field] != '') {
            $key = md5($row[$field]);
            $row2 = $db->qry_first('SELECT 1 AS found FROM %prefix%translation WHERE id = %string%', $key);
            if (!$row2['found']) {
                $db->qry('REPLACE INTO %prefix%translation SET id = %string%, file = \'DB\', org = %string%', $key, $row[$field]);
                $i++;
            }
        }
        $db->free_result($res);
        return $i;
    }

  /**
   * Read all t()-Function Strings from the complete Sourcecode and write into
   * Translationtable.
   *
   * @param string Path to Scan
   * @return String Output like a Logfile
   */
    function TUpdateFromFiles($BaseDir) {
        global $db, $FoundTransEntries;

        $output = '';
        if (!is_array($FoundTransEntries)) $FoundTransEntries = array();

        $ResDir = opendir($BaseDir);
        while ($file = readdir($ResDir)) {
            $FilePath = $BaseDir .'/'. $file;

            if (substr($file, strlen($file) - 4, 4) == '.php') {

                // Generate Mod-Name from FILE
                $CurrentFile = str_replace('\\','/', $FilePath);
                if (strpos($CurrentFile, 'modules/') !== false) {
                    $CurrentFile = substr($CurrentFile, strpos($CurrentFile, 'modules/') + 8, strlen($CurrentFile));
                    $CurrentFile = substr($CurrentFile, 0, strpos($CurrentFile, '/'));
                } else $CurrentFile = 'System';

                $ResFile = fopen($FilePath, "r");
                $content = fread($ResFile, filesize($FilePath));
                fclose($ResFile);

                $treffer = array();
                preg_match_all('/([^a-zA-Z0-9]+t\\(\\\')(.*?)(\\\'\\)|\\\'\\,)/', $content, $treffer1, PREG_SET_ORDER + PREG_OFFSET_CAPTURE);
                preg_match_all('/([^a-zA-Z0-9]+t\\(\\")(.*?)(\\"\\)|\\"\\,)/', $content, $treffer2, PREG_SET_ORDER + PREG_OFFSET_CAPTURE);
                $treffer = array_merge ($treffer1, $treffer2);

                foreach ($treffer as $wert) {
                    $CurrentPos = $wert[2][1];
                    $CurrentTrans = $wert[2][0];
                    if ($CurrentTrans != '') {
                        $key = md5($CurrentTrans);
                        if (strlen($CurrentTrans) > 255) $long = '_long'; else $long = '';

                        // Do only add expressions, which are not already in system lang-file
                        $row = $db->qry_first("SELECT 1 AS found FROM %prefix%translation%plain% WHERE id = %string% AND (file = 'System' OR file = %string%)", $long, $key, $CurrentFile);
                        if ($row['found']) $output .= $CurrentFile .'@'. $CurrentPos .': '. $CurrentTrans .'<br />';
                        else {
                          // New -> Insert to DB
                          $db->qry("REPLACE INTO %prefix%translation%plain% SET id = %string%, file = %string%, org = %string%", $long, $key, $CurrentFile, $CurrentTrans);
                          $output .= '<font color="#00ff00">'. $CurrentFile .'@'. $CurrentPos .': '. $CurrentTrans .'</font><br />';
                        }
                        array_push($FoundTransEntries, $CurrentFile.'+'.$key); // Array is compared to DB later for synchronization
                    }
                }
            } elseif ($file != '.' and $file != '..' and $file != '.svn' and is_dir($FilePath)) $output .= $this->TUpdateFromFiles($FilePath);
        }
        closedir($ResDir);
        return $output;
    }

}
?>
