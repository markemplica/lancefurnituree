@extends('layouts.app')
@section('title','Edit '.$product->name)
@section('body_class', 'furniture-editor-page furniture-record-page')
@section('content')<nav class="furniture-editor-breadcrumb" aria-label="Breadcrumb"><a href="{{ route('admin.products.index') }}">Catalog</a><span aria-hidden="true">/</span><span aria-current="page">Edit furniture</span></nav>
<header class="page-heading"><div><h1>Edit {{ $product->name }}</h1><p>Changes affect future recommendations and customer searches.</p></div><a class="furniture-editor-back" href="{{ route('admin.products.index') }}">Back to catalog</a></header>
<form class="panel furniture-editor furniture-record" method="POST" action="{{ route('admin.products.update',$product) }}" enctype="multipart/form-data">
    @csrf
    @method('PUT')
    @include('admin.products._form', ['recordLayout' => true])
</form>
@endsection
