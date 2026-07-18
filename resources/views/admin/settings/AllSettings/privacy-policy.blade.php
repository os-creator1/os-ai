@extends('layouts/contentLayoutMaster')

@section('title',  __('locale.labels.privacy_policy'))

@section('content')
    <section class="snow-editor">
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title">{{ __('locale.labels.privacy_policy') }}</h4>
                    </div>
                    @if ($errors->any())
                        <div class="alert alert-danger">
                            <ul>
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                    <div class="card-content collapse show">
                        <div class="card-body">
                            <form class="form form-vertical"
                                  action="{{ route('admin.settings.privacy-policy') }}" method="post">
                                @csrf
                                <div class="row">

                                    <div class="col-12">
                                        <div class="mb-1">
                                            <label for="message"
                                                   class="form-label required">{{ __('locale.labels.privacy_policy') }}</label>
                                            @include('plugins.editor', ['content' => $privacyPolicyData])
                                            <textarea name="privacy_policy" style="display:none"
                                                      id="hiddenArea"></textarea>
                                        </div>
                                    </div>


                                    <div class="col-12 mt-2">
                                        <button type="submit" class="btn btn-primary mr-1 mb-1"><i
                                                    data-feather="save"></i> {{ __('locale.buttons.save') }}</button>
                                    </div>

                                </div>

                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
    <!-- Snow Editor end -->
@endsection

