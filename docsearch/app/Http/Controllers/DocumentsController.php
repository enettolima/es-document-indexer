<?php
namespace App\Http\Controllers;

use App\Click;
use App\File;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Log;
use DB;

class DocumentsController extends Controller
{
  /**
  * Update counter
  */
  public function putUpdateCounter(Request $request)
  {
    //$data             = Input::all();
    //$request = new Illuminate\Http\Request();
    //$input = $request->all();
    //$input = $request->json()->all();
    //Log::info("Data Received: ID->".$request->input('id'));
    //Log::info("Data Received",$request);
    Log::info("Filename received".$request->input('filename'));
    //$c = Click::where('name', '=', $request->input('filename'))->where('path', '=', $request->input('path'))->get();
    //$affected = DB::update('update clicks set clicks = 0');

    //$SEL = "SELECT * FROM clicks WHERE name='".$request->input('filename')."' AND path='".$request->input('path')."'";
    $SEL = "SELECT * FROM clicks WHERE file_id='".$request->input('id')."'";
    $c = DB::connection('mysql')->select($SEL);
    if(count($c) > 0){
      $new_clicks = $c[0]->clicks+1;
      $affected = DB::update('update clicks set clicks="'.$new_clicks.'" where file_id="'.$request->input('id').'"');
    }else{
      //Create object on mysql table
      $click          = new Click;
      $click->name    = $request->input('filename');
      $click->path    = $request->input('path');
      $click->clicks  = 1;
      // save the clicks to the database
      $file_db        = $click->save();
    }
    return response()->json(['success' => true]);
  }
}
