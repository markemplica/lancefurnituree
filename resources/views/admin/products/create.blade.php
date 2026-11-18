@extends('layouts.app')
@section('title','Add furniture')
@section('body_class', 'furniture-editor-page furniture-record-page')
@section('content')<nav class="furniture-editor-breadcrumb" aria-label="Breadcrumb"><a href="{{ route('admin.products.index') }}">Catalog</a><span aria-hidden="true">/</span><span aria-current="page">Add furniture</span></nav>
<header class="page-heading"><div><h1>Add furniture</h1><p>Add a new piece to the Lance Furniture Shop catalog.</p></div><a class="furniture-editor-back" href="{{ route('admin.products.index') }}">Back to catalog</a></header>
<form class="panel furniture-editor furniture-record" method="POST" action="{{ route('admin.products.store') }}" enctype="multipart/form-data">
    @csrf
    @include('admin.products._form', ['recordLayout' => true])
</form>
@endsection
