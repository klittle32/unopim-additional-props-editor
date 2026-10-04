{{-- Render only at unopim.admin.catalog.product.edit.after, outside the native form. --}}
<link rel="stylesheet" href="{{ asset('vendor/additional-props-editor/editor.css') }}">
<additional-props-editor
    v-pre
    data-csrf="{{ csrf_token() }}"
    data-endpoint="{{ route('additional-props.show', ['id' => $product->id]) }}"
>
    <p>Loading Additional Product Data. JavaScript is required to use this separate editor.</p>
</additional-props-editor>
@pushOnce('scripts', 'additional-props-editor')
    <script type="module" src="{{ asset('vendor/additional-props-editor/editor.js') }}"></script>
@endPushOnce
