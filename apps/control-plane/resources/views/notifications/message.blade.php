{{--
    One template for every notification.

    `dir` follows the recipient's language rather than the platform's, so an
    Arabic customer's mail lays out right to left in clients that honour it.
    There is no provider detail, no identifier and no credential here: the
    template renders a title, a body and at most a link back into the portal,
    where the customer is authenticated.
--}}
<x-mail::message>
# {{ $title }}

<div @if($rtl) dir="rtl" @endif>
{{ $body }}
</div>

@if($url)
<x-mail::button :url="$url">
{{ __('notifications.action.open') }}
</x-mail::button>
@endif

{{ __('notifications.signature') }}
</x-mail::message>
