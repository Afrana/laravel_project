@section('title', 'Create page')
@extends('adminlte::page')
@section('content')

    <section class="content container-fluid">
        <div class="row">
            <div class="col-12">

                <h2>Create page</h2>

                <form action="{{ route('admin_page_store') }}" method="post">

                    @include('admin.page._fields')

                    <button class="btn btn-primary" type="submit">Create</button>

                    <input type="hidden" value="{!! csrf_token() !!}" name="_token">

                </form>

            </div>
        </div>
    </section>

@stop
