<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

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
      //$this->testElasticSearchConnection();
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
  		$this->compareCacheFolders($this->folders);
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

  		if(!$isRoot){
  			$folderName = $this->getFolderName($dir);
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
  					$ext = $this->checkFileExtension($fileInfo->getFilename());
  					if($ext['valid']){
  						$meta = get_meta_tags($dir.'/'.$fileInfo->getFilename(),true);
  						//echo $result;

              //This is to read the pdf file
  						//$reader = new \Asika\Pdf2text;
  						//$reader->setFilename($dir.'/'.$fileInfo->getFilename());
  						//$reader->decodePDF();
  						//$output = $reader->output();
  						//Log::info('Text from file '.$fileInfo->getFilename().':'.$output);
  					}
  					//$meta = get_meta_tags($dir.$fileInfo->getFilename(),true);
  					//$this->info('Metadata from file '.$fileInfo->getFilename().': ',$meta);
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
  	private function compareCacheFolders($folder_info){
      if($this->cacheCreated){
          $json = json_decode(file_get_contents($this->folder_path),true);
      }

  		//$toCreate = array_diff($this->folders,$json);

  		foreach ($folder_info as $key => $value) {
  			//Log::info('Key is '.$key, $value);
  			$params = [
            'index' => 'docsearch',
            'type' => 'folders',
            'body' => $value
        ];
  			$hosts = [$_ENV['ES_HOST']];// IP + Port

        $client = \Elasticsearch\ClientBuilder::create()           // Instantiate a new ClientBuilder
                            ->setHosts($hosts)      // Set the hosts
                            ->build();
  			$results = $client->create($params); //$client->search($params);
  			//Log::info('ES Response', array('results'=> $results));
  			//Log::info('############');
  		}
  	}

  	/*
  	 * Function to check if this file is good for us to read(in a vlid format)
  	 */
  	private function checkFileExtension($filename){
  		$ext_arr = explode(".",$filename);
  		$m = count($ext_arr)-1;
  		$valid = false;

  		$extension = $ext_arr[$m];
  		switch ($extension) {
  			case 'pdf':
  				$valid = true;
  				break;
  			case 'doc':
  				$valid = true;
  				break;
  			case 'txt':
  				$valid = true;
  				break;
  			case 'csv':
  				$valid = true;
  				break;
  			default:
  				# code...
  				break;
  		}

  		$response['valid'] = $valid;
  		$response['filename'] = $filename;
  		$response['raw_name'] = str_replace('.'.$extension,'',$filename);
  		$response['extension'] = $extension;
  		//Log::info('Extension for '.$filename, $response);
      //Log::info('User failed to login.');
      //Log::info('This is some useful information.');
      return $response;
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
