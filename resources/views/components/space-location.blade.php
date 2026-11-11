@props(['space'])
@php($location = is_string($space?->location) ? trim($space->location) : '')
{{ $location !== '' ? $location : 'الموقع غير محدد' }}
