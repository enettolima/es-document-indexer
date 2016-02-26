<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Folder;
use App\File;

class CronIndexDocuments extends Command
{
  /**
   * The name and signature of the console command.
   *
   * @var string
   */
  protected $signature = 'cron:index-documents';
  private $folders;
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

    //Call the function to start the folder creation on ES
    $this->createFolderIndex();
    //Call the function to start the file indexing on ES
  }
  /*
   * Create index with all the folders on ES
   */
  private function createFolderIndex(){
    //Just testing the command
    $this->info('Starting to search the folder: '.$_ENV['EBT_FILE_STORAGE']);
    $this->folders = array();
    $this->count = 0;

    //Checking if cache file exist, if not just create all the index on ES
    $this->checkCacheFolder();
    //Loop through the directories to get files ad folders
    $this->listFolderFiles($_ENV['EBT_FILE_STORAGE']);
    //Check if any folder is missing from cache and try to create on ES
    $this->compareCacheFolders();
    //Write new cache file
    $fp = fopen($this->folder_path, 'w');
    fwrite($fp, json_encode($this->folders));
    fclose($fp);
    //Log::info('Docs folder', $this->folders);
  }
  /*
   * This function will loop through the directories and get all the folders and files
   */
  private function listFolderFiles($dir){
    //$this->info('Folder: '.$dir);

    $this->info('#################');
    $this->info('Path: '.$dir);

    $children = false;
    $isRoot = false;
    if($dir==$_ENV['EBT_FILE_STORAGE']){
      $isRoot = true;
    }

    $folderName = $this->getFolderName($dir);
    if(!$isRoot){
      $this->info('Directory Name: '.$folderName['child']);
      $this->info('Parent Name: '.$folderName['parent']);
    }

    //$this->info('Folder: '.$dir);

    foreach (new \DirectoryIterator($dir) as $fileInfo) {
      if (!$fileInfo->isDot()) {
        if ($fileInfo->isDir()) {
          $this->listFolderFiles($fileInfo->getPathname());
        }else{
          $this->info('File: '.$fileInfo->getFilename());
          $this->checkFileExtension($dir.'/'.$fileInfo->getFilename(), $fileInfo->getFilename(), $folderName['parent']);
        }
        $children = true;
      }else{
        $children = false;
      }
    }
    $this->info('Children: '.$children);

    if(!$isRoot){
      $this->folders[$this->count]['name'] 			= $folderName['child'];
      $this->folders[$this->count]['parent'] 		= $folderName['parent'];
      $this->folders[$this->count]['full_path']	= $dir;
      $this->folders[$this->count]['children']	= $children;
      $this->count++;
    }
    $this->info('#################');
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
        $folder->name         = $value['name'];
        $folder->parent       = $value['parent'];
        $folder->full_path    = $value['full_path'];
        $folder->children     = $value['children'];

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
    $f = \App\Folder::where('name', $data['name'])->where('full_path', $data['full_path'])->first();
    if($f === null){
      return false;
    }else{
      return true;
    }
  }
  /*
   * Function to check if this file is good for us to read(in a vlid format)
   */
  private function checkFileExtension($filename, $name, $parent){
    $ext_arr = explode(".",$filename);
    $m = count($ext_arr)-1;
    $valid = false;

    $extension = $ext_arr[$m];
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
        $content = $this->readDocFile($filename);
        $valid = true;
        break;
      case 'docx':
        $content = $this->readDocxFile($filename);
        $valid = true;
        break;
      case 'txt':
        $handle = fopen($filename, "rb");
        $content = fread($handle, filesize($filename));
        fclose($handle);
        $valid = true;
        break;
      case 'csv':
        $handle = fopen($filename, "rb");
        $content = fread($handle, filesize($filename));
        fclose($handle);
        $valid = true;
        break;
      default:
        # code...
        break;
    }

    if($valid){
      //Create object on mysql table
      $file               = new File;
      $file->name         = $name;
      $file->path         = $filename;
      $file->extension    = $extension;
      // save the folder to the database
      $file_db            = $file->save();
      $body['name']       = $name;
      $body['parent']     = $parent;
      $body['full_path']  = $filename;
      $body['extension']  = $extension;
      //$body['content']    = str_replace("\n","",$content);
      $body['content']    = preg_replace('/[^A-Za-z0-9\. -]/', '', $content);//preg_replace('/[^A-Za-z0-9\-]/', '', $content);

      $this->info('Mysql ID for file '.$body['name'].' -> '.$file->id);

      $params = [
          'index' => 'docsearch',
          'type' => 'files',
          'id'  => $file->id,
          'body' => $body
      ];

      $hosts = [$_ENV['ES_HOST']];// IP + Port
      // Instantiate a new ClientBuilder
      $client = \Elasticsearch\ClientBuilder::create()
        ->setHosts($hosts)      // Set the hosts
        ->build();
      $results = $client->index($params);
    }
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
    \App\Folder::truncate();
    \App\File::truncate();
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
}
