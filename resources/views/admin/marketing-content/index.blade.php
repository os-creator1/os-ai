@extends('layouts/contentLayoutMaster')

@section('title', 'Marketing Content')

{{--
    Public Marketing Homepage contract. The one small, safe admin surface
    for the public homepage's owner-editable copy: hero headline/
    subheadline, FAQ entries, and video testimonial slots. Every field is a
    plain typed column — no rich-HTML editor, no page builder.
--}}

@section('content')
    <section id="admin-marketing-content-index">
        @if (session('success'))
            <x-alert variant="success" class="mb-3">{{ session('success') }}</x-alert>
        @endif
        @if ($errors->any())
            <x-alert variant="danger" class="mb-3">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </x-alert>
        @endif

        <div class="card mb-4">
            <div class="card-header"><h4 class="card-title">Homepage headline</h4></div>
            <div class="card-body">
                <form class="form form-vertical" action="{{ route('admin.marketing-content.hero.update') }}" method="post">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label text-label">Headline</label>
                        <input type="text" name="hero_headline" class="form-control" maxlength="191"
                               value="{{ old('hero_headline', $settings->hero_headline) }}"
                               placeholder="Run your business. We'll handle the front desk.">
                        @error('hero_headline')<div class="invalid-feedback d-block text-caption">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-label">Supporting copy</label>
                        <textarea name="hero_subheadline" class="form-control" rows="3" maxlength="2000"
                                  placeholder="Publish your site, capture every inquiry, and get paid — without switching tools.">{{ old('hero_subheadline', $settings->hero_subheadline) }}</textarea>
                        @error('hero_subheadline')<div class="invalid-feedback d-block text-caption">{{ $message }}</div>@enderror
                        <p class="form-text text-caption">Shown at the top of the public homepage. Leave blank to use the default copy.</p>
                    </div>
                    <button type="submit" class="btn btn-primary">Save headline</button>
                </form>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header"><h4 class="card-title">FAQ</h4></div>
            <div class="card-body">
                @forelse ($faqs as $faq)
                    <form class="form form-vertical border rounded p-3 mb-3" action="{{ route('admin.marketing-content.faqs.update', $faq) }}" method="post">
                        @csrf
                        @method('PUT')
                        <div class="mb-2">
                            <label class="form-label text-label">Question</label>
                            <input type="text" name="question" class="form-control" maxlength="255" value="{{ old('question', $faq->question) }}" required>
                        </div>
                        <div class="mb-2">
                            <label class="form-label text-label">Answer</label>
                            <textarea name="answer" class="form-control" rows="2" maxlength="5000" required>{{ old('answer', $faq->answer) }}</textarea>
                        </div>
                        <div class="d-flex align-items-center gap-3">
                            <div class="mb-0" style="width: 100px;">
                                <label class="form-label text-label">Order</label>
                                <input type="number" name="position" class="form-control" min="0" value="{{ old('position', $faq->position) }}">
                            </div>
                            <div class="form-check mt-4">
                                <input type="checkbox" name="is_visible" value="1" class="form-check-input" id="faq-visible-{{ $faq->id }}" @checked(old('is_visible', $faq->is_visible))>
                                <label class="form-check-label" for="faq-visible-{{ $faq->id }}">Visible on homepage</label>
                            </div>
                            <div class="ms-auto d-flex gap-2 mt-4">
                                <button type="submit" class="btn btn-sm btn-outline-primary">Save</button>
                            </div>
                        </div>
                    </form>
                    <form action="{{ route('admin.marketing-content.faqs.destroy', $faq) }}" method="post" class="mb-4 text-end" style="margin-top:-1rem;" onsubmit="return confirm('Remove this FAQ entry?');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-sm btn-link text-danger">Remove</button>
                    </form>
                @empty
                    <p class="text-muted">No FAQ entries yet. Add the first one below.</p>
                @endforelse

                <hr>

                <form class="form form-vertical" action="{{ route('admin.marketing-content.faqs.store') }}" method="post">
                    @csrf
                    <h6>Add a FAQ entry</h6>
                    <div class="mb-2">
                        <label class="form-label text-label">Question</label>
                        <input type="text" name="question" class="form-control" maxlength="255" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label text-label">Answer</label>
                        <textarea name="answer" class="form-control" rows="2" maxlength="5000" required></textarea>
                    </div>
                    <div class="form-check mb-2">
                        <input type="checkbox" name="is_visible" value="1" class="form-check-input" id="faq-new-visible" checked>
                        <label class="form-check-label" for="faq-new-visible">Visible on homepage</label>
                    </div>
                    <button type="submit" class="btn btn-primary">Add FAQ</button>
                </form>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header">
                <h4 class="card-title">Video testimonials</h4>
            </div>
            <div class="card-body">
                <x-alert variant="accent" class="mb-3">
                    Testimonials are labelled as feedback from an earlier business, never as
                    a review of this software. A testimonial only appears on the homepage
                    once it has a poster image and is marked visible.
                </x-alert>

                @forelse ($testimonials as $testimonial)
                    <form class="form form-vertical border rounded p-3 mb-3" action="{{ route('admin.marketing-content.testimonials.update', $testimonial) }}" method="post" enctype="multipart/form-data">
                        @csrf
                        <div class="row">
                            <div class="col-md-3 text-center">
                                @if ($testimonial->poster_image_path)
                                    <img src="{{ asset($testimonial->poster_image_path) }}" alt="" class="img-fluid rounded mb-2" style="max-height:120px;">
                                @endif
                                <input type="file" name="poster_image" class="form-control form-control-sm" accept="image/png,image/jpeg,image/webp">
                                @error('poster_image')<div class="invalid-feedback d-block text-caption">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-9">
                                <div class="mb-2">
                                    <label class="form-label text-label">Name</label>
                                    <input type="text" name="name" class="form-control" maxlength="191" value="{{ old('name', $testimonial->name) }}" required>
                                </div>
                                <div class="mb-2">
                                    <label class="form-label text-label">Context label</label>
                                    <input type="text" name="business_context_label" class="form-control" maxlength="255" value="{{ old('business_context_label', $testimonial->business_context_label) }}" required>
                                    <p class="form-text text-caption">Must make clear this is feedback from an earlier business, not a review of this software.</p>
                                </div>
                                <div class="mb-2">
                                    <label class="form-label text-label">Video URL</label>
                                    <input type="url" name="video_url" class="form-control" maxlength="2048" value="{{ old('video_url', $testimonial->video_url) }}" placeholder="https://...">
                                </div>
                                <div class="mb-2">
                                    <label class="form-label text-label">Transcript / caption</label>
                                    <textarea name="transcript_text" class="form-control" rows="2" maxlength="10000">{{ old('transcript_text', $testimonial->transcript_text) }}</textarea>
                                </div>
                                <div class="d-flex align-items-center gap-3">
                                    <div class="mb-0" style="width: 100px;">
                                        <label class="form-label text-label">Order</label>
                                        <input type="number" name="position" class="form-control" min="0" value="{{ old('position', $testimonial->position) }}">
                                    </div>
                                    <div class="form-check mt-4">
                                        <input type="checkbox" name="is_visible" value="1" class="form-check-input" id="testimonial-visible-{{ $testimonial->id }}" @checked(old('is_visible', $testimonial->is_visible))>
                                        <label class="form-check-label" for="testimonial-visible-{{ $testimonial->id }}">Visible on homepage</label>
                                    </div>
                                    <button type="submit" class="btn btn-sm btn-outline-primary ms-auto mt-4">Save</button>
                                </div>
                            </div>
                        </div>
                    </form>
                    <form action="{{ route('admin.marketing-content.testimonials.destroy', $testimonial) }}" method="post" class="mb-4 text-end" style="margin-top:-1rem;" onsubmit="return confirm('Remove this testimonial?');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-sm btn-link text-danger">Remove</button>
                    </form>
                @empty
                    <p class="text-muted">No testimonials yet. Add one below once you have a real name, poster image, and approved wording.</p>
                @endforelse

                <hr>

                <form class="form form-vertical" action="{{ route('admin.marketing-content.testimonials.store') }}" method="post" enctype="multipart/form-data">
                    @csrf
                    <h6>Add a testimonial</h6>
                    <div class="row">
                        <div class="col-md-3">
                            <label class="form-label text-label">Poster image</label>
                            <input type="file" name="poster_image" class="form-control" accept="image/png,image/jpeg,image/webp" required>
                        </div>
                        <div class="col-md-9">
                            <div class="mb-2">
                                <label class="form-label text-label">Name</label>
                                <input type="text" name="name" class="form-control" maxlength="191" required>
                            </div>
                            <div class="mb-2">
                                <label class="form-label text-label">Context label</label>
                                <input type="text" name="business_context_label" class="form-control" maxlength="255"
                                       value="Feedback from an earlier business (not a review of this software)" required>
                            </div>
                            <div class="mb-2">
                                <label class="form-label text-label">Video URL</label>
                                <input type="url" name="video_url" class="form-control" maxlength="2048" placeholder="https://...">
                            </div>
                            <div class="mb-2">
                                <label class="form-label text-label">Transcript / caption</label>
                                <textarea name="transcript_text" class="form-control" rows="2" maxlength="10000"></textarea>
                            </div>
                            <div class="form-check mb-2">
                                <input type="checkbox" name="is_visible" value="1" class="form-check-input" id="testimonial-new-visible">
                                <label class="form-check-label" for="testimonial-new-visible">Visible on homepage</label>
                            </div>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary">Add testimonial</button>
                </form>
            </div>
        </div>
    </section>
@endsection
