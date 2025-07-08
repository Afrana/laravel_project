@section('title', 'Page list')
@extends('adminlte::page')
@section('content')

    @php
    $gridData = [
        'dataProvider' => $dataProvider,
        'paginatorOptions' => [
            'pageName' => 'p',
        ],
        'rowsPerPage' => 5,
        'title' => 'Таблица',
        //'useFilters' => false,
        'strictFilters' => true,
        'rowsFormAction' => route('admin_page_delete'),
        'columnFields' => [
            [
                'attribute' => 'id',
                'filter' => false,
                'htmlAttributes' => [
                    'width' => '5%',
                ],
            ],
            [
                'label' => 'Заголовок',
                'value' => function ($row) {
                    /** @var App\Models\Page $row */
                    return $row->defaultTranslate('title');
                },
                'filter' => false,
            ],
            [
                'label' => 'Активно',
                'value' => function ($row) {
                    return '<span class="icon fas '.($row->active == 1 ? 'fa-check' : 'fa-times').'"></span>';
                },
                'format' => 'html',
                'sort' => 'active',
                'filter' => [
                    'class' => Itstructure\GridView\Filters\DropdownFilter::class,
                    'name' => 'active',
                    'data' => [
                        0 => 'No active',
                        1 => 'Active',
                    ]
                ],
            ],
            [
                'label' => 'Иконка',
                'value' => function ($row) {
                    return $row->icon;
                },
                'sort' => 'icon',
                'filter' => false,
                'format' => [
                    'class' => Itstructure\GridView\Formatters\ImageFormatter::class,
                    'htmlAttributes' => [
                        'width' => '100'
                    ]
                ],
            ],
            [
                'label' => 'Создано',
                'attribute' => 'created_at'
            ],
            [
                'class' => Itstructure\GridView\Columns\ActionColumn::class,
                'actionTypes' => [
                    'view' => function ($data) {
                        return '/admin/pages/view/' . $data->id;
                    },
                    'edit' => function ($data) {
                        return '/admin/pages/edit/' . $data->id;
                    },
                    /*[
                        'class' => Itstructure\GridView\Actions\Delete::class,
                        'url' => function ($data) {
                            return '/admin/pages/delete/' . $data->id;
                        },
                        'htmlAttributes' => [
                            'target' => '_blank',
                            'onclick' => 'return window.confirm("Are you sure you want to delete?");'
                        ]
                    ],*/
                ],
                'htmlAttributes' => [
                    'width' => '175'
                ]
            ],
            [
                'class' => Itstructure\GridView\Columns\CheckboxColumn::class,
                'field' => 'delete',
                'attribute' => 'id'
            ],
        ],
    ];
    @endphp

    @gridView($gridData)

@stop