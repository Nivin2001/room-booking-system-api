@props(['space'])
@php($name = is_string($space?->title) ? trim($space->title) : '')
{{ $name !== '' ? $name : 'مساحة بدون اسم #'.($space?->id ?? '—') }}
