@section('title', 'View page')
@extends('adminlte::page')
@section('content')

    <section class="content container-fluid">
        <h2>View page: {{ $model->title }}</h2>

        <div class="row mb-3">
            <div class="col-12">
                <form action="{{ route('admin_page_delete') }}" method="post">
                    <a class="btn btn-success" href="{{ route('admin_page_edit', ['id' => $model->id]) }}" title="Edit page">Edit page</a>
                    <input type="submit" class="btn btn-danger" value="Delete" title="Delete" onclick="return confirm('Are you sure you want to delete?')">
                    <input type="hidden" value="{{ $model->id }}" name="delete[]">
                    <input type="hidden" value="{!! csrf_token() !!}" name="_token">
                </form>
            </div>
        </div>

        <div class="row mb-3">
            <div class="col-12">
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
                            @php
                                $detailData = [
                                    'model' => $model,
                                    'htmlAttributes' => [
                                        'class' => 'table table-striped table-bordered table-sm'
                                    ],
                                    'rowFields' => [
                                        [
                                            'label' => 'Title',
                                            'value' => function ($model) use ($langModel) {
                                                return $model->{'title_'.$langModel->short_name};
                                            },
                                        ],
                                        [
                                            'label' => 'Description',
                                            'value' => function ($model) use ($langModel) {
                                                return $model->{'description_'.$langModel->short_name};
                                            },
                                        ],
                                        [
                                            'label' => 'Content',
                                            'value' => function ($model) use ($langModel) {
                                                return $model->{'content_'.$langModel->short_name};
                                            },
                                        ],
                                        [
                                            'label' => 'Meta keys',
                                            'value' => function ($model) use ($langModel) {
                                                return $model->{'meta_keys_'.$langModel->short_name};
                                            },
                                        ],
                                        [
                                            'label' => 'Meta description',
                                            'value' => function ($model) use ($langModel) {
                                                return $model->{'meta_description_'.$langModel->short_name};
                                            },
                                        ],
                                    ],
                                ];
                            @endphp

                            @detailView($detailData)
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </section>

@endsection
