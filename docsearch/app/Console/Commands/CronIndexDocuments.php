<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Folder;
use App\File;
use App\Click;
use Log;
use DB;
use Requests;

class CronIndexDocuments extends Command
{
  /**
   * The name and signature of the console command.
   *
   * @var string
   */
  protected $signature = 'cron:index-documents';
  private $folders;
  private $invalid_files;
  private $files;
  private $count;
  private $cacheCreated;
  private $folder_path = '/tmp/folders.json';
  private $files_path = '/tmp/files.json';

  /**
   * The console command description.
   *
   * @var string
   */
  protected $description = 'Command to get all documents from the file server and index them on ELasticSearch.';

  /**
   * Create a new command instance.
   *
   * @return void
   */
  public function __construct()
  {
      parent::__construct();
  }

  /**
   * Execute the console command.
   *
   * @return mixed
   */
  public function handle()
  {

    //Functions for test purposes only
    //$this->testElasticSearchConnection();
    //$this->clearMysqlTables();
    ////////////////////////////////
    //Clear Index and create maps again
    //$this->createIndex();
    //Test connection with db and print log
    //$this->dbTests();
    $start = microtime(true);
    $start_date = Date("y-m-d H:i:s");
    //Call the function to start the folder and file creation on ES
    $this->createFolderIndex();
    //Call the function to start the file indexing on ES
    $time_elapsed_secs = microtime(true) - $start;
    $end_date = Date("y-m-d H:i:s");

    $this->info('Script started at: '.$start_date." and finished at: ".$end_date." with total time of execution of ".$time_elapsed_secs." seconds.");
  }
  /*
   * Create index with all the folders on ES
   */
  private function createFolderIndex(){
    //Just testing the command
    $this->info('Starting to search the folder: '.$_ENV['EBT_FILE_STORAGE']);
    $this->folders = array();
    $this->invalid_files = array();
    $this->count = 0;

    //Tagging all files to be able to find removed files at the end
    $SQL = "UPDATE files SET found = '0'";
    DB::connection('mysql')->update($SQL);
    $SQL = "UPDATE folders SET found = '0'";
    DB::connection('mysql')->update($SQL);

    //Checking if cache file exist, if not just create all the index on ES
    $this->checkCacheFolder();
    //Loop through the directories to get files ad folders
    $this->listFolderFiles($_ENV['EBT_FILE_STORAGE']);
    //Check if any folder is missing from cache and try to create on ES
    $this->compareCacheFolders();

    //Remove files/folders that hasn't been found
    //if ($this->confirm('Do you wish to remove missing files? [y|N]')) {
      $this->removeMissingFiles();
    //}
    //Start slack notification
    //$this->sendSlackNotification();
  }
  /*
   * This function will loop through the directories and get all the folders and files
   */
  private function listFolderFiles($dir){
    $children = false;
    $isRoot = false;
    if($dir==$_ENV['EBT_FILE_STORAGE']){
      $isRoot = true;
    }

    $folderName = $this->getFolderName($dir);
    if(!$isRoot){
      //$this->info('Directory Name: '.$folderName['child']);
      //$this->info('Parent Name: '.$folderName['parent']);
    }

    //$this->info('Folder: '.$dir);

    foreach (new \DirectoryIterator($dir) as $fileInfo) {
      if (!$fileInfo->isDot()) {
        if ($fileInfo->isDir()) {
          $this->listFolderFiles($fileInfo->getPathname());
        }else{
          $rename = false;
          $this->info('File: '.$fileInfo->getFilename());
          //$info = new SplFileInfo($dir.'/'.$fileInfo->getFilename());
          $ext = ".".pathinfo($fileInfo->getFilename(), PATHINFO_EXTENSION);
          //$ext = "."$info->getExtension();
          $this->info('File: extension '.$ext);
          $filebreak = str_replace($ext,"",$fileInfo->getFilename());
          if (strpos($filebreak, '.') !== false || strpos($filebreak, "'") !== false) {
            $rename = true;
          }
          if($rename){
            $replace = array(".", "'");
            $fileraw = str_replace($replace," ",$filebreak);
            $this->info('File: raw '.$fileraw);
            $newfilename = $fileraw . $ext;
            $this->info('File: new '.$newfilename);
            $prod_filename = $newfilename;
            rename($dir.'/'.$fileInfo->getFilename(),$dir.'/'.$newfilename);
          }else{
            $prod_filename = $fileInfo->getFilename();
          }
          $this->checkFileExtension($dir.'/'.$prod_filename, $prod_filename, $dir);
        }
        $children = true;
      }else{
        $children = false;
      }
    }
    //$this->info('Children: '.$children);

    if(!$isRoot){
      $this->folders[$this->count]['name'] 			= $folderName['child'];
      $this->folders[$this->count]['parent'] 		= $folderName['parent'];
      $this->folders[$this->count]['full_path']	= $dir;
      $this->folders[$this->count]['children']	= $children;
      $this->count++;
    }
    //$this->info('#################');
  }

  /*
   * Function to separate the path from the actual folder name
   */
  private function getFolderName($path){
    $arr = explode("/",$path);
    $child = count($arr) - 1;
    $parent = count($arr) - 2;

    $response['child'] = $arr[$child];
    $response['parent'] = str_replace($arr[$child],"",$path);
    return $response;
  }

  /*
   * Function to compare what the folders are now against the cache file
   */
  private function compareCacheFolders(){
    $this->info('List of folders');
    $headers = ['Name', 'Parent', 'full_path', 'children'];
    $this->table($headers, $this->folders);

    foreach ($this->folders as $key => $value) {
      $this->info('Folder '.$value['name'].' found => '.$this->isFolderSaved($value));
      if(!$this->isFolderSaved($value)){
        //Create object on mysql table
        $folder               = new Folder;
        $folder->name         = str_replace("'","\'",$value['name']);
        $folder->parent       = str_replace("'","\'",$value['parent']);
        $folder->full_path    = str_replace("'","\'",$value['full_path']);
        $folder->children     = $value['children'];
        $folder->found        = 1;

        // save the folder to the database
        $folder_db = $folder->save();

        $params = [
            'index' => 'docsearch',
            'type' => 'folders',
            'id'  => $folder->id,
            'body' => $value
        ];
        $this->info('Mysql ID for folder '.$value['name'].' -> '.$folder->id);
        $hosts = [$_ENV['ES_HOST']];// IP + Port
        // Instantiate a new ClientBuilder
        $client = \Elasticsearch\ClientBuilder::create()
          ->setHosts($hosts)      // Set the hosts
          ->build();
        $results = $client->index($params); //$client->search($params);
        //Log::info('ES Response', array('results'=> $results));
        //Log::info('############');
      }
    }
  }

  /*
  * Checks if the folder is already on db
  */
  private function isFolderSaved($data){
    //Checking the folder table to make sure we have the
    //$f = Folder::where('name', '=', $data['name'])->where('full_path', '=', $data['full_path'])->get();
    $SEL = "SELECT * FROM folders WHERE name='".$data['name']."' AND full_path='".$data['full_path']."'";
    $f = DB::connection('mysql')->select($SEL);
    if(count($f) > 0){
      foreach ($f as $folder) {
        $SQL = "UPDATE folders SET found = '1' WHERE id = '".$folder->id."'";
        DB::connection('mysql')->update($SQL);
      }
      return true;
    }else{
      return false;
    }
  }
  /*
   * Function to check if this file is good for us to read(in a vlid format)
   */
  private function checkFileExtension($filename, $name, $parent){
    $ext_arr = explode(".",$filename);
    $m = count($ext_arr)-1;
    $valid = false;
    $index_time = date("Y-m-d H:i:s");
    $last_update = date ("Y-m-d H:i:s", filemtime($filename));

    //Checking if the file has been changed since last time
    $new_file     = true;
    $file_id      = 0;
    $is_different = true;
    //$f = File::where('name', '=', $name)->where('path', '=', $filename)->get();
    $SEL = "SELECT * FROM files WHERE name='".$name."' AND path='".$filename."'";
    $f = DB::connection('mysql')->select($SEL);
    //Getting the amount of clicks

    if(count($f) > 0){
      $file_id   = $f[0]->id;
      $this->info('File '.$name.' last update => '.$last_update.' - db last update '.$f[0]->last_file_change);
      if($f[0]->last_file_change != $last_update){
        $this->info('Different');
        //Updating files
        $SQL = "UPDATE files SET updated_at = '$index_time',
        last_file_change = '$last_update',
        found = '1' WHERE id = '$file_id'";
        $update = DB::connection('mysql')->update($SQL);
        Log::info("Update Query -> ".$SQL." with result ".$update);
      }else{
        $is_different  = false;
        $new_file      = false;
        $SQL = "UPDATE files SET found = '1' WHERE id = '$file_id'";
        $update = DB::connection('mysql')->update($SQL);
        $this->info('Is the same');
      }
    }
    //If the date on the file has been changed, than re-index that file
    $extension = strtolower($ext_arr[$m]);
    if($is_different){
      switch ($extension) {
        case 'pdf':
          $valid = true;
          //This is to read the pdf file
          $reader = new \Asika\Pdf2text;
          $reader->setFilename($filename);
          $reader->decodePDF();
          $content = $reader->output();
          //Log::info('Text from file '.$fileInfo->getFilename().':'.$output);
          break;
        case 'doc':
          $valid = true;
          $content = $this->readDocFile($filename);
          break;
        case 'docx':
          $valid = true;
          $content = $this->readDocxFile($filename);
          break;
        case 'txt':
          $valid = true;
          $handle = fopen($filename, "rb");
          $content = fread($handle, filesize($filename));
          fclose($handle);
          break;
        case 'csv':
          $valid = true;
          $handle = fopen($filename, "rb");
          $content = fread($handle, filesize($filename));
          fclose($handle);
          break;
        default:
          # code...
          break;
      }
    }

    if($valid){

      $created_now = false;
      if($file_id<1){
        //Create object on mysql table
        $file                   = new File;
        $file->name             = str_replace("'","\'",$name);
        $file->path             = str_replace("'","\'",$filename);
        $file->extension        = $extension;
        $file->updated_at       = $index_time;
        $file->last_file_change = $last_update;
        $file->found            = 1;
        // save the folder to the database
        $file_db                = $file->save();
        $file_id                = $file->id;
        $created_now            = true;
      }

      $clicks = $this->checkClickCount($name, $filename, $file_id);
      //Creating the array for elasticsearch
      $body['name']       = $name;
      $body['parent']     = $parent;
      $body['full_path']  = $filename;
      $body['extension']  = $extension;
      $body['updated_at'] = $last_update;
      $body['index_stamp']= $index_time;
      $body['clicks']     = $clicks;
      $body['content']    = preg_replace('/[^A-Za-z0-9\. -]/', '', $content);

      $this->info('Mysql ID for file '.$body['name'].' -> '.$file_id);
      if($created_now){
        //Sending to Elasticsearch
        $this->createDocument($file_id, $body);
      }
      Log::info("Indexing file ".$file_id." - ".$name);
    }else{
      $clicks = $this->checkClickCount($name, $filename, $file_id);
      if($file_id>0 && $clicks>0){
        //Sending to Elasticsearch
        $this->updateClicks($file_id, $clicks);
      }
      //$arr['name']  = $name;
      //$arr['path']  = $filename;
      if($name!="Thumbs.db" && $name!=".DS_Store" && $is_different){
        $ct = count($this->invalid_files);
        $this->invalid_files[$ct]['name']  = $name;
        $this->invalid_files[$ct]['path'] 	= $filename;
      }
    }
  }

  /*
   * Check if file exists on clicks table and returns the click count
   */
  private function checkClickCount($name, $path, $file_id){
    //$c = Click::where('name', '=', $name)->where('path', '=', $path)->get();
    $SEL = "SELECT * FROM clicks WHERE name='".$name."' AND path='".$path."'";
    $c = DB::connection('mysql')->select($SEL);
    if(count($c) > 0){
      $clicks   = $c[0]->clicks;
      if($file_id!=$c[0]->file_id){
        $UP = "UPDATE clicks SET file_id='".$file_id."' WHERE name='".$name."' AND path='".$path."'";
        $update = DB::connection('mysql')->update($UP);
      }
    }else{
      //Create object on mysql table
      $click          = new Click;
      $click->name    = $name;
      $click->path    = $path;
      $click->file_id = $file_id;
      $click->clicks  = 0;
      // save the clicks to the database
      $file_db        = $click->save();
      $clicks         = 0;
    }
    return $clicks;
  }

  /*
   * Only to see if the cache file exists and set the global variable
   */
  private function checkCacheFolder(){
    if (file_exists($this->folder_path)) {
      $this->cacheCreated = true;
    }else{
      $this->cacheCreated = false;
    }
  }
  /*
   * Read doc files
   */
  private function readDocFile($filename) {
       if(file_exists($filename))
      {
          if(($fh = fopen($filename, 'r')) !== false )
          {
             $headers = fread($fh, 0xA00);

             // 1 = (ord(n)*1) ; Document has from 0 to 255 characters
             $n1 = ( ord($headers[0x21C]) - 1 );

             // 1 = ((ord(n)-8)*256) ; Document has from 256 to 63743 characters
             $n2 = ( ( ord($headers[0x21D]) - 8 ) * 256 );

             // 1 = ((ord(n)*256)*256) ; Document has from 63744 to 16775423 characters
             $n3 = ( ( ord($headers[0x21E]) * 256 ) * 256 );

             // 1 = (((ord(n)*256)*256)*256) ; Document has from 16775424 to 4294965504 characters
             $n4 = ( ( ( ord($headers[0x21F]) * 256 ) * 256 ) * 256 );

             // Total length of text in the document
             $textLength = ($n1 + $n2 + $n3 + $n4);

             $extracted_plaintext = fread($fh, $textLength);

             // simple print character stream without new lines
             //echo $extracted_plaintext;

             // if you want to see your paragraphs in a new line, do this
             return nl2br($extracted_plaintext);
             // need more spacing after each paragraph use another nl2br
          }
      }
  }

  function readDocxFile($filename){

    $striped_content = '';
    $content = '';

    if(!$filename || !file_exists($filename)) return false;

    $zip = zip_open($filename);

    if (!$zip || is_numeric($zip)) return false;

    while ($zip_entry = zip_read($zip)) {

        if (zip_entry_open($zip, $zip_entry) == FALSE) continue;

        if (zip_entry_name($zip_entry) != "word/document.xml") continue;

        $content .= zip_entry_read($zip_entry, zip_entry_filesize($zip_entry));

        zip_entry_close($zip_entry);
    }// end while

    zip_close($zip);

    //echo $content;
    //echo "<hr>";
    //file_put_contents('1.xml', $content);

    $content = str_replace('</w:r></w:p></w:tc><w:tc>', " ", $content);
    $content = str_replace('</w:r></w:p>', "\r\n", $content);
    $striped_content = strip_tags($content);

    return $striped_content;
}
/*
*How to use this function
 *
$filename = "filepath";// or /var/www/html/file.docx

$content = read_file_docx($filename);
if($content !== false) {

    echo nl2br($content);
}
else {
    echo 'Couldn\'t the file. Please check that file.';
}*/
  /*
   * Remove all the records on mysql
   */
  private function clearMysqlTables(){
    //Only clearing folders table to make sure its always synced
    \App\Folder::truncate();
    \App\File::truncate();
    \App\NotificationLog::truncate();
  }
  /*
   * Function to check if this file is good for us to read(in a vlid format)
   */
  private function testElasticSearchConnection(){

    $value['name'] 			= "Testing";
    $value['parent'] 		= '/var/www/es_docs';
    $value['full_path']	= '/var/www/es_docs/newfolder';
    $value['children']	= true;

    $params = [
        'index' => 'docsearch',
        'type' => 'folders',
        'body' => $value
    ];
    $hosts = [$_ENV['ES_HOST']];// IP + Port

    $client = \Elasticsearch\ClientBuilder::create()           // Instantiate a new ClientBuilder
                        ->setHosts($hosts)      // Set the hosts
                        ->build();
    $results = $client->create($params);

    $this->info('#################Connection with ES#################');

    $this->info('response is '.$results);
    $this->info('##################################');
  }

  /*
  * Function to delete index and re-create the maps
  */
  private function createIndex(){
    $hosts = [$_ENV['ES_HOST']];// IP + Port
    $client = \Elasticsearch\ClientBuilder::create()           // Instantiate a new ClientBuilder
    ->setHosts($hosts)      // Set the hosts
    ->build();

    //Checking if index exists
    $request = Requests::get('http://'.$_ENV['ES_HOST'].'/docsearch', array('Accept' => 'application/json'));
    //If index exists(!=404) then delete it and try to create again
    if($request->status_code!=404){
      $this->info('################# Index Already Exists - Deleting now #################');
      //Deleting docsearch index
      $deleteParams = ['index' => 'docsearch'];
      $response = $client->indices()->delete($deleteParams);
    }
    //Creating map
    $params = [
      'index' => 'docsearch',
      'body' => [
        'mappings' => [
          'folders' => [
            'properties' => [
              'full_path' => [
                'type' => 'string',
                'index' => 'not_analyzed'
              ],
              'parent' => [
                'type' => 'string',
                'index' => 'not_analyzed'
              ]
            ]
          ]
        ]
      ]
    ];

    $response = $client->indices()->create($params);
    $this->info('#################Creating Index with ES#################');
    //$this->info('response is ',$response);
    Log::info("Creating index ->>> ", $response);
    $this->info('##################################');
  }

  /*
  * Function to create the document index
  */
  private function createDocument($file_id, $body){
    $params = [
        'index' => 'docsearch',
        'type' => 'files',
        'id'  => $file_id,
        'body' => $body
    ];

    $hosts = [$_ENV['ES_HOST']];// IP + Port
    // Instantiate a new ClientBuilder
    $client = \Elasticsearch\ClientBuilder::create()
      ->setHosts($hosts)      // Set the hosts
      ->build();
    $client->index($params);
  }

  /*
  * Function to update the document clicks
  */
  private function updateClicks($file_id, $clicks){
    $params = [
        'index' => 'docsearch',
        'type' => 'files',
        'id'  => $file_id,
        'body' => [
          'doc' => [
            'clicks' => $clicks
          ]
        ]
    ];

    Log::info("PARAMS",$params);
    $hosts = [$_ENV['ES_HOST']];// IP + Port
    // Instantiate a new ClientBuilder
    $client = \Elasticsearch\ClientBuilder::create()
      ->setHosts($hosts)      // Set the hosts
      ->build();
    $client->update($params);
  }

  private function dbTests(){
    //$this->info('Testing Log');
    Log::info("Logging something!");
    $f = Folder::where('name', '=', 'Android')->where('full_path', '=', '/var/www/es_docs/Guides/Development/Android')->get();
    foreach ($f as $folder) {
      Log::info("Folder name ".$folder['name']." path ".$folder['full_path']." id= ".$folder['id']);
    }
    //$this->info('Done');
  }

  private function removeMissingFiles(){
    $hosts = [$_ENV['ES_HOST']];// IP + Port
    //Deleting docsearch index
    $client = \Elasticsearch\ClientBuilder::create()           // Instantiate a new ClientBuilder
    ->setHosts($hosts)      // Set the hosts
    ->build();

    //Removing folders from elastic search
    $folder = Folder::where('found', '=', 0)->get();
    foreach ($folder as $fo) {
      $params = [
        'index' => 'docsearch',
        'type' => 'folders',
        'id' => $fo['id']
      ];
      $response = $client->delete($params);
    }

    $file = File::where('found', '=', 0)->get();
    foreach ($file as $fi) {
      $params = [
        'index' => 'docsearch',
        'type' => 'files',
        'id' => $fi['id']
      ];
      $response = $client->delete($params);
    }

    $SQL = "DELETE FROM files WHERE found = '0'";
    DB::connection('mysql')->update($SQL);

    $SQL = "DELETE FROM folders WHERE found = '0'";
    DB::connection('mysql')->update($SQL);
  }

  private function sendSlackNotification(){
    $today = date('Y-m-d');
    $today_stamp = date('Y-m-d H:i:s');

    if(count($this->invalid_files)>0){
      Log::info("List of invalid files",$this->invalid_files);
      $SEL = "SELECT * FROM notification_logs WHERE last_log LIKE '$today%' LIMIT 1";
      $select = DB::connection('mysql')->select($SEL);
      if(count($select)>0){
        Log::info("Checking notification_logs table with result ",$select);
        Log::info("inside if ".count($select));
      }else{
        $INS = "INSERT INTO notification_logs SET last_log = '$today_stamp'";
        $insert = DB::connection('mysql')->update($INS);
        //Log::info("Insert with result ",$insert);
        //$this->invalid_files = array("\n", $array);
        //$failed_files = implode("\n", $this->invalid_files);
        $failed_files = "";
        foreach ($this->invalid_files as $key => $value) {
          $failed_files .= "Name: ".$this->invalid_files[$key]['name']."\nPath: ".$this->invalid_files[$key]['path']."\n-------------------------\n";
        }

        $data = array(
          'channel' => "#app-notifications",
          'username' => "Passport Document Indexer",
          'text' => "Document Indexer failed to index the following files: \n ".$failed_files,
          'icon_emoji' => ":turtle:"
        );
        $response = Requests::post($_ENV['slack_post_url'], array(), json_encode($data));
      }
    }else{
      Log::info("No Invalid Files");
      $this->info("No Invalid Files");
    }
  }
}
