@section('title', 'Edit page')
@extends('adminlte::page')
@section('content')

    <section class="content container-fluid">
        <div class="row">
            <div class="col-12">

                <h2>Edit page</h2>

                <form action="{{ route('admin_page_update', ['id' => $model->id]) }}" method="post">

                    @include('admin.page._fields')

                    <button class="btn btn-primary" type="submit">Submit</button>

                    <input type="hidden" value="{!! csrf_token() !!}" name="_token">

                </form>

            </div>
        </div>
    </section>

@stop