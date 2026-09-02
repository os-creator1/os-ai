<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateAiBrainSettingsRequest;
use DB;

class AiSettingsController extends Controller {

 public function index() {
  $this->authorize('manage ai_settings');

  $settings = DB::table('ai_settings')->first();
  return view('admin.ai-settings',compact('settings'));
 }

 public function save(UpdateAiBrainSettingsRequest $request) {
  $this->authorize('manage ai_settings');

  DB::table('ai_settings')->update([
   'system_prompt'=>$request->system_prompt,
   'model'=>$request->model
  ]);

  return back();
 }

}
