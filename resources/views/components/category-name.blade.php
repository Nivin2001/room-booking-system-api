@props(['category'])
@php($name = is_string($category?->name) ? trim($category->name) : '')
{{ $name !== '' ? $name : 'تصنيف غير مسمى #'.($category?->id ?? '—') }}
