<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use DB;

class AiSettingsController extends Controller {

 public function index() {
  $settings = DB::table('ai_settings')->first();
  return view('admin.ai-settings',compact('settings'));
 }

 public function save(Request $request) {
  DB::table('ai_settings')->update([
   'system_prompt'=>$request->system_prompt,
   'model'=>$request->model
  ]);

  return back();
 }

}
