<ul class="nav nav-tabs">
    @foreach($languageList as $langModel)
        <li class="nav-item">
            <a class="nav-link @if($langModel->default == 1)active @endif" data-toggle="tab" href="#lang_{{ $langModel->short_name }}">
                {{ $langModel->name }}
            </a>
        </li>
    @endforeach
</ul>
<div class="tab-content my-2">
    @foreach($languageList as $langModel)
        <div class="tab-pane fade @if($langModel->default == 1)show active @endif" id="lang_{{ $langModel->short_name }}">
            <div class="row">
                <div class="col-12 col-sm-10 col-md-8 col-lg-6 col-xl-4">
                    <div class="form-group">
                        <label for="page_title_{{ $langModel->short_name }}">{!! __('messages.title') !!}</label>
                        <input id="page_title_{{ $langModel->short_name }}"
                               type="text"
                               class="form-control @if ($errors->has('title_'.$langModel->short_name)) is-invalid @endif"
                               name="title_{{ $langModel->short_name }}"
                               value="{{ old('title_'.$langModel->short_name, !empty($model) ? $model->{'title_'.$langModel->short_name} : null) }}">
                        @if ($errors->has('title_'.$langModel->short_name))
                            <div class="invalid-feedback">
                                <strong>{{ $errors->first('title_'.$langModel->short_name) }}</strong>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-12 col-sm-10 col-md-8 col-lg-6 col-xl-4">
                    <div class="form-group">
                        <label for="page_description_{{ $langModel->short_name }}">{!! __('messages.description') !!}</label>
                        <input id="page_description_{{ $langModel->short_name }}"
                               type="text"
                               class="form-control @if ($errors->has('description_'.$langModel->short_name)) is-invalid @endif"
                               name="description_{{ $langModel->short_name }}"
                               value="{{ old('description_'.$langModel->short_name, !empty($model) ? $model->{'description_'.$langModel->short_name} : null) }}">
                        @if ($errors->has('description_'.$langModel->short_name))
                            <div class="invalid-feedback">
                                <strong>{{ $errors->first('description_'.$langModel->short_name) }}</strong>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-12 col-sm-10 col-md-8 col-lg-6 col-xl-4">
                    <div class="form-group">
                        <label for="page_content_{{ $langModel->short_name }}">{!! __('messages.content') !!}</label>
                        <textarea id="page_content_{{ $langModel->short_name }}" rows="5"
                                  class="form-control @if ($errors->has('content_'.$langModel->short_name)) is-invalid @endif"
                                  name="content_{{ $langModel->short_name }}">{{ old('content_'.$langModel->short_name, !empty($model) ? $model->{'content_'.$langModel->short_name} : null) }}</textarea>
                        @if ($errors->has('content_'.$langModel->short_name))
                            <div class="invalid-feedback">
                                <strong>{{ $errors->first('content_'.$langModel->short_name) }}</strong>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-12 col-sm-10 col-md-8 col-lg-6 col-xl-4">
                    <div class="form-group">
                        <label for="page_meta_keys_{{ $langModel->short_name }}">{!! __('messages.meta_keys') !!}</label>
                        <input id="page_meta_keys_{{ $langModel->short_name }}"
                               type="text"
                               class="form-control @if ($errors->has('meta_keys_'.$langModel->short_name)) is-invalid @endif"
                               name="meta_keys_{{ $langModel->short_name }}"
                               value="{{ old('meta_keys_'.$langModel->short_name, !empty($model) ? $model->{'meta_keys_'.$langModel->short_name} : null) }}">
                        @if ($errors->has('meta_keys_'.$langModel->short_name))
                            <div class="invalid-feedback">
                                <strong>{{ $errors->first('meta_keys_'.$langModel->short_name) }}</strong>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-12 col-sm-10 col-md-8 col-lg-6 col-xl-4">
                    <div class="form-group">
                        <label for="page_meta_description_{{ $langModel->short_name }}">{!! __('messages.meta_description') !!}</label>
                        <input id="page_meta_description_{{ $langModel->short_name }}"
                               type="text"
                               class="form-control @if ($errors->has('meta_description_'.$langModel->short_name)) is-invalid @endif"
                               name="meta_description_{{ $langModel->short_name }}"
                               value="{{ old('meta_description_'.$langModel->short_name, !empty($model) ? $model->{'meta_description_'.$langModel->short_name} : null) }}">
                        @if ($errors->has('meta_description_'.$langModel->short_name))
                            <div class="invalid-feedback">
                                <strong>{{ $errors->first('meta_description_'.$langModel->short_name) }}</strong>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endforeach
</div>

<div class="row mb-3">
    <div class="col-12 col-sm-10 col-md-8 col-lg-6 col-xl-4">
        <div class="custom-control custom-radio custom-control-inline">
            <input type="radio" id="page_active_input" name="active" value="1" class="custom-control-input" @if(old('active', !empty($model) ? $model->active : 1) == 1) checked @endif >
            <label class="custom-control-label" for="page_active_input">{!! __('messages.active') !!}</label>
        </div>
        <div class="custom-control custom-radio custom-control-inline">
            <input type="radio" id="page_inactive_input" name="active" value="0" class="custom-control-input" @if(old('active', !empty($model) ? $model->active : 1) == 0) checked @endif >
            <label class="custom-control-label" for="page_inactive_input">{!! __('messages.inactive') !!}</label>
        </div>
    </div>
</div>
