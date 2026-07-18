<form method="POST">
@csrf

<label>Model</label>
<input name="model" value="{{ $settings->model }}" class="form-control">

<label>System Prompt</label>

<textarea name="system_prompt" rows="12" class="form-control">{{ $settings->system_prompt }}</textarea>

<button type="submit">Save</button>
</form>
