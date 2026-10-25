Hi {{ $inquiry->name ?: 'there' }},

{{ $replyBody }}

--
{{ $siteName }}
{{ config('site.contact.email') }}
